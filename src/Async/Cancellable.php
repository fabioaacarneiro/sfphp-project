<?php

namespace SfphpProject\src\Async;

use Throwable;

/**
 * An operation that can be given up on.
 *
 * Kept separate from Future because not everything that settles can be called
 * off: a query already sent to a server is going to finish whether or not
 * anybody still wants the answer. A Future says what happened; a Cancellable
 * says it can also be told to stop.
 *
 * What cancelling guarantees is uniform: the operation settles as CANCELLED and
 * everybody awaiting it is released. What it can additionally do — closing a
 * socket, removing a transfer from the event loop — depends on the operation,
 * and each one says so where it implements this.
 */
interface Cancellable extends Future
{
    /**
     * Give up on this operation.
     *
     * @param Throwable|null $reason Why, for whoever is awaiting it
     * @return void
     */
    public function cancel(?Throwable $reason = null): void;
}
