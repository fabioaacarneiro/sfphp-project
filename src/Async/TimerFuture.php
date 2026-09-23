<?php

namespace SfphpProject\src\Async;

use Throwable;

/**
 * A Future that settles when a moment arrives.
 *
 * `delay()` used to be a Task that called `usleep()`, which stopped the whole
 * process — every other pending request included — for the length of the
 * delay. A delay is a deadline the event loop already wakes up for, so this
 * costs nothing while it waits and everything else keeps moving.
 */
final class TimerFuture extends Pending implements Cancellable
{
    private EventLoop $loop;

    private ?int $timer;

    /**
     * Arrange to settle after a delay.
     *
     * @param EventLoop $loop The loop that keeps the time
     * @param float $seconds How long
     * @param mixed $value What to settle with
     */
    public function __construct(EventLoop $loop, float $seconds, mixed $value = null)
    {
        $this->loop = $loop;
        $this->state = self::RUNNING;

        $this->timer = $loop->addTimer($seconds, function () use ($value): void {
            $this->timer = null;
            $this->resolveWith($value);
        });
    }

    /**
     * Stop waiting.
     *
     * @param Throwable|null $reason Why
     * @return void
     */
    public function cancel(?Throwable $reason = null): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->cancelWith($reason);
    }
}
