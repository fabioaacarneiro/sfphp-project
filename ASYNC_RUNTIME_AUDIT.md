# Async runtime audit

Written against the code, not against its documentation, and every number below
was produced by a command reproduced here.

**The short version.** The runtime had Fibers, a scheduler, Futures and
`async`/`await`, and none of it performed concurrent I/O. Three HTTP requests
took as long as three HTTP requests. Awaiting from inside a task returned
`null` and did no work at all. What was missing was an event loop; there is one
now, HTTP is genuinely concurrent through it, and the database is honestly
still not.

---

## 1. The architecture as it was

```
async(fn)  ──▶  Task ──▶ Scheduler::run()
                              │
                              └─▶ for each task, every pass: start() or resume()

await($f)  ──▶  $f->onResolve(fn () => $fiber->resume())
                Fiber::suspend()

HttpFuture::get($url)  ──▶  nothing happens
        └─ getValue()  ──▶  curl_exec()          ← blocks here
QueryFuture            ──▶  $executor()          ← blocks here
```

There was no loop, nothing registered pending operations, and nothing waited on
them. `Scheduler::run()` was a `while` over one queue that resumed every task on
every pass, whether or not it had anything to do.

## 2. What was wrong

Ten things, in the order they matter.

**1. No I/O was ever non-blocking.** `HttpFuture` called `curl_exec()` from
inside `getValue()`. The request did not even start until it was awaited, and
then the process stopped until the response arrived.

**1b. Creating three requests together did not start any of them.** Because the
work happened on read, `$a = get(); $b = get(); $c = get();` followed by three
reads took 903.4 ms — the same as writing them one after another. There was no
arrangement of the API that produced overlap.

**2. Awaiting inside a task returned `null` and ran nothing.** The single worst
defect, because it is silent:

```php
$task = new Task(function () use ($url) {
    $a = async(fn () => HttpFuture::get($url)->getValue());
    $b = async(fn () => HttpFuture::get($url)->getValue());
    return [await($a), await($b)];
});

var_dump(syncRun($task));   // NULL — in 1 ms, with no request made
```

**3. `await()` resumed a Fiber from the wrong stack.** It registered
`onResolve(fn () => $currentFiber->resume())`. When the Future settled inside
that same Fiber's own call stack, that is resuming a Fiber that is not
suspended; when `Fiber::getCurrent()` was `null`, it was a call on `null`.

**4. The scheduler spun.** One queue, every task resumed on every pass. A task
waiting on a network response was resumed thousands of times a second to
discover, each time, that nothing had changed.

**5. `delay()` called `usleep()` inside a Fiber**, stopping the entire process —
every other pending operation with it.

**6. `CompositeFuture::all()` could not create concurrency.** It listens; it does
not start anything. Given lazy futures it produced strictly sequential work
behind a parallel-looking call.

**7. Reading a value too early returned `null`.** `Task::getValue()` on an
unfinished task returned the uninitialised property, so a missing `await` became
missing data somewhere else entirely.

**8. `async()` and `await()` were not autoloadable.** `src/Async/functions.php`
was absent from `composer.json`'s `files`. In a fresh project
`function_exists('SfphpProject\src\Async\async')` was `false`.

**9. The async exceptions were not autoloadable either.** `Exceptions.php`
declared three classes in one file, which PSR-4 cannot map, so a timeout raised
`Error: Class "TimeoutException" not found`.

**10. The benchmarks did not measure concurrency.** `benchmark-async.php` timed
`async()`/`await()` around work with no I/O in it, which measures the cost of the
runtime and says nothing about overlap.

Three more found on the way, outside the path this work covers:

- `src/Async/WebSocketFuture.php` **does not parse**. It declares `connect()`
  both as an instance method and as a static factory, which is a fatal error, so
  the class had never been loaded by anything — and it ships. `composer run
  lint` was failing on `master` because of it.
- The published package carries thirteen scratch files (`PHASE4_SUMMARY.md`,
  `example-phase5.php`, `test-async.php` and friends) into the root of every
  project installed from 0.12.0.
- The test suite on `master` was red for the same reason: `153 passed, 1
  failed`.

## 3. What was corrected

| Piece | Before | Now |
|---|---|---|
| `EventLoop` | did not exist | one `curl_multi` for transfers, timers, stream watchers; one wait for all of it |
| `Scheduler` | one queue, resumed everything | ready and parked queues; a parked task is untouched until its Future settles |
| `await()` | resumed the Fiber from the settling stack | parks the Fiber and lets the scheduler resume it; drives the loop when called outside a task |
| `Future` | eight hand-written copies | one state machine: `PENDING`, `RUNNING`, `RESOLVED`, `REJECTED`, `CANCELLED` |
| `Task` | `isPending()` meant three things | states from `Pending`, `step()` for the scheduler, `cancel()` |
| `HttpFuture` | `curl_exec()` on read | added to the multi handle at construction; settles from the loop; resolves to `ClientResponse` |
| `delay()` | `usleep()` in a Fiber | a timer in the loop |
| `CompositeFuture` | own bookkeeping | on `Pending`, propagates cancellation |
| timeouts | none | `await($f, timeout: 5000)`, backed by a loop timer, raising `TimeoutException` |
| autoloading | functions and exceptions unreachable | `files` entry; one class per file |

## 4. What is genuinely non-blocking now

**HTTP.** Every request is handed to a single `curl_multi` handle when it is
created, so it is in flight before anything awaits it, and libcurl drives all of
them from one `curl_multi_select()`. Fifty requests are fifty sockets and one
waiting process.

**Timers.** `delay()` is a deadline the loop wakes for.

**The scheduler's idle time.** When no task is ready the process waits in
`curl_multi_select()` or `stream_select()` — it does not poll.

## 5. What still blocks

**Every database query.** PDO has no asynchronous API. `PDOStatement::execute()`
blocks until the server answers and no Fiber changes that. `QueryFuture` says so
in its own docblock rather than implying otherwise, and section 11 has the
measurement.

**`CacheFuture`, `FileFuture`, `ComponentFuture`.** Still do their work when
their value is read. `await()` detects them and reads them directly rather than
waiting for something nobody started, so they behave exactly as before. They are
the next thing to convert: file reads have no non-blocking path worth the
trouble, but a Redis cache is a socket and belongs in the loop.

**`StreamFuture`, `WebSocketFuture`.** Not audited in depth; they were outside
the path this work covers and are listed here so nobody reads their absence as
approval.

## 6. What PHP and its extensions allow

| Capability | Stock PHP | Verdict |
|---|---|---|
| Concurrent HTTP | `curl_multi_*` in ext-curl | **Yes**, and that is what this uses |
| Timers | userland, over `select` timeouts | Yes |
| Socket readiness | `stream_select()` | Yes |
| Concurrent MySQL | `MYSQLI_ASYNC` + `mysqli_poll()` in ext-mysqli on mysqlnd | **Yes, but not through PDO** |
| Concurrent PostgreSQL | `pg_send_query()` + `pg_socket()` + `pg_connection_busy()` in ext-pgsql | **Yes, but not through PDO** |
| Concurrent anything through PDO | — | **No.** There is no API for it |
| Merging cURL's descriptors into `stream_select()` | `curl_multi_fdset()` is not exposed to PHP | No |

Verified rather than assumed:

```
$ docker run --rm php:8.3-cli sh -c 'docker-php-ext-install mysqli >/dev/null 2>&1 && php -r "..."'
mysqli=sim poll=sim ASYNC_const=sim
```

So non-blocking database I/O *is* reachable with stock extensions — through
`mysqli` or `pgsql`, not through the PDO layer this framework is built on.
Changing that is a database backend, not an async change, which is why it is not
in this work.

## 7. The event loop

```
                    ┌──────────────────────┐
                    │      Scheduler       │  ready ──▶ run one slice
                    └──────────┬───────────┘  parked ──▶ untouched
                               │ nothing ready?
                    ┌──────────▼───────────┐
                    │      EventLoop       │  tick()
                    └──────────┬───────────┘
             ┌─────────────────┼─────────────────┐
             ▼                 ▼                 ▼
      curl_multi_select   timer deadline    stream_select
             │                 │                 │
             └─────────────────┼─────────────────┘
                               ▼
                     settle the Future
                               ▼
                 scheduler moves the task to ready
```

`tick()` is the only place the process is allowed to wait, and it waits for
everything at once. Its timeout is the nearest timer, capped at 50 ms so a tick
stays responsive. When cURL reports it has nothing to wait on — which happens
while a transfer is being set up — the loop pauses a millisecond rather than
spinning, which is libcurl's own advice.

**The one compromise, stated plainly.** PHP does not expose
`curl_multi_fdset()`, so cURL's descriptors cannot join the same
`stream_select()` as the stream watchers. When both kinds are pending the loop
waits on cURL and polls the streams without blocking, costing a wake-up every
few milliseconds in that mixed case — and nothing in the common one, where only
transfers are pending.

## 8. Future

One state machine in `Pending`, which every new Future extends.

```
PENDING ──▶ RUNNING ──┬──▶ RESOLVED
                      ├──▶ REJECTED
                      └──▶ CANCELLED
```

Settled is final. Callbacks registered before it settles run when it does;
callbacks registered after run immediately. A callback that throws is *not*
swallowed — the previous code caught and discarded listener exceptions in eight
places, which turns a bug in a listener into a Fiber that is never resumed.

`getValue()` on an unsettled Future throws instead of returning `null`.
`Cancellable` is separate, because not everything that settles can be called
off: a query already sent is going to finish whether or not anybody wants the
answer.

## 9. Scheduler

```
       schedule()          step()                     Future settles
READY ──────────▶ READY ──────────▶ RUNNING ──┬──▶ WAITING ──────────────▶ READY
                                              ├──▶ RESOLVED
                                              ├──▶ REJECTED
                                              └──▶ CANCELLED
```

A parked task is in an `SplObjectStorage` and is not looked at again until the
Future it waited on settles. When nothing is ready the scheduler ticks the loop.
When nothing is ready, the loop is empty and tasks are still parked, it raises a
deadlock error instead of hanging — a program that stops with a message can be
fixed; one that stops silently gets reported as "the server is slow".

## 10. HTTP async

```php
$a = Http::getAsync($first);     // on the wire already
$b = Http::getAsync($second);    // and this one
$c = Http::getAsync($third);

[$x, $y, $z] = [await($a), await($b), await($c)];   // ≈ the slowest of the three
```

Resolves to `ClientResponse`, the same object the synchronous client returns, so
`status()`, `json()` and `ok()` mean one thing in both. Failures that are not
answers — a refused connection, a name that does not resolve, a certificate that
does not verify — reject with `ClientException` rather than resolving with an
empty response. `await(..., timeout: 300)` cancels the transfer, releasing the
socket, and raises `TimeoutException`.

## 11. Database

**Not concurrent, and the framework says so.** Measured against MySQL 8.0.46,
with the wait happening inside the server (`SELECT SLEEP(0.2)`) so that it is
exactly the kind of wait an async runtime should overlap:

```
  1 query, synchronous                        200.9 ms
  3 queries, synchronous                      603.4 ms
  3 queries, awaited together                 609.0 ms      ← no overlap
  3 HTTP requests, awaited together           205.3 ms      ← overlap
```

The two shapes are written identically and behave differently, which is the
distinction worth learning:

- **Async scheduling** — the operation is a value the runtime can hold, order
  and combine. A query gets this.
- **Non-blocking I/O** — while it waits, the process does other work. HTTP gets
  this; a query does not.

**The seam for fixing it.** Nothing above `QueryFuture` would have to change. A
backend on `ext-mysqli` (`MYSQLI_ASYNC`) or `ext-pgsql` (`pg_send_query`) hands
out a socket, `EventLoop::addWatcher()` already takes one, and
`await(User::query()->getAsync())` is the same line either way.

## 12. Reproducing everything here

```bash
# the origin: one process, non-blocking, answers after a delay it is told
php benchmarks/origin.php 127.0.0.1:8300 &

# benchmarks 1 and 2
php benchmarks/async.php http://127.0.0.1:8300 300

# benchmark 3
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8200 benchmarks/server.php &
ab -n 2000 -c 50 http://127.0.0.1:8200/hello
ab -n 200  -c 20 http://127.0.0.1:8200/http-parallel

# benchmark 4
docker run -d --name sfphp-bench-db -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=bench -p 33061:3306 mysql:8.0
php benchmarks/database.php "mysql:host=127.0.0.1;port=33061;dbname=bench" root secret
```

**Why the origin is not `php -S`.** PHP's built-in server answers from a fixed
pool of worker processes, so measuring concurrency against it measures the pool.
Three 300 ms requests took 600 ms against it while the client was already
perfectly concurrent — and plain `curl_multi`, with no framework at all, showed
the same 600 ms, which is how the origin was identified as the culprit rather
than the code under test.

## 13. Results

Machine: 12th Gen Intel Core i7-12650H, 16 cores · Linux 6.12.107-amd64 ·
PHP 8.4.24 (NTS) · curl 8.14.1 · origin `benchmarks/origin.php`, single process,
delay 300 ms unless stated · one run each, no warm-up.

**Runtime overhead, no I/O**

| | |
|---|---|
| 10,000 plain calls | 0.7 ms |
| 10,000 `async()` + `await()` | 53.3 ms |
| **cost of a task** | **≈ 5.3 µs** |

**HTTP, real sockets**

Both columns are measured, against the same origin, on the same machine. The
"before" column comes from the previous implementation checked out into a
worktree and run against the same server.

| | before | now |
|---|---|---|
| 1 request | 301.0 ms | 301.7 ms |
| 3 requests, awaited one at a time | 903.3 ms | 902.9 ms |
| 3 requests created together, read later | 903.4 ms | — |
| 3 requests, all in flight | not possible | **301.3 ms** |
| 10 requests, all in flight | not possible | **301.6 ms** |
| 50 requests, all in flight | not possible | **309.0 ms** |
| 10 requests through `async()` tasks | returned `null` | **302.8 ms** |
| peak memory, 50 in flight | — | 2.00 MB |

"Before" is the same benchmark run against the previous implementation, which is
why the first two rows barely move: the correction is not that requests became
faster, it is that they became able to overlap.

**Server, `ab`, built-in server with 8 workers, origin delay 100 ms**

| endpoint | n | c | RPS | p50 | p95 | p99 | failed |
|---|---|---|---|---|---|---|---|
| `/hello` | 2000 | 50 | 12,658 | 4 ms | 7 ms | 9 ms | 0 |
| `/json` | 2000 | 50 | 11,737 | 4 ms | 6 ms | 8 ms | 0 |
| `/phpx` | 2000 | 50 | 12,122 | 4 ms | 7 ms | 9 ms | 0 |
| `/http` (1 call) | 200 | 20 | 80.6 | 207 ms | 414 ms | 724 ms | 0 |
| `/http-parallel` (3 calls) | 200 | 20 | 80.4 | 208 ms | 416 ms | 520 ms | 0 |

The last two rows are the result: **three outbound calls cost what one costs**.
The 80 RPS ceiling is the worker pool — 8 workers × one 100 ms wait — not the
runtime, and a different server would move it.

CPU and RAM were not sampled per run; `ab`'s own numbers and PHP's peak memory
are what is reported, and inventing the rest would defeat the point of the
exercise.

## 14. The next bottlenecks

1. **Database I/O**, the big one. A `mysqli`/`pgsql` backend behind the existing
   Future contract is the only way to make `CompositeFuture::all()` over three
   queries mean anything.
2. **`CacheFuture` on Redis.** A socket the loop could watch, currently blocking.
3. **One wait for everything.** Without `curl_multi_fdset()`, mixed cURL and
   stream work costs a small poll. A stream-based HTTP client would remove the
   split entirely — at the cost of writing TLS and HTTP/2 by hand, which is not
   obviously worth it.
4. **The worker pool.** Everything measured here is one request per process. The
   runtime is ready for a persistent server; that is a separate piece of work and
   should not be confused with this one.
5. **Cancellation is cooperative.** A cancelled task settles and its awaiters are
   released, but the Fiber's own code stops only at its next suspension point.
   PHP offers no way to unwind a suspended Fiber from outside.
