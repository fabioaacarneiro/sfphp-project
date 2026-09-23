<?php

namespace SfphpProject\src\Async;

/**
 * Reactive state management for components with automatic invalidation
 *
 * Allows components to maintain state that updates asynchronously
 * and automatically invalidates related cache entries.
 */
class ReactiveState
{
    private mixed $value = null;
    private ?\Throwable $error = null;
    private bool $loading = false;
    private array $dependencies = [];
    private array $listeners = [];
    private int $ttl = 0;
    private int $createdAt = 0;

    /**
     * Create reactive state with initial value
     */
    public function __construct(mixed $initialValue = null, int $ttl = 0)
    {
        $this->value = $initialValue;
        $this->ttl = $ttl;
        $this->createdAt = time();
    }

    /**
     * Set the value
     */
    public function setValue(mixed $value): self
    {
        if ($this->value !== $value) {
            $this->value = $value;
            $this->error = null;
            $this->notifyListeners();
        }
        return $this;
    }

    /**
     * Get the current value
     */
    public function getValue(): mixed
    {
        if ($this->isExpired()) {
            return null;
        }
        return $this->value;
    }

    /**
     * Set loading state
     */
    public function setLoading(bool $loading): self
    {
        $this->loading = $loading;
        if (!$loading) {
            $this->notifyListeners();
        }
        return $this;
    }

    /**
     * Is currently loading
     */
    public function isLoading(): bool
    {
        return $this->loading;
    }

    /**
     * Set error state
     */
    public function setError(\Throwable $error): self
    {
        $this->error = $error;
        $this->notifyListeners();
        return $this;
    }

    /**
     * Get error if any
     */
    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * Has error
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Check if state is stale (expired)
     */
    public function isExpired(): bool
    {
        if ($this->ttl === 0) {
            return false; // Never expires
        }
        return (time() - $this->createdAt) > $this->ttl;
    }

    /**
     * Reset state
     */
    public function reset(): self
    {
        $this->value = null;
        $this->error = null;
        $this->loading = false;
        $this->createdAt = time();
        $this->notifyListeners();
        return $this;
    }

    /**
     * Add a cache dependency (for invalidation)
     */
    public function addDependency(string $cacheKey): self
    {
        if (!in_array($cacheKey, $this->dependencies)) {
            $this->dependencies[] = $cacheKey;
        }
        return $this;
    }

    /**
     * Get all dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Subscribe to changes
     */
    public function onChange(callable $callback): self
    {
        $this->listeners[] = $callback;
        return $this;
    }

    /**
     * Notify all listeners of change
     */
    private function notifyListeners(): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($this);
            } catch (\Throwable $e) {
                // Ignore listener errors
            }
        }
    }

    /**
     * Update state from a Future
     */
    public function updateFromFuture(Future $future): self
    {
        $this->loading = true;

        try {
            $value = $future->getValue();
            $this->setValue($value);
        } catch (\Throwable $e) {
            $this->setError($e);
        } finally {
            $this->loading = false;
        }

        return $this;
    }

    /**
     * Serialize for view rendering
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'loading' => $this->loading,
            'error' => $this->error ? $this->error->getMessage() : null,
            'hasError' => $this->hasError(),
            'expired' => $this->isExpired(),
        ];
    }
}
