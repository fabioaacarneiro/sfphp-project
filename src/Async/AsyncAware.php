<?php

namespace SfphpProject\src\Async;

use function SfphpProject\src\Async\{async, syncRun};

/**
 * Trait for classes that need async support
 *
 * Use this trait in your model classes, services, or components
 * to enable async execution context.
 *
 * @example
 *   class UserService {
 *       use AsyncAware;
 *
 *       public function getUser($id) {
 *           return $this->async(fn () => User::query()->findAsync($id));
 *       }
 *   }
 */
trait AsyncAware
{
    /**
     * Execute a callable within an async context
     *
     * Creates a Scheduler for the duration of the call,
     * allowing the callable to use await() and async()
     *
     * @param callable(): mixed $callable The function to execute
     * @return mixed The result from the callable
     * @throws \Throwable If the callable throws
     *
     * @example
     *   $user = $this->withAsync(fn () =>
     *       await(User::query()->findAsync(1))
     *   );
     */
    protected function withAsync(callable $callable): mixed
    {
        return syncRun(async($callable));
    }

    /**
     * Check if async is currently enabled
     *
     * @return bool True if we're in an async context
     */
    protected function isAsyncEnabled(): bool
    {
        return Context::hasScheduler();
    }

    /**
     * Get the current Scheduler (if one is active)
     *
     * @return Scheduler|null The active Scheduler or null
     * @throws AsyncException If no Scheduler is active
     */
    protected function getScheduler(): ?Scheduler
    {
        try {
            return Context::getScheduler();
        } catch (AsyncException $e) {
            return null;
        }
    }
}
