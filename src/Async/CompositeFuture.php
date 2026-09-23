<?php

namespace SfphpProject\src\Async;

/**
 * A Future that combines multiple other Futures
 *
 * CompositeFuture::all() waits for ALL Futures to resolve.
 * CompositeFuture::race() waits for the FIRST Future to resolve.
 */
class CompositeFuture implements Future
{
    private array $futures = [];
    private array $results = [];
    private ?\Throwable $exception = null;
    private bool $resolved = false;
    private array $callbacks = [];
    private int $completedCount = 0;
    private string $mode; // 'all' or 'race'

    /**
     * Create a composite Future
     *
     * @param string $mode Either 'all' or 'race'
     * @param Future ...$futures The Futures to combine
     */
    public function __construct(string $mode = 'all', Future ...$futures)
    {
        $this->futures = $futures;
        $this->mode = $mode;

        // Initialize results array
        foreach ($this->futures as $i => $future) {
            $this->results[$i] = null;
        }

        // Register callbacks for each Future
        foreach ($this->futures as $i => $future) {
            $this->attachCallback($i, $future);
        }

        // If no futures, resolve immediately
        if (empty($this->futures)) {
            $this->resolved = true;
            $this->notifyCallbacks();
        }
    }

    /**
     * Attach a callback to a Future
     *
     * @param int $index The index in the futures array
     * @param Future $future The future to monitor
     */
    private function attachCallback(int $index, Future $future): void
    {
        $future->onResolve(function ($resolvedFuture) use ($index) {
            if ($this->resolved) {
                // Already resolved, ignore
                return;
            }

            try {
                // Store the result
                $this->results[$index] = $resolvedFuture->getValue();
                $this->completedCount++;

                // Check completion based on mode
                if ($this->mode === 'race') {
                    // Race mode: resolve on first completion
                    $this->resolved = true;
                    $this->notifyCallbacks();
                } elseif ($this->completedCount === count($this->futures)) {
                    // All mode: resolve when all complete
                    $this->resolved = true;
                    $this->notifyCallbacks();
                }
            } catch (\Throwable $e) {
                // Future was rejected
                if (!$this->resolved) {
                    $this->exception = $e;
                    $this->resolved = true;
                    $this->notifyCallbacks();
                }
            }
        });
    }

    public function isPending(): bool
    {
        return !$this->resolved && $this->exception === null;
    }

    public function isResolved(): bool
    {
        return $this->resolved && $this->exception === null;
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

        if (!$this->resolved) {
            throw new AsyncException("CompositeFuture is still pending");
        }

        // Return results in order
        ksort($this->results);
        return array_values($this->results);
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function onResolve(callable $callback): void
    {
        if ($this->resolved || $this->exception) {
            $callback($this);
        } else {
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Notify all registered callbacks
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
     * Create a Future that waits for ALL Futures to resolve
     *
     * @param Future ...$futures The Futures to combine
     * @return Future A Future that resolves when all input Futures are done
     *
     * @example
     *   [$user, $posts] = await(Future::all($task1, $task2));
     */
    public static function all(Future ...$futures): Future
    {
        return new self('all', ...$futures);
    }

    /**
     * Create a Future that waits for the FIRST Future to resolve
     *
     * @param Future ...$futures The Futures to combine
     * @return Future A Future that resolves when the first input Future is done
     *
     * @example
     *   $result = await(Future::race(
     *       async(fn () => slow_operation()),
     *       delay(5000)->then(fn () => throw new TimeoutException())
     *   ));
     */
    public static function race(Future ...$futures): Future
    {
        return new self('race', ...$futures);
    }
}
