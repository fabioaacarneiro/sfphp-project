<?php

namespace SfphpProject\src\Async\Adapters;

use SfphpProject\src\Async\Future;

/**
 * A Future wrapper for cache operations
 *
 * Allows cache get/set/delete operations to be used with async/await syntax.
 * Currently uses lazy execution (actual operation happens when getValue() called),
 * but API is ready for truly non-blocking cache drivers.
 *
 * The operation blocks when it runs: awaiting several of these runs them one
 * after another. What this adds is the Future shape, not concurrency.
 *
 * The driver is the framework's cache — `cache()`, a CacheManager or any
 * {@see \SfphpProject\src\Cache\Cache} — whose methods are put() and forget().
 * A PSR-16 style object with set() and delete() is accepted too, so code
 * written against one keeps working, but the framework's names are tried first.
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
     * @param string $operation get|set|put|delete|forget|has|exists|increment|decrement|flush
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

        /*
         * These used to call set() and delete() only, which the framework's
         * cache does not have: every write or delete made through this adapter
         * with `cache()` failed with "Call to undefined method".
         */
        return match ($this->operation) {
            'get' => $this->driver->get($this->key),
            'set', 'put' => $this->write(),
            'delete', 'forget' => $this->remove(),
            'has', 'exists' => $this->driver->has($this->key),
            'increment' => $this->driver->increment($this->key, $this->value ?? 1),
            'decrement' => method_exists($this->driver, 'decrement')
                ? $this->driver->decrement($this->key, $this->value ?? 1)
                // The Cache interface has no decrement; a negative increment is the same atomic step.
                : $this->driver->increment($this->key, -($this->value ?? 1)),
            'flush' => $this->driver->flush(),
            default => throw new \Exception("Unknown cache operation: {$this->operation}"),
        };
    }

    /**
     * Store the value, through put() when the driver has it.
     *
     * A TTL of zero or less means "no expiry" to put(), which takes null for
     * that; passing 0 through would store an entry that is already expired.
     *
     * @return mixed True for put(), which reports nothing and throws on failure; the driver's own answer for set()
     */
    private function write(): mixed
    {
        if (method_exists($this->driver, 'put')) {
            $this->driver->put($this->key, $this->value, $this->ttl > 0 ? $this->ttl : null);

            return true;
        }

        return $this->driver->set($this->key, $this->value, $this->ttl);
    }

    /**
     * Remove the key, through forget() when the driver has it.
     *
     * @return mixed True for forget(), which reports nothing; the driver's own answer for delete()
     */
    private function remove(): mixed
    {
        if (method_exists($this->driver, 'forget')) {
            $this->driver->forget($this->key);

            return true;
        }

        return $this->driver->delete($this->key);
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
