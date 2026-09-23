<?php

namespace SfphpProject\src\Async\Adapters;

use SfphpProject\src\Async\Future;

/**
 * A Future wrapper for database queries
 *
 * QueryFuture lazily executes a query when getValue() is called
 * (i.e., when await() accesses the result).
 *
 * This allows the Scheduler to defer database queries until
 * they're actually needed, allowing better parallelism between
 * multiple queries.
 */
class QueryFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];
    private $executor;

    /**
     * Create a QueryFuture from a query executor
     *
     * @param mixed $executor A function that executes the query and returns the result
     */
    public function __construct($executor)
    {
        $this->executor = $executor;
    }

    /**
     * Execute the query if not already executed
     */
    private function execute(): void
    {
        if ($this->executed) {
            return;
        }

        try {
            $this->result = ($this->executor)();
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
        }
    }

    public function isPending(): bool
    {
        return !$this->executed;
    }

    public function isResolved(): bool
    {
        return $this->executed && $this->exception === null;
    }

    public function isRejected(): bool
    {
        return $this->exception !== null;
    }

    public function getValue()
    {
        // Execute query if not already executed
        if (!$this->executed) {
            $this->execute();
        }

        // Throw exception if query failed
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        if (!$this->executed) {
            $this->execute();
        }
        return $this->exception;
    }

    public function onResolve(callable $callback): void
    {
        if ($this->executed) {
            // Already executed, call immediately
            $callback($this);
        } else {
            // Store for later
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Notify all registered callbacks that the query has executed
     */
    private function notifyCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                // Ignore callback errors
            }
        }
        $this->callbacks = [];
    }
}
