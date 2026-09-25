<?php

namespace SfphpProject\src\Async;

use Throwable;

/**
 * One Future over several.
 *
 * `all()` settles when every part has, with the values in the order they were
 * given. `race()` settles with the first one to finish.
 *
 * Whether the parts progress together is decided by the parts, not here: this
 * listens, it does not drive. An {@see Adapters\HttpFuture} is already in
 * flight when it is created, so three of them genuinely overlap. A Future whose
 * work only happens when somebody reads it cannot be made concurrent by being
 * put in this list, which is worth saying plainly because the shape of the call
 * suggests otherwise.
 *
 *     [$a, $b, $c] = await(CompositeFuture::all(
 *         Http::getAsync($first),
 *         Http::getAsync($second),
 *         Http::getAsync($third),
 *     ));
 */
final class CompositeFuture extends Pending implements Cancellable
{
    /** @var list<Future> */
    private array $futures;

    private string $mode;

    /** @var array<int|string, mixed> */
    private array $results = [];

    private int $settledCount = 0;

    /**
     * Combine several Futures.
     *
     * @param string $mode Either 'all' or 'race'
     * @param Future ...$futures The parts
     */
    public function __construct(string $mode = 'all', Future ...$futures)
    {
        $this->mode = $mode;
        $this->futures = $futures;
        $this->state = self::RUNNING;

        if ($futures === []) {
            $this->resolveWith([]);

            return;
        }

        foreach ($futures as $index => $future) {
            $this->results[$index] = null;

            $future->onResolve(function (Future $settled) use ($index): void {
                $this->absorb($index, $settled);
            });
        }
    }

    /**
     * Take one part's outcome.
     *
     * A part can be named: awaitAll(...['user' => $a, 'posts' => $b]) spreads
     * string keys, which PHP passes as named arguments. The index used to be
     * typed int, so a named part threw a TypeError from inside the event
     * loop — and the timers it had started stayed there, failing the next,
     * unrelated await() in the same process.
     *
     * @param int|string $index Which part
     * @param Future $settled The part
     * @return void
     */
    private function absorb(int|string $index, Future $settled): void
    {
        if ($this->isSettled()) {
            return;
        }

        if (!$settled->isResolved()) {
            /*
             * The first failure settles the whole thing. The others are left to
             * finish on their own rather than cancelled: this does not own
             * them, and a caller that also holds one would be surprised to find
             * it stopped.
             */
            $this->rejectWith($settled->getException() ?? new AsyncException('An awaited operation failed.'));

            return;
        }

        $this->results[$index] = $settled->getValue();
        $this->settledCount++;

        if ($this->mode === 'race') {
            $this->resolveWith($settled->getValue());

            return;
        }

        if ($this->settledCount === count($this->futures)) {
            // Named parts keep their names, in the order given; a list stays a list.
            $this->resolveWith(array_is_list($this->futures) ? array_values($this->results) : $this->results);
        }
    }

    /**
     * Give up, and give up on every part that can be.
     *
     * @param Throwable|null $reason Why
     * @return void
     */
    public function cancel(?Throwable $reason = null): void
    {
        foreach ($this->futures as $future) {
            if ($future instanceof Cancellable && $future->isPending()) {
                $future->cancel($reason);
            }
        }

        $this->cancelWith($reason);
    }

    /**
     * A Future that settles when all of these have.
     *
     * @param Future ...$futures The parts
     * @return Future The composite
     */
    public static function all(Future ...$futures): Future
    {
        return new self('all', ...$futures);
    }

    /**
     * A Future that settles with the first of these to finish.
     *
     * @param Future ...$futures The parts
     * @return Future The composite
     */
    public static function race(Future ...$futures): Future
    {
        return new self('race', ...$futures);
    }
}
