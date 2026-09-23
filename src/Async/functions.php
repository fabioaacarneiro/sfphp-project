<?php

namespace SfphpProject\src\Async;

use Fiber;
use Throwable;

/**
 * Run something as a Task, alongside whatever else is running.
 *
 * The Task is queued on the current scheduler and begins at the next
 * opportunity, so calling this twice puts two pieces of work in flight before
 * either is awaited.
 *
 * @param callable $executor The work
 * @return Task The task
 *
 * @example
 *   $a = async(fn () => Http::getAsync($first));
 *   $b = async(fn () => Http::getAsync($second));
 *   [$x, $y] = [await($a), await($b)];
 */
function async(callable $executor): Task
{
    $task = new Task($executor);

    Context::scheduler()->schedule($task);

    return $task;
}

/**
 * Wait for a Future, without stopping anything else.
 *
 * Inside a Task this parks the Fiber: the scheduler is told what it is waiting
 * for, the Fiber suspends, and it is resumed once that has settled. Outside
 * one — a controller, a command, a test — the caller drives the event loop
 * instead, so every pending operation keeps progressing while it waits.
 *
 * Either way the process never polls. It waits in a single select() over
 * everything outstanding, and wakes for whichever finishes first.
 *
 * @param Future $future What to wait for
 * @param int|null $timeout Milliseconds to allow, or null for no limit
 * @return mixed The value it settled with
 * @throws Throwable Whatever it was rejected with
 * @throws TimeoutException When the timeout passes first
 *
 * @example
 *   $response = await(Http::getAsync($url), timeout: 5000);
 */
function await(Future $future, ?int $timeout = null): mixed
{
    $scheduler = Context::scheduler();

    if ($future instanceof Task) {
        $scheduler->schedule($future);
    }

    $timer = null;

    if ($timeout !== null && $future->isPending()) {
        $loop = $scheduler->loop();
        $timer = $loop->addTimer($timeout / 1000, static function () use ($future, $timeout): void {
            if (!$future->isPending()) {
                return;
            }

            $expired = new TimeoutException(sprintf('The operation did not finish within %d ms.', $timeout));

            /*
             * Cancelling is what releases the network handle and everybody
             * waiting on it. A Future that cannot be cancelled is left to
             * finish on its own — the caller is freed by the exception below,
             * but nothing pretends the work stopped.
             */
            if ($future instanceof Cancellable) {
                $future->cancel($expired);
            }
        });
    }

    try {
        if ($future->isPending() && !$future instanceof Pending) {
            /*
             * An adapter from before this runtime existed. Those do their work
             * when their value is read — CacheFuture, FileFuture and
             * ComponentFuture all still do — so there is nothing for the loop
             * to wait for, and waiting would be waiting for something nobody
             * started. Reading it runs it, blocking, which is what it did
             * before and what the audit records as still outstanding.
             */
            return $future->getValue();
        }

        if ($future->isPending()) {
            $task = $scheduler->getCurrentTask();

            if ($task !== null && Fiber::getCurrent() !== null) {
                $scheduler->park($task, $future);
                Fiber::suspend();
            } else {
                $scheduler->runUntil(static fn (): bool => $future->isSettled());
            }
        }

        if ($future->isCancelled() && $future->getException() instanceof TimeoutException) {
            throw $future->getException();
        }

        return $future->getValue();
    } finally {
        if ($timer !== null) {
            $scheduler->loop()->cancelTimer($timer);
        }
    }
}

/**
 * Wait for several Futures at once.
 *
 * @param Future ...$futures What to wait for
 * @return array<int, mixed> The values, in the order given
 * @throws Throwable Whatever the first failure was rejected with
 */
function awaitAll(Future ...$futures): array
{
    return await(CompositeFuture::all(...$futures));
}

/**
 * A Future that settles after a delay.
 *
 * The wait belongs to the event loop, so everything else keeps progressing
 * through it. This used to call `usleep()` inside a Fiber, which stopped the
 * entire process — every other request in flight included.
 *
 * @param int $milliseconds How long
 * @param mixed $value What to settle with
 * @return Future The future
 */
function delay(int $milliseconds, mixed $value = null): Future
{
    return new TimerFuture(Context::scheduler()->loop(), $milliseconds / 1000, $value);
}

/**
 * Run a Future to completion from synchronous code.
 *
 * @param Future $future What to run
 * @return mixed The value it settled with
 * @throws Throwable Whatever it was rejected with
 */
function syncRun(Future $future): mixed
{
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);

    try {
        return await($future);
    } finally {
        Context::popScheduler();
    }
}
