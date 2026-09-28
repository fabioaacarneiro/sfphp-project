<?php

/*
 * Logs, request ids, metrics and health checks.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Container;
use SfphpProject\src\Health;
use SfphpProject\src\Log\Metrics;
use SfphpProject\src\Log\Level;
use SfphpProject\src\Log\LogManager;
use SfphpProject\src\Log\MemoryDriver as LogMemoryDriver;
use SfphpProject\src\Log\NullDriver as LogNullDriver;
use SfphpProject\src\Log\StreamDriver;
use SfphpProject\src\Http\Middleware\LogRequests;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Router;

$tests->run('log records are structured, ordered by severity and filtered', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver, Level::Warning);

    $log->debug('ignored');
    $log->info('also ignored');
    $log->warning('kept', ['attempt' => 3]);
    $log->error('kept too');

    $tests->assertSame(2, count($driver->records()));
    $tests->assertSame('kept', $driver->records()[0]['message']);
    $tests->assertSame(3, $driver->records()[0]['context']['attempt']);
    $tests->assertSame(Level::Error, $driver->last()['level']);

    // The severity order is the one a human reads, not RFC 5424's inverted one.
    $tests->assertTrue(Level::Error->atLeast(Level::Warning));
    $tests->assertTrue(!Level::Debug->atLeast(Level::Info));

    // A typo in configuration falls back instead of stopping the application.
    $tests->assertSame(Level::Info, Level::fromName('nonsense', Level::Info));
    $tests->assertSame(Level::Warning, Level::fromName('WARNING'));
});

$tests->run('the shared context reaches every record and can be dropped', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);

    $log->withContext(['request_id' => 'abc123']);
    $log->info('first');
    $log->info('second', ['order_id' => 7]);

    $tests->assertSame('abc123', $driver->records()[0]['context']['request_id']);
    $tests->assertSame('abc123', $driver->records()[1]['context']['request_id']);
    $tests->assertSame(7, $driver->records()[1]['context']['order_id']);

    /*
     * The reset is what keeps one visitor's id off the next visitor's logs in a
     * worker that serves many requests.
     */
    $log->forgetContext();
    $log->info('third');
    $tests->assertSame([], $driver->last()['context']);
});

$tests->run('secrets are redacted before a record is written', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);

    $log->info('login attempt', [
        'email' => 'ana@example.com',
        'password' => 'hunter2',
        'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
        'card' => ['card_number' => '4111111111111111', 'last4' => '1111'],
    ]);

    $context = $driver->last()['context'];

    $tests->assertSame('ana@example.com', $context['email']);
    $tests->assertSame('[redacted]', $context['password']);
    $tests->assertSame('[redacted]', $context['headers']['Authorization']);
    $tests->assertSame('application/json', $context['headers']['Accept']);
    $tests->assertSame('[redacted]', $context['card']['card_number']);
    $tests->assertSame('1111', $context['card']['last4']);

    // An application can name more keys of its own.
    $log->redact('pin')->info('x', ['pin' => '0000']);
    $tests->assertSame('[redacted]', $driver->last()['context']['pin']);
});

$tests->run('a stream record is one line of json, in UTC', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-log-' . bin2hex(random_bytes(6)) . '/app.log';
    $driver = new StreamDriver($path);

    $driver->write(Level::Error, 'algo quebrou', ['país' => 'Brasil']);
    $driver->write(Level::Info, 'segunda linha');
    $driver->close();

    $lines = array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($path))));
    $tests->assertSame(2, count($lines));

    $record = json_decode($lines[0], true);
    $tests->assertSame('error', $record['level']);
    $tests->assertSame('algo quebrou', $record['message']);
    $tests->assertSame('Brasil', $record['context']['país']);

    // UTF-8 survives: a log that mangles the message is worse than no log.
    $tests->assertTrue(str_contains($lines[0], 'país'));

    // The timestamp is UTC and sorts as a string, which is what makes lines
    // from several machines orderable.
    $tests->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $record['timestamp']) === 1);

    // A record with no context does not carry an empty object.
    $tests->assertTrue(!array_key_exists('context', json_decode($lines[1], true)));

    unlink($path);
    @rmdir(dirname($path));
});

$tests->run('a value that cannot be encoded degrades instead of throwing', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-log-' . bin2hex(random_bytes(6)) . '/app.log';
    $driver = new StreamDriver($path);

    $recursive = ['self' => null];
    $recursive['self'] = &$recursive;

    $driver->write(Level::Error, 'still written', ['handle' => fopen('php://memory', 'r')]);
    $driver->close();

    $record = json_decode(trim((string) file_get_contents($path)), true);
    $tests->assertSame('still written', $record['message']);

    unlink($path);
    @rmdir(dirname($path));
});

$tests->run('every request gets an id that reaches the logs and the response', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);
    $middleware = new LogRequests($log);

    $seen = null;
    $destination = static function (Request $request) use (&$seen): Response {
        $seen = $request->attribute('request_id');

        return Response::text('ok');
    };

    $response = $middleware->handle(Request::create('GET', '/orders'), $destination);

    $id = $response->header('X-Request-Id');
    $tests->assertTrue($id !== null && strlen($id) === 32);

    // The same id is on the request, in the response header, and on the record.
    $tests->assertSame($id, $seen);
    $tests->assertSame($id, $driver->last()['context']['request_id']);
    $tests->assertSame('request handled', $driver->last()['message']);
    $tests->assertSame(HTTP_OK, $driver->last()['context']['status']);
    $tests->assertSame('/orders', $driver->last()['context']['path']);
    $tests->assertTrue($driver->last()['context']['duration_ms'] >= 0);
});

$tests->run('an inbound request id is honoured only when it is usable', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $middleware = new LogRequests(new LogManager($driver));
    $destination = static fn (Request $request): Response => Response::text('ok');

    // A trace coming from another service continues here.
    $traced = Request::create('GET', '/', ['headers' => ['X-Request-Id' => 'trace-0001']]);
    $tests->assertSame('trace-0001', $middleware->handle($traced, $destination)->header('X-Request-Id'));

    /*
     * Client-controlled input goes straight into the logs, so an id that is too
     * long, or carries characters a log viewer would act on, is replaced rather
     * than believed.
     */
    foreach (["injected\nline", str_repeat('a', 129), 'has spaces', '<script>'] as $hostile) {
        $refused = Request::create('GET', '/', ['headers' => ['X-Request-Id' => $hostile]]);
        $assigned = $middleware->handle($refused, $destination)->header('X-Request-Id');

        $tests->assertTrue($assigned !== $hostile);
        $tests->assertSame(32, strlen($assigned));
    }
});

$tests->run('a failing request is reported once, with its id, by the router', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = logger()->driver($driver)->forgetContext();

    Router::reset();
    Router::get('/boom', [DispatchTestController::class, 'boom']);

    $router = (new Router(new Container()))->middleware(new LogRequests($log));
    $response = $router->dispatch(Request::create('GET', '/boom'));

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $response->status());

    /*
     * The failure is written once, not twice. The router's boundary reports it
     * and LogRequests deliberately does not, so that both being present cannot
     * produce a duplicate.
     */
    $failures = array_values(array_filter(
        $driver->records(),
        static fn (array $record): bool => isset($record['context']['exception'])
    ));
    $tests->assertSame(1, count($failures));

    $record = $failures[0];
    $tests->assertSame(Level::Error, $record['level']);
    $tests->assertSame('/boom', $record['context']['path']);
    $tests->assertTrue(isset($record['context']['line']));

    /*
     * An exception skips the rest of the pipeline, so the id has to reach both
     * the record and the response some other way. The visitor who sees the 500
     * is the person most in need of it.
     */
    $id = $record['context']['request_id'];
    $tests->assertSame(32, strlen($id));
    $tests->assertSame($id, $response->header('X-Request-Id'));

    logger()->driver(new LogNullDriver())->forgetContext();
});

$tests->run('metrics count and time, including the call that failed', function () use ($tests): void {
    Metrics::reset();

    Metrics::count('orders.placed');
    Metrics::count('orders.placed');
    Metrics::count('payments.failed', ['gateway' => 'stripe']);

    $snapshot = Metrics::snapshot();
    $tests->assertSame(2.0, $snapshot['counters']['orders.placed']['value']);
    $tests->assertSame(['gateway' => 'stripe'], $snapshot['counters']['payments.failed|{"gateway":"stripe"}']['labels']);

    // time() returns what the work returned.
    $tests->assertSame('done', Metrics::time('work', static fn (): string => 'done'));

    /*
     * And records the failed call too: something that only gets slow when it is
     * failing is exactly the thing worth seeing.
     */
    try {
        Metrics::time('work', static function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $tests->assertSame(2, Metrics::snapshot()['timers']['work']['count']);

    // The scraper format escapes what would end a label early.
    Metrics::reset();
    Metrics::count('a.metric', ['label' => 'with "quote"']);
    $tests->assertSame(true, str_contains(Metrics::prometheus(), 'with \"quote\"'));
    $tests->assertSame(true, str_starts_with(Metrics::prometheus(), "# TYPE a_metric counter\na_metric{label="));

    Metrics::reset();
    $tests->assertSame('', Metrics::prometheus());
});

$tests->run('a health check reports each dependency and times it', function () use ($tests): void {
    Health::forget();

    Health::register('fine', static fn (): bool => true);
    $report = Health::check();
    $tests->assertSame(true, $report['healthy']);
    $tests->assertSame(true, $report['checks']['fine']['ok']);
    $tests->assertSame(true, $report['checks']['fine']['ms'] >= 0);

    // One failing dependency makes the instance unhealthy.
    Health::register('broken', static fn (): bool => false);
    $tests->assertSame(false, Health::check()['healthy']);

    /*
     * A check that throws is a failed check, and the message is reported so
     * whoever reads it knows which dependency — but the trace is not, because
     * this response may leave the deployment.
     */
    Health::forget();
    Health::register('throws', static function (): bool {
        throw new RuntimeException('connection refused');
    });

    $report = Health::check();
    $tests->assertSame(false, $report['healthy']);
    $tests->assertSame('connection refused', $report['checks']['throws']['error']);

    // A subset can be asked for, which is how a liveness probe differs from a
    // readiness one.
    Health::forget();
    Health::register('a', static fn (): bool => true);
    Health::register('b', static fn (): bool => false);
    $tests->assertSame(['a'], array_keys(Health::check(['a'])['checks']));
    $tests->assertSame(true, Health::check(['a'])['healthy']);

    Health::forget();
});
