<?php

namespace SfphpProject\src\Async;

/**
 * Create an async Task from a callable
 *
 * The Task is automatically scheduled on the current Scheduler if one is active.
 * If no Scheduler is active, you must manually schedule and run it.
 *
 * @param callable(): mixed $executor The function to execute asynchronously
 * @return Task A Task representing the async operation
 *
 * @example
 *   $task = async(fn () => User::query()->find(1));
 *   $user = await($task);
 */
function async(callable $executor): Task
{
    $task = new Task($executor);

    // Try to schedule on current Scheduler if one is active
    if (Context::hasScheduler()) {
        try {
            $scheduler = Context::getScheduler();
            $scheduler->schedule($task);
        } catch (AsyncException $e) {
            // No scheduler active, task will be scheduled manually
        }
    }

    return $task;
}

/**
 * Await the result of a Future
 *
 * If the Future is already resolved, returns immediately.
 * If pending, suspends the current Fiber until the Future resolves.
 *
 * @param Future $future The Future to await
 * @param int|null $timeout Optional timeout in milliseconds
 * @return mixed The resolved value
 * @throws \Throwable If the Future was rejected
 * @throws TimeoutException If timeout was exceeded
 *
 * @example
 *   $user = await(User::query()->find(1));
 *   [$user, $posts] = await(Future::all($task1, $task2));
 */
function await(Future $future, ?int $timeout = null): mixed
{
    // If already resolved, return immediately without suspending
    if ($future->isResolved()) {
        return $future->getValue();
    }

    if ($future->isRejected()) {
        throw $future->getException();
    }

    // If it's a Task that hasn't started, start it
    if ($future instanceof Task) {
        $task = $future;
        if (!$task->isPending() && !$task->isTerminated()) {
            // Get scheduler and schedule if active
            if (Context::hasScheduler()) {
                try {
                    $scheduler = Context::getScheduler();
                    $scheduler->schedule($task);
                } catch (AsyncException $e) {
                    // No scheduler, we'll have to run inline
                    $task->start();
                }
            } else {
                $task->start();
            }
        }
    }

    // Future is still pending, we need to suspend

    // Get current Fiber
    $currentFiber = \Fiber::getCurrent();

    // Track if we've suspended
    $suspended = false;

    // Register a callback to resume this Fiber when Future resolves
    $future->onResolve(function ($resolvedFuture) use ($currentFiber, &$suspended) {
        if ($suspended) {
            // Fiber was suspended, resume it now
            $currentFiber->resume();
        }
    });

    // If Future still pending, suspend this Fiber
    if ($future->isPending()) {
        $suspended = true;
        \Fiber::suspend();
    }

    // Fiber resumed, get the result
    return $future->getValue();
}

/**
 * Await multiple Futures "in parallel"
 *
 * All Futures are started/resumed before any of them blocks,
 * allowing them to progress concurrently.
 *
 * @param Future ...$futures The Futures to await
 * @return array Array of resolved values in order
 * @throws \Throwable If any Future is rejected
 *
 * @example
 *   [$user, $posts] = await(Future::all(
 *       async(fn () => User::query()->find(1)),
 *       async(fn () => Post::query()->where('user_id', 1)->get())
 *   ));
 */
function awaitAll(Future ...$futures): array
{
    return await(CompositeFuture::all(...$futures));
}

/**
 * Create a Future that resolves after a delay
 *
 * In the current implementation (PHP-only), this still blocks.
 * In a future version with true I/O async, this could be non-blocking.
 *
 * @param int $milliseconds The delay in milliseconds
 * @return Future A Future that resolves after the delay
 *
 * @example
 *   $result = await(delay(1000));  // Wait 1 second
 */
function delay(int $milliseconds): Future
{
    return async(function () use ($milliseconds) {
        usleep($milliseconds * 1000);
        return null;
    });
}

/**
 * Execute a Future synchronously in its own Scheduler context
 *
 * Useful for running async code from a synchronous context,
 * or for testing.
 *
 * @param Future $future The Future to execute
 * @return mixed The resolved value
 * @throws \Throwable If the Future was rejected
 *
 * @example
 *   $user = syncRun(User::query()->find(1));
 */
function syncRun(Future $future): mixed
{
    // Create a Scheduler for this operation
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);

    try {
        // If it's a Task, schedule it
        if ($future instanceof Task) {
            $scheduler->schedule($future);
        }

        // Run the scheduler until all Tasks complete
        $scheduler->run();

        // Return the result
        return $future->getValue();
    } finally {
        Context::popScheduler();
    }
}
