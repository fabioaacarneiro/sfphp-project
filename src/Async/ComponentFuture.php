<?php

namespace SfphpProject\src\Async;

/**
 * A specialized Future for component rendering with async data
 *
 * Handles loading states, errors, and automatic retries for component rendering
 */
class ComponentFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];

    private $renderer;
    private int $maxRetries;
    private int $retryCount = 0;
    private int $retryDelay = 100; // ms

    /**
     * Create a component future
     *
     * @param callable $renderer The component rendering function
     * @param int $maxRetries Max retry attempts on failure
     */
    public function __construct(callable $renderer, int $maxRetries = 0)
    {
        $this->renderer = $renderer;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Execute the component rendering with retry logic
     */
    private function execute(): void
    {
        if ($this->executed) {
            return;
        }

        try {
            $this->result = $this->render();
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            if ($this->retryCount < $this->maxRetries) {
                $this->retryCount++;
                usleep($this->retryDelay * 1000); // Convert ms to microseconds
                $this->execute(); // Retry
            } else {
                $this->exception = $e;
                $this->executed = true;
                $this->notifyCallbacks();
            }
        }
    }

    /**
     * Render the component
     */
    private function render(): mixed
    {
        return call_user_func($this->renderer);
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
     * Get retry count
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * Set retry delay in milliseconds
     */
    public function setRetryDelay(int $delayMs): self
    {
        $this->retryDelay = $delayMs;
        return $this;
    }

    /**
     * Create component future with loading and error fallback templates
     *
     * The component is retried up to $maxRetries times; when it still fails,
     * $errorFallback receives the exception and its return value becomes the
     * result. $loadingFallback is accepted for compatibility and not called:
     * a server-side render produces one answer, so there is no moment at
     * which a loading state could be shown.
     *
     * The inner closure used to read $maxRetries without capturing it, so the
     * inner future was built with null and failed with a TypeError before the
     * component ever ran — and the outer future then retried that failure too.
     */
    public static function withFallbacks(
        callable $component,
        ?callable $loadingFallback = null,
        ?callable $errorFallback = null,
        int $maxRetries = 0
    ): ComponentFuture {
        return new self(function () use ($component, $errorFallback, $maxRetries) {
            $future = new self($component, $maxRetries);

            try {
                return $future->getValue();
            } catch (\Throwable $e) {
                if ($errorFallback) {
                    return call_user_func($errorFallback, $e);
                }
                throw $e;
            }
        });
    }
}
