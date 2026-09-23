<?php

namespace SfphpProject\src\Async;

use Throwable;

/**
 * The bookkeeping every Future needs, in one place.
 *
 * A Future is a value that settles once. It starts PENDING, becomes RUNNING
 * when work on it has begun, and ends RESOLVED, REJECTED or CANCELLED — and
 * once it has ended it never changes again. Callbacks registered before it
 * settles fire when it does; callbacks registered afterwards fire immediately,
 * so a caller never has to ask whether it is too late to listen.
 *
 * This class exists because eight classes carried their own copy of that
 * bookkeeping, each with its own bugs: one resolved twice, one reported
 * "pending" while holding an exception, one dropped its callbacks. A state
 * machine written once is a state machine that can be reasoned about once.
 */
abstract class Pending implements Future
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const RESOLVED = 'resolved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected string $state = self::PENDING;

    protected mixed $value = null;

    protected ?Throwable $reason = null;

    /** @var list<callable(Future): void> */
    protected array $callbacks = [];

    /**
     * The state this Future is in.
     *
     * @return string One of the class constants
     */
    public function state(): string
    {
        return $this->state;
    }

    /**
     * Whether this Future has not settled yet.
     *
     * @return bool True while it may still change
     */
    public function isPending(): bool
    {
        return $this->state === self::PENDING || $this->state === self::RUNNING;
    }

    /**
     * Whether this Future settled with a value.
     *
     * @return bool True when a value is available
     */
    public function isResolved(): bool
    {
        return $this->state === self::RESOLVED;
    }

    /**
     * Whether this Future settled with an exception.
     *
     * @return bool True when an exception is available
     */
    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    /**
     * Whether this Future was cancelled.
     *
     * @return bool True when it was cancelled
     */
    public function isCancelled(): bool
    {
        return $this->state === self::CANCELLED;
    }

    /**
     * Whether this Future has finished changing.
     *
     * @return bool True when resolved, rejected or cancelled
     */
    public function isSettled(): bool
    {
        return !$this->isPending();
    }

    /**
     * The value it settled with.
     *
     * @return mixed The value
     * @throws Throwable The rejection reason, when it was rejected or cancelled
     * @throws AsyncException When it has not settled
     */
    public function getValue(): mixed
    {
        if ($this->state === self::REJECTED || $this->state === self::CANCELLED) {
            throw $this->reason ?? new AsyncException('The operation did not complete.');
        }

        if ($this->state !== self::RESOLVED) {
            /*
             * Reading a value that does not exist yet is a programming error,
             * not a result. It used to return null, so a caller that forgot to
             * await received null and carried on with it — the failure showed
             * up later, somewhere else, as data that was simply missing.
             */
            throw new AsyncException('This operation has not finished. Await it before reading its value.');
        }

        return $this->value;
    }

    /**
     * The exception it settled with, if any.
     *
     * @return Throwable|null The exception, or null
     */
    public function getException(): ?Throwable
    {
        return $this->reason;
    }

    /**
     * Run a callback when this Future settles.
     *
     * @param callable(Future): void $callback The callback
     * @return void
     */
    public function onResolve(callable $callback): void
    {
        if ($this->isSettled()) {
            $callback($this);

            return;
        }

        $this->callbacks[] = $callback;
    }

    /**
     * Settle with a value.
     *
     * @param mixed $value The value
     * @return void
     */
    protected function resolveWith(mixed $value): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->value = $value;
        $this->state = self::RESOLVED;
        $this->settle();
    }

    /**
     * Settle with an exception.
     *
     * @param Throwable $reason The exception
     * @return void
     */
    protected function rejectWith(Throwable $reason): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->reason = $reason;
        $this->state = self::REJECTED;
        $this->settle();
    }

    /**
     * Settle as cancelled.
     *
     * @param Throwable|null $reason Why it was cancelled
     * @return void
     */
    protected function cancelWith(?Throwable $reason = null): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->reason = $reason ?? new CancelledException('The operation was cancelled.');
        $this->state = self::CANCELLED;
        $this->settle();
    }

    /**
     * Hand this Future to everybody waiting on it.
     *
     * @return void
     */
    private function settle(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        foreach ($callbacks as $callback) {
            /*
             * Not wrapped in a catch. A listener that throws is a bug in the
             * listener, and swallowing it — which this used to do, eight times
             * over — turns that bug into a Fiber that is never resumed and a
             * program that stops for no visible reason.
             */
            $callback($this);
        }
    }
}
