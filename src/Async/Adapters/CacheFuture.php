<?php

namespace SfphpProject\src\Async\Adapters;

use SfphpProject\src\Async\Future;

/**
 * A Future wrapper for cache operations
 *
 * Allows cache get/set/delete operations to be used with async/await syntax.
 * Currently uses lazy execution (actual operation happens when getValue() called),
 * but API is ready for truly non-blocking cache drivers.
 */
class CacheFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];

    private string $operation;
    private string $key;
    private mixed $value;
    private int $ttl;
    private $driver;

    /**
     * Create a CacheFuture for a cache operation
     *
     * @param string $operation get|set|delete|forget
     * @param string $key Cache key
     * @param mixed $value Value for set operation
     * @param int $ttl TTL in seconds for set operation
     * @param mixed $driver Cache driver instance
     */
    public function __construct(
        string $operation,
        string $key,
        mixed $value = null,
        int $ttl = 3600,
        $driver = null
    ) {
        $this->operation = strtolower($operation);
        $this->key = $key;
        $this->value = $value;
        $this->ttl = $ttl;
        $this->driver = $driver;
    }

    /**
     * Execute the cache operation
     */
    private function execute(): void
    {
        if ($this->executed) {
            return;
        }

        try {
            $this->result = $this->performOperation();
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
        }
    }

    /**
     * Perform the actual cache operation
     */
    private function performOperation(): mixed
    {
        if ($this->driver === null) {
            throw new \Exception("Cache driver not provided");
        }

        return match ($this->operation) {
            'get' => $this->driver->get($this->key),
            'set' => $this->driver->set($this->key, $this->value, $this->ttl),
            'delete', 'forget' => $this->driver->delete($this->key),
            'has', 'exists' => $this->driver->has($this->key),
            'increment' => $this->driver->increment($this->key, $this->value ?? 1),
            'decrement' => $this->driver->decrement($this->key, $this->value ?? 1),
            'flush' => $this->driver->flush(),
            default => throw new \Exception("Unknown cache operation: {$this->operation}"),
        };
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
        if (!$this->executed) {
            $this->execute();
        }

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
            $callback($this);
        } else {
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Notify all callbacks
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

    /**
     * Create a CacheFuture for a get operation
     */
    public static function get(string $key, $driver): self
    {
        return new self('get', $key, null, 0, $driver);
    }

    /**
     * Create a CacheFuture for a set operation
     */
    public static function set(string $key, mixed $value, int $ttl = 3600, $driver = null): self
    {
        return new self('set', $key, $value, $ttl, $driver);
    }

    /**
     * Create a CacheFuture for a delete operation
     */
    public static function delete(string $key, $driver): self
    {
        return new self('delete', $key, null, 0, $driver);
    }

    /**
     * Create a CacheFuture for a has/exists operation
     */
    public static function has(string $key, $driver): self
    {
        return new self('has', $key, null, 0, $driver);
    }

    /**
     * Create a CacheFuture for an increment operation
     */
    public static function increment(string $key, int $value = 1, $driver = null): self
    {
        return new self('increment', $key, $value, 0, $driver);
    }

    /**
     * Create a CacheFuture for a decrement operation
     */
    public static function decrement(string $key, int $value = 1, $driver = null): self
    {
        return new self('decrement', $key, $value, 0, $driver);
    }
}
