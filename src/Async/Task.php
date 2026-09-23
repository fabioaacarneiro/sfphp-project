<?php

namespace SfphpProject\src\Async;

/**
 * Represents an async task wrapping a PHP Fiber
 *
 * A Task can be in one of these states:
 * - Pending: not started or started but not finished
 * - Resolved: completed successfully with a value
 * - Rejected: failed with an exception
 */
class Task implements Future
{
    private ?\Fiber $fiber = null;
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $started = false;
    private bool $cancelled = false;
    private array $callbacks = [];

    private $executor;

    /**
     * Create a new Task from an executor function
     *
     * @param mixed $executor The function to execute in the Task
     */
    public function __construct($executor)
    {
        $this->executor = $executor;
    }

    /**
     * Start the Task's Fiber
     *
     * This should be called by the Scheduler when the Task first needs to run
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        // Create the Fiber that will execute the task
        $this->fiber = new \Fiber(function () {
            try {
                // Execute the task's function
                $this->result = ($this->executor)();
                $this->notifyCallbacks();
            } catch (\Throwable $e) {
                $this->exception = $e;
                $this->notifyCallbacks();
            }
        });

        // Start the Fiber (executes until first Fiber::suspend())
        $this->fiber->start();
    }

    /**
     * Resume a suspended Fiber
     *
     * This is called by the Scheduler when a Task that was waiting
     * on an await() is ready to continue
     */
    public function resume(): void
    {
        if (!$this->fiber || !$this->started) {
            return;
        }

        // Check if Fiber is still running
        if ($this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            try {
                $this->fiber->resume();
            } catch (\Throwable $e) {
                $this->exception = $e;
                $this->notifyCallbacks();
            }
        }
    }

    /**
     * Check if the Fiber has terminated
     */
    public function isTerminated(): bool
    {
        return $this->fiber === null || $this->fiber->isTerminated();
    }

    public function isPending(): bool
    {
        // Still running
        if ($this->started && !$this->isTerminated()) {
            return $this->exception === null;
        }

        // Not started yet
        if (!$this->started) {
            return true;
        }

        // Finished with error
        if ($this->exception !== null) {
            return false;
        }

        // Finished with result
        return false;
    }

    public function isResolved(): bool
    {
        return $this->isTerminated() && $this->exception === null;
    }

    public function isRejected(): bool
    {
        return $this->exception !== null;
    }

    public function getValue()
    {
        if ($this->exception) {
            throw $this->exception;
        }

        if (!$this->isTerminated()) {
            throw new AsyncException("Task is still pending");
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function onResolve(callable $callback): void
    {
        if ($this->isTerminated()) {
            // Already resolved, call immediately
            $callback($this);
        } else {
            // Store for later when resolved
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Mark this Task as cancelled
     *
     * Note: There's no native way to cancel a Fiber, so we just mark it
     * and the result will be ignored
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Check if this Task was cancelled
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Notify all registered callbacks that this Task has resolved
     *
     * @internal
     */
    private function notifyCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                // Ignore callback errors to prevent cascade failures
            }
        }
        $this->callbacks = [];
    }
}
