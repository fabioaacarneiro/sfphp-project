# Async Runtime Guide

> **Read in:** [English](ASYNC.md) · [Português](../pt-BR/ASYNC.md) · [Español](../es/ASYNC.md)

This guide covers SFPHP's async runtime in depth: what each function and class
does, what it does not do, and where the process really waits. It adds detail to
the [Async section of the main documentation](DOCUMENTATION.md#async) and does
not replace it.

Every example that starts with `<?php` runs as written, assuming Composer's
autoloader is loaded (it always is inside an SFPHP application). The examples
call `https://api.example.com`. Point them at a server you control to try them.
The database examples also need a configured connection.

## Contents

- [Two meanings of async](#two-meanings-of-async)
- [Importing the functions](#importing-the-functions)
- [async() and await()](#async-and-await)
- [delay()](#delay)
- [Several at once: awaitAll() and CompositeFuture](#several-at-once-awaitall-and-compositefuture)
- [syncRun()](#syncrun)
- [The Future contract](#the-future-contract)
- [Tasks](#tasks)
- [Deadlines and cancellation](#deadlines-and-cancellation)
- [HTTP requests](#http-requests)
- [Database queries](#database-queries)
- [Components](#components)
- [A scheduler per request: EnableAsync](#a-scheduler-per-request-enableasync)
- [Async in your own classes: AsyncAware](#async-in-your-own-classes-asyncaware)
- [Errors](#errors)
- [Blocking adapters](#blocking-adapters)
  - [FileFuture](#filefuture)
  - [CacheFuture](#cachefuture)
  - [CacheInvalidator](#cacheinvalidator)
  - [ComponentFuture](#componentfuture)
  - [StreamFuture](#streamfuture)
- [ReactiveState](#reactivestate)
- [EventBroadcaster](#eventbroadcaster)
- [WebSocketFuture (experimental)](#websocketfuture-experimental)
- [Under the hood](#under-the-hood)
- [Measured numbers](#measured-numbers)
- [Common mistakes](#common-mistakes)

---

## Two meanings of async

The word covers two different things, and the difference decides how long your
code takes:

- **Async scheduling.** An operation is a value (a *Future*) that the runtime can
  hold, pass around, combine and await. Everything in this guide gets this.
- **Non-blocking I/O.** While the operation waits, the process does other work.
  Only some operations get this.

| Operation | Future type | Overlaps with other work? |
|---|---|---|
| HTTP request (`Http::getAsync()` and the rest) | `HttpFuture` | **Yes.** Requests run together in one curl multi handle. |
| Timer (`delay()`) | `TimerFuture` | **Yes.** It is a deadline the event loop wakes up for. |
| Your own code (`async()`) | `Task` | Only while it is awaiting something that overlaps. |
| Database query (`getAsync()`, `firstAsync()`, …) | `QueryFuture` | **No.** PDO blocks until the server answers. |
| File, cache, component, stream adapters | `FileFuture`, `CacheFuture`, … | **No.** They run, blocking, when they are awaited. |

In plain PHP (PHP-FPM, `php -S`, the CLI) this is literally true. SFPHP needs
no extension such as Swoole or RoadRunner for HTTP requests and timers to
overlap. It also does not make a blocking call non-blocking by running it inside
a Fiber: three queries awaited together take as long as the three added up.

The runtime is single-threaded. It never runs two pieces of PHP at the same
moment. What overlaps is *waiting*: while one request waits for its response,
another request's bytes can arrive.

## Importing the functions

The four helpers are namespaced functions. Import them with `use function`:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, awaitAll};
```

`syncRun` lives in the same namespace, and you can add it to the same list.
Classes are imported the usual way:

```php
<?php

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;
```

## async() and await()

`await($future)` waits for a Future and returns its value, or throws what it was
rejected with. `async($callable)` runs your callable as a **Task** next to
whatever else is running, and returns the Task, which is itself a Future.

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

$task = async(function (): ?array {
    $response = await(Http::getAsync('https://api.example.com/users/1'));

    return $response->json();
});

$user = await($task);
```

How `await()` waits depends on where it is called:

- **Inside a Task** it parks the Task's Fiber. The scheduler resumes it once the
  awaited Future has settled, and runs other Tasks in the meantime.
- **Outside a Task** (in a controller, a command or a test) it drives the event
  loop itself until the Future settles. Every other pending operation keeps
  progressing while it waits.

In both cases the process never polls. When nothing is ready to run, it waits in
a single `select()` over every outstanding HTTP transfer and the nearest timer.

`await()` works anywhere. No setup or middleware is needed, because the runtime
creates a scheduler for the process the first time one is needed (see
[EnableAsync](#a-scheduler-per-request-enableasync) for a per-request one).

**A Task starts when something drives the scheduler, not on creation.**
`async()` queues the Task. The Task runs the next time any `await()` (for
anything) gives the scheduler a turn. A Task that nothing ever awaits, in a
program that never awaits anything, never runs.

**A Task's value is what the callable returns, as it is.** If the callable
returns a Future, the Task resolves to that Future and not to its value. Await
it inside the callable:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

// Wrong: $wrong is an HttpFuture, not a response.
$wrong = await(async(fn () => Http::getAsync('https://api.example.com/a')));

// Right: the Task awaits the request and returns the response.
$right = await(async(fn () => await(Http::getAsync('https://api.example.com/a'))));
```

In most cases you don't need `async()` at all. An HTTP Future is already
running, so awaiting it directly is enough. Use `async()` when you have several
steps of your own that should run next to other work, such as "fetch, then fetch
again with the result".

## delay()

`delay($milliseconds, $value = null)` returns a Future that resolves with
`$value` after the delay. It is an event-loop timer, not `usleep()`: other Tasks
and HTTP transfers keep progressing while it runs.

```php
<?php

use SfphpProject\src\Async\CompositeFuture;

use function SfphpProject\src\Async\{await, delay};

$started = microtime(true);

// Three delays of 250 ms awaited together take about 250 ms, not 750.
await(CompositeFuture::all(delay(250), delay(250), delay(250)));

$value = await(delay(10, 'done')); // 'done'

printf("%.0f ms\n", (microtime(true) - $started) * 1000);
```

## Several at once: awaitAll() and CompositeFuture

`CompositeFuture` combines Futures. It has exactly two modes:

- `CompositeFuture::all(...$futures)` resolves when every part has, with the
  values in the order given. It rejects as soon as one part fails, with that
  part's exception.
- `CompositeFuture::race(...$futures)` settles with the first part to settle. If
  that part failed, the race rejects.

`awaitAll(...$futures)` is shorthand for `await(CompositeFuture::all(...))`.

```php
<?php

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

[$invoice, $product, $stock] = awaitAll(
    Http::getAsync('https://api.example.com/invoices/7'),
    Http::getAsync('https://api.example.com/products/42'),
    Http::getAsync('https://api.example.com/stock/42'),
);

$fastest = await(CompositeFuture::race(
    Http::getAsync('https://api.example.com/primary'),
    Http::getAsync('https://api.example.com/mirror'),
));

echo $invoice->status(), ' ', $fastest->status(), PHP_EOL;
```

The three requests overlap, so this takes about as long as the slowest one.

The parts decide whether anything overlaps. `CompositeFuture` only listens. It
does not start or drive anything. Three `Http::getAsync()` calls overlap because
each one was already in the event loop. Three queries passed to `all()` still
run one after another.

When `all()` rejects or `race()` settles, the other parts are **not** cancelled.
They belong to whoever created them, and an HTTP request that lost a race still
runs to completion in the loop. To stop the other parts, cancel the composite
itself. `CompositeFuture::cancel()` cancels every part that is still pending and
can be cancelled.

`all()` with no parts resolves at once to `[]`.

## syncRun()

`syncRun($future)` awaits a Future on a new scheduler of its own, then removes
that scheduler again. It gives an entry point, such as a console command or a
queue job, a clean scheduler that nothing else shares:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, syncRun};

$result = syncRun(async(function (): string {
    await(delay(50));

    return 'finished';
}));
```

A Future created *before* the call is attached to the event loop that was
current when it was created. Create the work inside the callable, as above, so
that it belongs to the scheduler `syncRun()` drives.

## The Future contract

Every Future implements `SfphpProject\src\Async\Future`:

| Method | Meaning |
|---|---|
| `isPending()` | Has not settled yet. |
| `isResolved()` | Settled with a value. |
| `isRejected()` | Settled with an exception. |
| `getValue()` | The value. Throws the exception if it was rejected. |
| `getException()` | The exception, or `null`. |
| `onResolve(callable $cb)` | Calls `$cb($future)` when it settles, or at once if it already has. |

The runtime's own Futures (`Task`, `HttpFuture`, `TimerFuture`,
`CompositeFuture`, `QueryFuture`) extend `Pending`, which adds:

| Method | Meaning |
|---|---|
| `isCancelled()` | Was cancelled. A cancelled Future is not "rejected". |
| `isSettled()` | Resolved, rejected or cancelled. |
| `state()` | `'pending'`, `'running'`, `'resolved'`, `'rejected'` or `'cancelled'`. |

Three rules:

1. **Settled is final.** Once a Future has settled it never changes again.
2. **Reading too early throws.** `getValue()` on a `Pending` that has not
   settled throws `AsyncException` ("This operation has not finished. Await it
   before reading its value."). It does not return `null`. Use `await()`.
3. **A cancelled Future throws when read.** `getValue()` throws the cancellation
   reason: a `CancelledException`, or whatever reason was given.

```php
<?php

use SfphpProject\src\Async\AsyncException;

use function SfphpProject\src\Async\{async, await};

$task = async(fn (): int => 42);

try {
    $task->getValue();                 // Not run yet
} catch (AsyncException $e) {
    echo $e->getMessage(), PHP_EOL;
}

echo await($task), PHP_EOL;            // 42
echo $task->getValue(), PHP_EOL;       // 42, now that it has settled
```

Callbacks passed to `onResolve()` run synchronously when the Future settles. An
exception thrown in one is not swallowed. It propagates, because hiding it would
leave a Task that is never resumed.

## Tasks

A `Task` wraps a Fiber. It is `pending` until the scheduler first reaches it,
`running` while its Fiber is alive, and then `resolved`, `rejected` or
`cancelled`.

- An exception thrown inside the callable rejects the Task. `await()` rethrows
  it.
- `Fiber::suspend()` inside a Task (without awaiting anything) yields to the
  other ready Tasks. The Task goes to the back of the queue.
- `await()` on a Task schedules it if it is not already scheduled.

```php
<?php

use function SfphpProject\src\Async\{async, await};

$outer = async(function (): int {
    $inner = async(fn (): int => 20);

    return await($inner) + 1;
});

echo await($outer), PHP_EOL; // 21
```

## Deadlines and cancellation

`await()` takes a timeout in milliseconds:

```php
<?php

use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

try {
    $response = await(Http::getAsync('https://api.example.com/slow'), timeout: 2000);
} catch (TimeoutException $e) {
    echo $e->getMessage(), PHP_EOL; // "The operation did not finish within 2000 ms."
}
```

The deadline is a timer in the event loop. When it fires, the awaited Future is
**cancelled** and `await()` throws `TimeoutException`.

**Only Futures that implement `Cancellable` honour a timeout.** Those are:

| Future | What cancelling does |
|---|---|
| `HttpFuture` | Removes the transfer from the curl multi handle and closes the connection. |
| `TimerFuture` (`delay()`) | Removes the timer. |
| `Task` | Settles the Task as cancelled. Its Fiber is never resumed, so its code stops at the `await()` it is parked on. |
| `CompositeFuture` | Cancels every part that is still pending and cancellable, then itself. |

A Future that is not `Cancellable` ignores the timeout. This covers
`QueryFuture` and the [blocking adapters](#blocking-adapters). `await()` waits
for it to settle, exactly as it would without a timeout, and no
`TimeoutException` is thrown. For a query this cannot be otherwise: once PDO
has sent it, the process is blocked until the server answers.

A timeout on a `Task` cancels the Task, not the work inside it. If a Task is
parked on an HTTP request when its deadline passes, the request keeps running in
the loop. To bound the request itself, put the timeout on the request:

```php
<?php

use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

$task = async(function (): string {
    try {
        return await(Http::getAsync('https://api.example.com/slow'), 200)->body();
    } catch (TimeoutException) {
        return 'fallback';
    }
});

echo await($task), PHP_EOL;
```

You can also cancel explicitly. The optional argument is the reason that
awaiting code receives:

```php
<?php

use SfphpProject\src\Async\CancelledException;

use function SfphpProject\src\Async\{async, await, delay};

$task = async(function (): string {
    await(delay(1000));

    return 'never returned';
});

$task->cancel(new CancelledException('The user left.'));

try {
    await($task);
} catch (CancelledException $e) {
    echo $e->getMessage(), PHP_EOL;           // "The user left."
}

var_dump($task->isCancelled());               // bool(true)
```

## HTTP requests

HTTP is where the runtime overlaps real work. Each call hands a transfer to the
event loop's curl multi handle and returns an `HttpFuture` at once.

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

$get    = Http::getAsync('https://api.example.com/users', ['page' => 2], ['Accept' => 'application/json']);
$post   = Http::postAsync('https://api.example.com/users', ['name' => 'Ana']);
$put    = Http::putAsync('https://api.example.com/users/1', ['name' => 'Ana Maria']);
$patch  = Http::patchAsync('https://api.example.com/users/1', ['active' => true]);
$delete = Http::deleteAsync('https://api.example.com/users/1');

foreach ([$get, $post, $put, $patch, $delete] as $future) {
    echo await($future)->status(), PHP_EOL;
}
```

The signatures are:

| Method | Arguments |
|---|---|
| `Http::getAsync` | `string $url, array $query = [], array $headers = []` |
| `Http::postAsync` / `putAsync` / `patchAsync` | `string $url, array\|string\|null $body = null, array $headers = []` |
| `Http::deleteAsync` | `string $url, array $headers = []` |

An array body is sent as JSON with `Content-Type: application/json`. A string
body is sent as it is. Your own headers win over those defaults.

`HttpFuture` has matching static constructors: `HttpFuture::get($url,
$headers, $options)`, `post($url, $body, $headers, $options)`, and `put`,
`patch`, `delete`. The last argument takes raw cURL options (`CURLOPT_*`), which
win over the defaults.

**Things the async client does not share with the synchronous one.** The
`*Async` methods create a fresh request each time. They do not use `Http::base()`,
`withToken()` or the other configuration of `Http::client()`. So:

- **Use absolute URLs.** `Http::getAsync('/users')` has no host and rejects with
  `ClientException` ("URL rejected: No host part in the URL").
- **Pass authentication as a header:** `['Authorization' => 'Bearer ' . $token]`.
- The defaults are fixed: 30 s total, 10 s to connect, up to 5 redirects,
  HTTPS-only redirects (a redirect from `https://` to `http://` is refused).
  Change them per request through `$options` on `HttpFuture`, or bound the wait
  with `await(..., timeout: $ms)`.

**When the request progresses.** The transfer is registered when the Future is
created. It moves forward whenever the process waits in the event loop, which
means inside any `await()`. Blocking work done between creating the Future and
awaiting it (a query, a `usleep()`, heavy computation) does not overlap with the
request. Start requests first, then await:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\awaitAll;

// All three are registered before anything waits, so they overlap.
$futures = [];

foreach ([1, 2, 3] as $id) {
    $futures[] = Http::getAsync('https://api.example.com/users/' . $id);
}

$responses = awaitAll(...$futures);
```

**The response.** An `HttpFuture` resolves to a `ClientResponse`, the same class
the synchronous client returns:

| Method | Returns |
|---|---|
| `status()` | The status code (`int`). |
| `ok()` | True for 2xx. |
| `failed()` | True for 4xx and 5xx. |
| `clientError()` / `serverError()` | True for 4xx / 5xx. |
| `body()` | The raw body (`string`). |
| `json(bool $strict = false)` | The decoded body as an array, or `null` when it is not JSON. With `true`, throws `ClientException` instead of returning `null`. |
| `header(string $name)` | One header, looked up case-insensitively, or `null`. |
| `headers()` | Every header. |
| `url()` | The URL that answered, after redirects. |
| `throw()` | Throws `ClientException` for 4xx/5xx, otherwise returns the response. |

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

$response = await(Http::getAsync('https://api.example.com/users/1'));

if ($response->ok()) {
    $user = $response->json();
    $type = $response->header('Content-Type');
}

// Or treat an error status as an exception:
$user = await(Http::getAsync('https://api.example.com/users/1'))->throw()->json();
```

**Failures.** An error *status* (404, 500) is an answer: the Future resolves and
you inspect `status()`. No answer at all (DNS failure, refused connection,
timeout, TLS failure) rejects the Future with `SfphpProject\src\Http\ClientException`.
The `curl` extension is required. Without it, the Future rejects at once with a
`ClientException` that says so.

## Database queries

`ModelQuery` has four async methods:

| Method | Resolves to |
|---|---|
| `getAsync()` | `array` of models |
| `firstAsync()` | a model or `null` |
| `countAsync()` | `int` |
| `findAsync($id)` | a model or `null` |

```php
<?php

use SfphpProject\app\models\User;

use function SfphpProject\src\Async\await;

$active = await(User::query()->where('active', true)->getAsync());
$first  = await(User::query()->orderBy('id')->firstAsync());
$count  = await(User::query()->countAsync());
$one    = await(User::query()->findAsync(1));
```

Use `firstAsync()`, not `first()->...`. `first()` runs the query and returns the
model, so it cannot be awaited.

**These block.** A `QueryFuture` runs its query from the event loop, through PDO,
and PDO has no asynchronous API: `execute()` waits for the server, and no Fiber
changes that. Awaiting three queries together takes as long as the three in a
row. What you get is the Future *shape*: the query is a value you can pass
around, combine with `all()` and await like everything else. Creating a
`QueryFuture` costs nothing. The query runs the first time the loop turns, and a
query that is never awaited might never run.

There is a real benefit when a query is mixed with HTTP. Start the requests
first, then await the query. The requests progress while the loop is waiting,
though not while PDO is blocked:

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

$weather = Http::getAsync('https://api.example.com/weather');
$news    = Http::getAsync('https://api.example.com/news');

$user = await(User::query()->findAsync(1));   // Blocks while the query runs

[$weatherResponse, $newsResponse] = awaitAll($weather, $news);
```

A `QueryFuture` is not `Cancellable`, so a timeout does not stop it (see
[Deadlines and cancellation](#deadlines-and-cancellation)).

## Components

A `.phpx` component is a function, so it can call `await()`:

```php
function UserPanel(string $url): Sfht
{
    $data = await(Http::getAsync($url))->json();

    return sfht(
        <div class="card"><p>{{ $data['name'] ?? '' }}</p></div>
    );
}
```

The framework calls components one after another, not as Tasks. A component
that awaits a request therefore waits for it before the next component is
called. For two components' requests to overlap, the requests must both be in
flight before either is awaited. Either start them earlier (in the controller)
and pass the Futures or results down, or render each component inside
`async()` and await both Tasks together:

```php
[$left, $right] = awaitAll(
    async(fn () => UserPanel('https://api.example.com/users/1')),
    async(fn () => UserPanel('https://api.example.com/users/2')),
);
```

## A scheduler per request: EnableAsync

The async functions work without any setup. The first time they need a
scheduler they create one for the whole process, the *root* scheduler.

`SfphpProject\src\Http\Middleware\EnableAsync` is optional. It gives each request
its own scheduler, pushed when the request enters the pipeline and removed when
the response leaves it. Nothing a request leaves pending (a forgotten Task, a
request nobody awaited) can then carry over into the next request, which
matters when one worker serves many requests. To use it, add it to the
middleware list in `public/index.php`:

```php
$router = (new Router($container))->middleware(
    new LogRequests(),
    new EnableAsync(),
    new SecurityHeaders(),
    // ...
);
```

Work still pending when the response is returned is abandoned with its
scheduler, not finished later. Await what you start.

## Async in your own classes: AsyncAware

The `SfphpProject\src\Async\AsyncAware` trait gives a class three protected
helpers:

- `withAsync(callable $fn)`: runs `$fn` as a Task on a scheduler of its own
  (through `syncRun()`) and returns its result.
- `isAsyncEnabled()`: whether a scheduler has been pushed or created yet.
- `getScheduler()`: that scheduler, or `null`.

```php
<?php

use SfphpProject\src\Async\AsyncAware;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

final class ProfileService
{
    use AsyncAware;

    public function load(int $id): array
    {
        return $this->withAsync(function () use ($id): array {
            [$user, $posts] = awaitAll(
                Http::getAsync('https://api.example.com/users/' . $id),
                Http::getAsync('https://api.example.com/users/' . $id . '/posts'),
            );

            return ['user' => $user->json(), 'posts' => $posts->json()];
        });
    }
}

$profile = (new ProfileService())->load(1);
```

## Errors

Every exception the runtime raises for its own reasons extends
`SfphpProject\src\Async\AsyncException`, which extends `RuntimeException`:

| Exception | When |
|---|---|
| `AsyncException` | Reading a Future that has not settled. A deadlock (below). Popping a scheduler that is not there. `Context::getScheduler()` with no scheduler ("No active Scheduler"). |
| `TimeoutException` | `await($future, $timeout)` on a `Cancellable` that did not settle in time. |
| `CancelledException` | Reading or awaiting a Future cancelled without an explicit reason. |

The error the work itself raised is passed through unchanged. A Task that
throws `DomainException` makes `await()` throw that `DomainException`. A failed
HTTP transfer throws `SfphpProject\src\Http\ClientException`.

**Deadlock.** When every Task is parked on something that nothing is going to
settle, and no transfer or timer is pending, the scheduler stops with:

```
Deadlock: 1 task(s) are waiting and nothing is pending that could wake them.
```

The stuck Tasks are cancelled with that same exception, so a later, unrelated
`await()` is not blamed for them. Awaiting such a Future from outside a Task
reports:

```
Deadlock: the awaited operation is still pending and nothing is scheduled that could settle it.
```

This only happens with Futures you build yourself, such as a subclass of
`Pending` that never settles. The framework's Futures always settle.

`SfphpProject\src\Async\Exceptions.php` is kept only so that code which
required it still loads. The three classes each have their own file and are
autoloaded.

## Blocking adapters

These classes implement `Future` but not `Pending`. They predate the current
runtime and work the old way: **nothing happens until the value is read, and
then it runs, blocking.** `await()` on one of them simply calls `getValue()`.
They add the Future shape, not concurrency, and none of them is `Cancellable`,
so a timeout does not affect them.

### FileFuture

`SfphpProject\src\Async\FileFuture` wraps one file operation:

| Factory | Resolves to |
|---|---|
| `FileFuture::read($path)` | The contents. Rejects when the file is missing. |
| `FileFuture::write($path, $content)` | Bytes written. |
| `FileFuture::append($path, $content)` | Bytes written. |
| `FileFuture::delete($path)` | `true`, or `false` when the file did not exist. |
| `FileFuture::copy($from, $to)` / `move($from, $to)` | `true`. |
| `FileFuture::exists($path)` | `bool`. |
| `FileFuture::size($path)` | Bytes, or `0` when missing. |
| `FileFuture::mkdir($path, ['mode' => 0755, 'recursive' => true])` | `true`. |
| `FileFuture::scan($path)` | Entries without `.` and `..`, **keyed as `scandir()` left them** (use `array_values()` for a list). |

```php
<?php

use SfphpProject\src\Async\FileFuture;

use function SfphpProject\src\Async\await;

$path = sys_get_temp_dir() . '/sfphp-report.txt';

await(FileFuture::write($path, "first line\n"));
await(FileFuture::append($path, "second line\n"));

echo await(FileFuture::read($path));
echo await(FileFuture::size($path)), " bytes\n";

await(FileFuture::delete($path));
```

Pass the Future itself to `await()`. `await(FileFuture::read($path)->getValue())`
passes a string and fails with a `TypeError`.

### CacheFuture

`SfphpProject\src\Async\Adapters\CacheFuture` wraps one cache operation. The
driver is the framework cache: the `cache()` helper, a `CacheManager` or any
`SfphpProject\src\Cache\Cache` driver. Its `put()` and `forget()` are used. An
object that has `set()`/`delete()` instead (PSR-16 style) also works.

| Factory | Does |
|---|---|
| `CacheFuture::get($key, $cache)` | Reads (resolves to `null` when missing). |
| `CacheFuture::set($key, $value, $ttl = 3600, $cache)` | Stores. A TTL of `0` or less means no expiry. Resolves to `true`. |
| `CacheFuture::delete($key, $cache)` | Removes. Resolves to `true`. |
| `CacheFuture::has($key, $cache)` | `bool`. |
| `CacheFuture::increment($key, $by = 1, $cache)` | The new value, atomically. |
| `CacheFuture::decrement($key, $by = 1, $cache)` | The new value. Drivers without `decrement()` get `increment(-$by)`. |

```php
<?php

use SfphpProject\src\Async\Adapters\CacheFuture;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

use function SfphpProject\src\Async\await;

$cache = new CacheManager(new MemoryDriver()); // In an application: cache()

await(CacheFuture::set('greeting', 'hello', 60, $cache));
echo await(CacheFuture::get('greeting', $cache)), PHP_EOL;   // hello
echo await(CacheFuture::increment('visits', 1, $cache)), PHP_EOL;
await(CacheFuture::delete('greeting', $cache));
```

Because each operation blocks, the synchronous call is shorter and does the same
thing: `cache()->put('greeting', 'hello', 60)`. The adapter is useful when an
API takes a `Future`.

### CacheInvalidator

`SfphpProject\src\Async\CacheInvalidator` removes a key together with the keys
registered as depending on it. It is not asynchronous. It lives here because it
can follow a `ReactiveState`.

```php
<?php

use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$cache = new CacheManager(new MemoryDriver()); // In an application: cache()

$invalidator = (new CacheInvalidator($cache))
    ->registerDependency('user:1', ['user:1:profile', 'user:1:posts'])
    ->registerDependency('user:1:posts', ['feed:home']);

$invalidator->invalidate('user:1');
// Forgets user:1, user:1:profile, user:1:posts and feed:home.
```

- Dependencies are followed transitively, and each key is visited once, so a
  cycle (`a` depends on `b` and `b` on `a`) is safe.
- `invalidateMany([...])` invalidates several keys.
- `invalidateByPattern('user:1:*')` calls the cache's `deleteByPattern()` if it
  has one. The framework drivers don't, so it falls back to invalidating the
  *registered sources* whose names match (`*` matches any characters). Keys that
  were never registered are not found this way.
- `invalidateOnStateChange($state, $keys)` invalidates `$keys` every time the
  `ReactiveState` changes.
- `createUserInvalidationPattern($id)` and
  `createResourceInvalidationPattern($type, $id)` return conventional key lists
  (`user:1`, `user:1:profile`, …) to pass to `invalidateMany()`.

### ComponentFuture

`SfphpProject\src\Async\ComponentFuture` runs a rendering callable when its
value is read, retrying it on failure:

```php
<?php

use SfphpProject\src\Async\ComponentFuture;

$html = (new ComponentFuture(fn (): string => '<p>Hello</p>', maxRetries: 2))->getValue();

$safe = ComponentFuture::withFallbacks(
    fn (): string => throw new RuntimeException('The service is down.'),
    null,
    fn (Throwable $e): string => '<p class="error">' . htmlspecialchars($e->getMessage()) . '</p>',
    2,
);

echo $safe->getValue(), PHP_EOL;
```

- Retries wait `setRetryDelay($ms)` milliseconds (100 by default) with
  `usleep()`, which blocks the whole process. Keep retries few and short.
- `getRetryCount()` tells how many retries were used.
- `withFallbacks($component, $loadingFallback, $errorFallback, $maxRetries)`:
  when the component still fails after its retries, `$errorFallback($e)`
  provides the result. `$loadingFallback` is accepted and never called, because
  a server-side render has no loading moment.
- The syntax `(new ComponentFuture(...))->getValue()` needs the parentheses on
  PHP 8.1 to 8.3.

### StreamFuture

`SfphpProject\src\Async\StreamFuture` runs a pipeline over an iterable (an
array, a generator or a callable that returns one) chunk by chunk:

```php
<?php

use SfphpProject\src\Async\StreamFuture;

use function SfphpProject\src\Async\await;

$evenTimesTen = (new StreamFuture(range(1, 10), chunkSize: 3))
    ->filter(fn (int $n): bool => $n % 2 === 0)
    ->map(fn (int $n): int => $n * 10);

print_r(await($evenTimesTen));        // [20, 40, 60, 80, 100]

$sum = (new StreamFuture(range(1, 10), 3))
    ->reduce(fn (int $carry, int $n): int => $carry + $n, 0);

echo $sum, PHP_EOL;                    // 55
```

- `pipe(callable $stage)` adds a stage that receives a chunk (an array) and
  returns an array. `filter()` and `map()` are stages built on it.
- The pipeline runs when the value is read, once. The result is every
  processed item in one list.
- `reduce($fn, $initial)` is terminal. It folds every item through the pipeline
  built so far and returns the value directly, not a Future. It does not change
  the pipeline, so `getValue()` still works afterwards.
- `getProcessedCount()` counts the items that came out of the pipeline.

**Memory.** The source is read one chunk at a time. `reduce()` holds only one
chunk and the running value. `getValue()` collects every processed item into its
result, so it needs memory for the whole output.

Three factories:

- `StreamFuture::fromCsv($path, $chunkSize)`: one array per CSV row, read line
  by line. Throws `RuntimeException` when the file cannot be opened.
- `StreamFuture::fromJsonLines($path, $chunkSize)`: one decoded value per line.
  Lines that are not JSON are skipped.
- `StreamFuture::fromQuery($query, $chunkSize)`: rows from a model or query
  builder query. SFPHP queries have no cursor, so all rows are fetched with one
  `get()` when the stream is first read. The chunk size limits how many rows
  each stage handles at once, not how many are in memory.

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Async\StreamFuture;

$names = (new StreamFuture(fn () => User::query()->get(), 500))
    ->map(fn (User $user): string => (string) $user->name)
    ->getValue();
```

## ReactiveState

`SfphpProject\src\Async\ReactiveState` holds one value together with loading
and error flags, and notifies listeners when they change:

```php
<?php

use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Http\Http;

$state = new ReactiveState(initialValue: null, ttl: 300);

$state->onChange(function (ReactiveState $s): void {
    echo $s->hasError() ? 'error: ' . $s->getError()->getMessage() : 'value changed', PHP_EOL;
});

$state->updateFromFuture(Http::getAsync('https://api.example.com/users/1'));

$response = $state->getValue();         // The ClientResponse
$view     = $state->toArray();          // value, loading, error, hasError, expired
```

- `updateFromFuture($future)` **awaits** the Future. On success the value is
  set. On failure the exception is stored with `setError()`. Loading is
  `true` while it waits and is already `false` again when listeners are
  notified.
- `setValue($v)` notifies only when the value is not identical (`!==`) to the
  current one. `setError()`, `reset()` and `setLoading(false)` always notify.
- Listeners are called with the state. An exception thrown by a listener is
  ignored.
- `ttl` is in seconds (`0` means never expire). It counts from the last
  `setValue()`. Once it has passed, `getValue()` returns `null` and
  `isExpired()` is true.
- `addDependency($cacheKey)` and `getDependencies()` record cache keys for your
  own use. To actually invalidate on change, use
  `CacheInvalidator::invalidateOnStateChange()`.
- A state lives for one PHP process, and in PHP-FPM that means one request. It
  is not shared between requests or with the browser.

## EventBroadcaster

`SfphpProject\src\Async\EventBroadcaster` is an in-process publish/subscribe
hub. It is separate from the framework's event dispatcher
(`SfphpProject\src\Events\Dispatcher`) and does not share listeners with it.

```php
<?php

use SfphpProject\src\Async\EventBroadcaster;

use function SfphpProject\src\Async\await;

$events = new EventBroadcaster();

$events->subscribe('user.created', function (string $event, mixed $payload): string {
    return 'welcome mail for ' . $payload['email'];
}, priority: 10);

$events->subscribe('user.*', fn (string $event): string => 'audit: ' . $event);

$report = await($events->broadcast('user.created', ['email' => 'ana@example.com']));
// Or, equivalently: $report = $events->broadcastSync('user.created', [...]);

print_r($report['results']);
```

**Delivery.** `broadcast()` returns a Task. The listeners run inside it, one
after another, in priority order (highest first). Like any Task, it runs when the
scheduler gets a turn, which is at the next `await()`. `broadcastSync()` awaits
it for you and returns the report. The report is:

```
[
    'event'           => 'user.created',
    'listeners_count' => 2,
    'results'         => [listenerId => return value, ...],
    'errors'          => [listenerId => exception message, ...],
    'duration_ms'     => 0.05,
]
```

A listener that throws does not stop the others. Its message goes into
`errors`. Listeners receive `($event, $payload)`.

**Wildcards.** `*` in a subscription stands for one or more characters, dots
included:

| Pattern | Receives | Does not receive |
|---|---|---|
| `user.created` | `user.created` | anything else |
| `user.*` | `user.created`, `user.profile.updated` | `user`, `users.created` |
| `*.created` | `user.created`, `order.created` | `created` |
| `order.*.shipped` | `order.42.shipped` | `order.shipped` |

**Managing listeners.**

- `subscribe()` returns a listener id. `unsubscribe($event, $id)` removes that
  listener, and `unsubscribeAll($event)` removes every listener of that exact
  pattern.
- `getListenerCount($event)` counts the listeners an event would reach,
  wildcards included. Without an argument it counts every listener.
- `getEvents()` lists the subscribed patterns, and `clear()` removes every
  listener.
- `enableHistory(true, $limit)` records each broadcast (`event`, `payload`,
  `timestamp`), keeping the last `$limit`. Read it with `getHistory()` and empty
  it with `clearHistory()`.
- `scope('billing')` returns a broadcaster that shares these listeners and
  history and puts `billing.` in front of every event name it is given.
  `clear()` on a scope removes only that scope's listeners.

```php
<?php

use SfphpProject\src\Async\EventBroadcaster;

$events = new EventBroadcaster();
$billing = $events->scope('billing');

$billing->subscribe('paid', fn (string $event): string => 'heard ' . $event);

$report = $events->broadcastSync('billing.paid');   // Reaches the listener
echo $report['listeners_count'], PHP_EOL;            // 1
```

Everything lives in memory for one process: nothing is sent to other requests,
workers, servers or browsers. To reach browsers, use server-sent events
([STREAMING.md](STREAMING.md)). For work that must survive the request, use the
queue.

## WebSocketFuture (experimental)

`SfphpProject\src\Async\WebSocketFuture` is **experimental and is not a working
WebSocket client.** Do not build features on it.

What it does:

- Opens a plain TCP connection to the URL's host and port (80 when the URL has
  none). It settles once the connection is open or has failed.
- `send($text)` writes one masked text frame. `queueMessage()` and
  `flushQueue()` batch sends. `disconnect()` closes the socket.

What it does not do:

- **No opening handshake.** It never sends the HTTP `Upgrade: websocket`
  request, so a real WebSocket server closes the connection. The headers given
  to the constructor are stored and never sent.
- **No TLS.** A `wss://` URL is refused: `connect()` returns `false` and the
  Future is rejected.
- **No receiving.** Nothing reads the socket, so `onMessage()` callbacks are
  never called. There are no ping, pong or close frames.
- **Not on the event loop.** Connecting and writing block the process.

```php
<?php

use SfphpProject\src\Async\WebSocketFuture;

$socket = WebSocketFuture::open('wss://echo.example.com/socket');

var_dump($socket->isConnected());               // bool(false)
echo $socket->getException()->getMessage(), PHP_EOL;
```

`WebSocketFuture::open($url)` constructs and connects. `connect()` is the
instance method and returns `false` on failure instead of throwing. For
real-time updates to a browser, use server-sent events and SFJS `@stream`
([STREAMING.md](STREAMING.md)).

## Under the hood

Three classes do the work. You rarely need them directly.

**`EventLoop`** holds everything the process can wait on: cURL transfers (in
one multi handle), timers, and stream watchers (`addWatcher()`, a seam for
backends that expose a socket, not used by the framework yet). `tick()` is the
only place the process waits, and it waits for all of them at once, for at most
50 ms at a time.

**`Scheduler`** keeps *ready* Tasks, which it runs in turn, and *waiting*
Tasks, which are parked on a Future. A waiting Task is not touched until its
Future settles, and then it moves back to ready. When nothing is ready, the
scheduler lets the loop wait. `stats()` returns
`['ready' => …, 'waiting' => …, 'loop' => ['transfers' => …, 'timers' => …, 'watchers' => …]]`,
which is useful to check that nothing is left pending at the end of a request.

**`Context`** is a stack of schedulers. `Context::scheduler()` returns the top
one and creates the root scheduler when the stack is empty. EnableAsync and
`syncRun()` push and pop their own. `Context::getScheduler()` throws
`AsyncException` ("No active Scheduler") instead of creating one, and
`Context::hasScheduler()` asks without side effects.

```php
<?php

use SfphpProject\src\Async\Context;

use function SfphpProject\src\Async\{await, delay};

await(delay(10));

print_r(Context::scheduler()->stats());
```

## Measured numbers

These numbers come from `benchmarks/async.php`, run against the bundled origin
server, which answers each request after 300 ms from a single non-blocking
process:

```
php benchmarks/origin.php 127.0.0.1:8300 &
php benchmarks/async.php
```

One run on PHP 8.4.24, Linux 6.12, a 16-thread Intel Core i7-12650H and curl
8.14.1:

| Case | Time |
|---|---|
| 1 request | 301.3 ms |
| 3 requests, each awaited before the next starts | 903.6 ms |
| 3 requests, all in flight | 303.1 ms |
| 10 requests, all in flight | 302.2 ms |
| 50 requests, all in flight | 303.8 ms |
| 10 requests through `async()` Tasks | 301.2 ms |
| Overhead of `async()` + `await()` with no I/O | 5.6 µs per Task |
| Peak memory for the whole run | 2.00 MB |

Numbers from one run on one machine describe that machine. Run the benchmark on
your own hardware before relying on them. `benchmarks/database.php` measures the
query side against a MySQL server you point it at (see its header). It shows that
queries awaited together cost about the same as the same queries run one after
another.

## Common mistakes

| Symptom | Cause | Fix |
|---|---|---|
| `Call to undefined function async()` | The function is not imported. | `use function SfphpProject\src\Async\{async, await, delay, awaitAll};` |
| `AsyncException: This operation has not finished…` | `getValue()` on a Future that has not settled. | `await($future)`. |
| `TypeError` from `await()` | A value was passed instead of a Future, such as `await($f->getValue())`. | `await($f)`. |
| `await(async(...))` returns a Future | The callable returned a Future without awaiting it. | `async(fn () => await(...))`, or await the Future directly. |
| Three "parallel" queries take three times as long | Queries block (PDO). | Expected. Only HTTP and timers overlap. |
| A request did not overlap with other work | Blocking work ran between creating the Future and awaiting it. | Create every request first, then await them together. |
| `ClientException: … No host part in the URL` | Relative URL passed to `Http::*Async`. | Use an absolute URL. |
| A timeout did not stop the operation | The Future is not `Cancellable` (query, file, cache, component). | Only HTTP, timers, Tasks and composites honour timeouts. |
| `AsyncException: Deadlock: …` | A Task awaits a Future that nothing will settle. | Settle it, or do not await it. |
| A Task never ran | Nothing awaited anything after it was created. | Await it, or await something else. |
