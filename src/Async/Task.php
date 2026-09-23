<?php

namespace SfphpProject\src\Async;

use Fiber;
use Throwable;

/**
 * A unit of work the scheduler runs, wrapped around a Fiber.
 *
 * A Task moves through the states its Future contract describes: PENDING until
 * the scheduler first reaches it, RUNNING while its Fiber is alive, and then
 * RESOLVED, REJECTED or CANCELLED. The scheduler advances it one slice at a
 * time through `step()`, which starts the Fiber the first time and resumes it
 * afterwards.
 *
 * The Task never resumes itself. When it awaits something it asks the scheduler
 * to park it and suspends; the scheduler resumes it only once what it waited
 * for has settled. The previous version passed the Fiber into a callback that
 * resumed it from inside whatever call stack happened to settle the Future —
 * which, when that stack was the Fiber's own, meant resuming a Fiber that was
 * not suspended.
 */
final class Task extends Pending implements Cancellable
{
    private ?Fiber $fiber = null;

    /** @var callable */
    private $executor;

    /**
     * Create a Task.
     *
     * @param callable $executor The work to run
     */
    public function __construct(callable $executor)
    {
        $this->executor = $executor;
    }

    /**
     * Advance this Task by one slice.
     *
     * @return void
     */
    public function step(): void
    {
        if ($this->isSettled()) {
            return;
        }

        try {
            if ($this->fiber === null) {
                $this->state = self::RUNNING;
                $this->fiber = new Fiber($this->executor);
                $this->fiber->start();
            } elseif ($this->fiber->isSuspended()) {
                $this->fiber->resume();
            } elseif ($this->fiber->isTerminated()) {
                $this->resolveWith($this->fiber->getReturn());

                return;
            }
        } catch (Throwable $exception) {
            $this->rejectWith($exception);

            return;
        }

        if ($this->fiber->isTerminated()) {
            $this->resolveWith($this->fiber->getReturn());
        }
    }

    /**
     * Whether the Fiber has finished.
     *
     * Kept because it reads well from outside, and because code written against
     * the previous runtime asks this question.
     *
     * @return bool True when there is nothing left to run
     */
    public function isTerminated(): bool
    {
        return $this->isSettled();
    }

    /**
     * Give up on this Task.
     *
     * A suspended Fiber cannot be unwound from outside, so what this can
     * promise is the half that matters to a caller: the Task settles as
     * cancelled, whoever awaits it is told, and the scheduler stops resuming
     * it. Work already inside the Fiber stops at its next suspension point and
     * never runs again.
     *
     * @param Throwable|null $reason Why
     * @return void
     */
    public function cancel(?Throwable $reason = null): void
    {
        $this->cancelWith($reason);
    }

    /**
     * Start this Task outside a scheduler.
     *
     * @return void
     * @deprecated Schedule it instead; a Task that starts itself cannot await.
     */
    public function start(): void
    {
        $this->step();
    }

    /**
     * Resume this Task outside a scheduler.
     *
     * @return void
     * @deprecated Schedule it instead; the scheduler knows when it may be resumed.
     */
    public function resume(): void
    {
        $this->step();
    }
}
