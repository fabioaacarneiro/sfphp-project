<?php

namespace SfphpProject\src\Async;

use SplObjectStorage;
use Throwable;

/**
 * Decides what runs next, and waits when nothing can.
 *
 * Tasks live in two places. **Ready** ones have work to do now and are run in
 * turn. **Waiting** ones have suspended on something that has not happened yet,
 * and the scheduler does not touch them again until it has: when the Future
 * they are waiting on settles, it moves them back to ready.
 *
 * That division is the whole correction. The previous scheduler kept one queue
 * and resumed everything in it on every pass, so a Task waiting on a network
 * response was resumed thousands of times a second to discover, each time, that
 * the response had not arrived — a spin loop wearing the shape of a scheduler.
 * Worse, it resumed Fibers that had suspended for a reason that had nothing to
 * do with it, which is how an awaited value came back as null.
 *
 * When nothing is ready, the scheduler does not spin: it asks the event loop to
 * wait. The process then sits in a single select() over every pending transfer
 * and the nearest timer, and wakes for whichever finishes first.
 */
final class Scheduler
{
    /** @var list<Task> */
    private array $ready = [];

    /** @var SplObjectStorage<Task, null> */
    private SplObjectStorage $waiting;

    private EventLoop $loop;

    private ?Task $current = null;

    private bool $running = false;

    /**
     * Create a scheduler.
     *
     * @param EventLoop|null $loop The loop to wait on, or null for a new one
     */
    public function __construct(?EventLoop $loop = null)
    {
        $this->loop = $loop ?? new EventLoop();
        $this->waiting = new SplObjectStorage();
    }

    /**
     * The event loop this scheduler waits on.
     *
     * @return EventLoop The loop
     */
    public function loop(): EventLoop
    {
        return $this->loop;
    }

    /**
     * Queue a Task to run.
     *
     * @param Task $task The task
     * @return void
     */
    public function schedule(Task $task): void
    {
        if ($task->isSettled() || $this->waiting->contains($task)) {
            return;
        }

        foreach ($this->ready as $queued) {
            if ($queued === $task) {
                return;
            }
        }

        $this->ready[] = $task;
    }

    /**
     * Park a Task until a Future settles.
     *
     * @param Task $task The task
     * @param Future $future What it is waiting for
     * @return void
     */
    public function park(Task $task, Future $future): void
    {
        $this->waiting->attach($task);

        $future->onResolve(function () use ($task): void {
            if (!$this->waiting->contains($task)) {
                return;
            }

            $this->waiting->detach($task);
            $this->ready[] = $task;
        });
    }

    /**
     * Run until every Task has finished.
     *
     * @return void
     */
    public function run(): void
    {
        $this->runUntil(static fn (): bool => false);
    }

    /**
     * Run until a condition holds, or until there is nothing left to do.
     *
     * This is what `await()` calls from outside a Fiber: the outermost caller
     * drives the loop instead of blocking on the operation, so everything else
     * that is pending keeps progressing while it waits.
     *
     * @param callable(): bool $done Checked before each pass
     * @return void
     * @throws AsyncException When nothing can progress and the condition still does not hold
     */
    public function runUntil(callable $done): void
    {
        $reentrant = $this->running;
        $this->running = true;

        try {
            while (!$done()) {
                if ($this->ready !== []) {
                    $this->step();

                    continue;
                }

                if (!$this->loop->isEmpty()) {
                    $this->loop->tick();

                    continue;
                }

                if ($this->waiting->count() > 0) {
                    $this->failStuckTasks();
                }

                return;
            }
        } finally {
            $this->running = $reentrant;
        }
    }

    /**
     * Give up on every parked Task, because nothing is left that could wake one.
     *
     * Every Task is waiting for something nothing is going to deliver. Saying
     * so beats hanging: a program that stops with a message can be fixed, and
     * one that stops silently is reported as "the server is slow".
     *
     * The stuck Tasks are also cancelled with that same exception and taken
     * off the waiting list. They used to stay parked, so the scheduler — the
     * one shared by the whole process, outside a request — reported the same
     * deadlock again on every later await, however unrelated. A Task that was
     * cancelled while parked is not stuck, only finished, and is dropped
     * without counting.
     *
     * @return void
     * @throws AsyncException When a Task was still waiting
     */
    private function failStuckTasks(): void
    {
        $stuck = [];

        foreach ($this->waiting as $task) {
            if (!$task->isSettled()) {
                $stuck[] = $task;
            }
        }

        $this->waiting = new SplObjectStorage();

        if ($stuck === []) {
            return;
        }

        $deadlock = new AsyncException(sprintf(
            'Deadlock: %d task(s) are waiting and nothing is pending that could wake them.',
            count($stuck)
        ));

        foreach ($stuck as $task) {
            $task->cancel($deadlock);
        }

        throw $deadlock;
    }

    /**
     * Run one ready Task for one slice.
     *
     * @return void
     */
    private function step(): void
    {
        $task = array_shift($this->ready);

        if ($task === null || $task->isSettled()) {
            return;
        }

        $this->current = $task;

        try {
            $task->step();
        } finally {
            $this->current = null;
        }

        /*
         * A Task that is neither finished nor parked yielded without waiting
         * for anything — cooperative multitasking by choice. It goes to the
         * back of the queue so its neighbours get a turn.
         */
        if (!$task->isSettled() && !$this->waiting->contains($task)) {
            $this->ready[] = $task;
        }
    }

    /**
     * The Task currently running, if any.
     *
     * @return Task|null The task
     */
    public function getCurrentTask(): ?Task
    {
        return $this->current;
    }

    /**
     * How many Tasks are queued or parked.
     *
     * @return int The count
     */
    public function getPendingCount(): int
    {
        return count($this->ready) + $this->waiting->count();
    }

    /**
     * How the scheduler's work is divided right now.
     *
     * @return array{ready: int, waiting: int, loop: array{transfers: int, timers: int, watchers: int}} The counts
     */
    public function stats(): array
    {
        return [
            'ready' => count($this->ready),
            'waiting' => $this->waiting->count(),
            'loop' => $this->loop->pending(),
        ];
    }

    /**
     * Whether the scheduler is inside run().
     *
     * @return bool True while running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
