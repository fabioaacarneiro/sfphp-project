<?php

namespace SfphpProject\src\Async;

/**
 * Represents a value that may be available now or in the future
 */
interface Future
{
    /**
     * Check if the future is still pending (not yet resolved or rejected)
     */
    public function isPending(): bool;

    /**
     * Check if the future was resolved successfully
     */
    public function isResolved(): bool;

    /**
     * Check if the future was rejected (threw an exception)
     */
    public function isRejected(): bool;

    /**
     * Get the resolved value
     *
     * @return mixed The resolved value
     * @throws \Throwable If the future was rejected
     * @throws \RuntimeException If the future is still pending
     */
    public function getValue();

    /**
     * Get the exception if the future was rejected
     *
     * @return \Throwable|null The exception or null if not rejected
     */
    public function getException(): ?\Throwable;

    /**
     * Register a callback to be invoked when the future is resolved or rejected
     *
     * @param callable(Future): void $callback The callback function
     */
    public function onResolve(callable $callback): void;
}
