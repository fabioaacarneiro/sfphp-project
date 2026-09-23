<?php

namespace SfphpProject\src\Async\Adapters;

use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\EventLoop;
use SfphpProject\src\Async\Pending;
use Throwable;

/**
 * A database query behind the Future contract — scheduled, but not non-blocking.
 *
 * **Read this before believing the name.** The query runs through PDO, and PDO
 * has no asynchronous API: `PDOStatement::execute()` blocks the process until
 * the server answers, and no Fiber can change that. Awaiting one of these does
 * not let another query progress. What it gives is *scheduling*: the work is a
 * value that can be passed around, composed with others and awaited in the same
 * shape as everything else in the runtime.
 *
 * The distinction that matters:
 *
 * - **Async scheduling** — the operation is a Future the runtime can hold,
 *   order and combine. This is what a query gets.
 * - **Non-blocking I/O** — while the operation waits, the process does other
 *   work. This is what an HTTP request gets, and a query does not.
 *
 * So three queries awaited together take as long as the three added up, while
 * three HTTP requests take as long as the slowest. The framework is not going
 * to pretend otherwise: an API that said `await()` and blocked anyway would
 * teach people something false about their own programs.
 *
 * **What would make it true.** PDO cannot, but two stock extensions can:
 * `ext-mysqli` built on mysqlnd exposes `MYSQLI_ASYNC` with `mysqli_poll()`,
 * and `ext-pgsql` exposes `pg_send_query()` with `pg_socket()` and
 * `pg_connection_busy()`. Both hand out something the event loop can already
 * watch — a socket — so a backend written against either would produce a
 * Future that settles from {@see EventLoop::addWatcher()}, and nothing above
 * this class would change: `await(User::query()->getAsync())` is the same line
 * either way. That is the seam, and it is why this is worth having now.
 *
 * The query is run from the event loop rather than from the constructor, so
 * creating one costs nothing and a caller that never awaits it never pays for
 * it — and awaiting it can never deadlock waiting for something nobody started.
 */
final class QueryFuture extends Pending
{
    /** @var callable */
    private $executor;

    /**
     * Arrange to run a query.
     *
     * @param callable $executor Runs the query and returns its result
     * @param EventLoop|null $loop The loop to run it from, or null for the current one
     */
    public function __construct(callable $executor, ?EventLoop $loop = null)
    {
        $this->executor = $executor;
        $this->state = self::RUNNING;

        ($loop ?? Context::scheduler()->loop())->addTimer(0.0, function (): void {
            $this->execute();
        });
    }

    /**
     * Run the query, blocking until the server answers.
     *
     * @return void
     */
    private function execute(): void
    {
        if ($this->isSettled()) {
            return;
        }

        try {
            $this->resolveWith(($this->executor)());
        } catch (Throwable $exception) {
            $this->rejectWith($exception);
        }
    }
}
