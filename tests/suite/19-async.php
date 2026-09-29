<?php

/*
 * The async runtime.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\Http;

$tests->run('the async runtime keeps several requests in flight at once', function () use ($tests): void {
    /*
     * The claim under test is the whole point of the runtime, and it is not
     * observable from unit assertions: three requests that each take 300 ms
     * either finish in about 300 ms or in about 900, and only a clock and a
     * real socket can say which.
     *
     * The origin is the non-blocking one in benchmarks/, on purpose. PHP's
     * built-in server answers from a pool of worker processes, so measuring
     * against it measures the pool — three 300 ms requests took 600 ms there
     * while the client was already perfectly concurrent.
     */
    if (!extension_loaded('curl')) {
        return;
    }

    $port = 8400 + (getmypid() % 500);
    $origin = dirname(dirname(__DIR__)) . '/benchmarks/origin.php';
    $command = sprintf(
        '%s %s 127.0.0.1:%d > /dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($origin),
        $port
    );

    $pid = (int) trim((string) shell_exec($command));
    $base = 'http://127.0.0.1:' . $port;

    try {
        // Wait for the socket, rather than guessing how long it takes to bind.
        $listening = false;

        for ($attempt = 0; $attempt < 150; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);

            if ($probe !== false) {
                fclose($probe);
                $listening = true;
                break;
            }

            usleep(20_000);
        }

        /*
         * No origin, nothing to measure. A port that was taken or a process
         * that could not start says nothing about whether transfers overlap,
         * and a test that reports the machine as a defect in the framework is
         * one people learn to ignore.
         */
        if (!$listening) {
            return;
        }

        $url = $base . '/delay?ms=300';

        $startedAt = microtime(true);
        $first = Http::getAsync($url);
        $second = Http::getAsync($url);
        $third = Http::getAsync($url);

        $statuses = [
            SfphpProject\src\Async\await($first)->status(),
            SfphpProject\src\Async\await($second)->status(),
            SfphpProject\src\Async\await($third)->status(),
        ];
        $elapsed = microtime(true) - $startedAt;

        $tests->assertSame([200, 200, 200], $statuses);

        /*
         * Sequential would be 900 ms. The bound is generous because a loaded
         * machine is still a machine, but it is nowhere near 900: this fails
         * the moment the transfers stop overlapping.
         */
        $tests->assertTrue($elapsed < 0.6);

        // A response is the framework's own, so sync and async agree on shape.
        $tests->assertSame(300, SfphpProject\src\Async\await(Http::getAsync($url))->json()['slept_ms']);

        // A deadline is honoured, and arrives as the exception it promises.
        $startedAt = microtime(true);
        $tests->assertThrows(
            static fn () => SfphpProject\src\Async\await(Http::getAsync($base . '/delay?ms=3000'), 300),
            SfphpProject\src\Async\TimeoutException::class
        );
        $tests->assertTrue(microtime(true) - $startedAt < 1.0);

        // A refused connection is a failure, not an empty response.
        $tests->assertThrows(
            static fn () => SfphpProject\src\Async\await(Http::getAsync('http://127.0.0.1:9/nothing')),
            ClientException::class
        );
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});

$tests->run('a task that awaits gets its value, and the scheduler waits for nobody', function () use ($tests): void {
    /*
     * Both halves used to be broken. await() inside a Task registered a
     * callback that resumed the Fiber from whatever stack settled the Future,
     * and the scheduler resumed every Task on every pass whether or not it was
     * waiting for anything — so this returned null, silently, and the work was
     * never done.
     */
    $task = SfphpProject\src\Async\async(static function (): int {
        $inner = SfphpProject\src\Async\async(static fn (): int => 20);

        return SfphpProject\src\Async\await($inner) + 1;
    });

    $tests->assertSame(21, SfphpProject\src\Async\await($task));

    // Timers are the loop's, not usleep()'s: three of them overlap.
    $startedAt = microtime(true);
    SfphpProject\src\Async\await(SfphpProject\src\Async\CompositeFuture::all(
        SfphpProject\src\Async\delay(150),
        SfphpProject\src\Async\delay(150),
        SfphpProject\src\Async\delay(150)
    ));
    $elapsed = microtime(true) - $startedAt;

    $tests->assertTrue($elapsed >= 0.14 && $elapsed < 0.4);

    // A value read before it exists is an error, not null.
    $pending = new SfphpProject\src\Async\Task(static fn (): int => 1);
    $tests->assertThrows(
        static fn () => $pending->getValue(),
        SfphpProject\src\Async\AsyncException::class
    );

    // A rejected task carries its exception to whoever awaits it.
    $failing = SfphpProject\src\Async\async(static function (): void {
        throw new RuntimeException('from inside the fiber');
    });
    $tests->assertThrows(
        static fn () => SfphpProject\src\Async\await($failing),
        RuntimeException::class
    );
    $tests->assertSame(true, $failing->isRejected());

    // A bare suspend is cooperative yielding: the task gets its turn back.
    $yielding = SfphpProject\src\Async\async(static function (): string {
        Fiber::suspend();

        return 'resumed';
    });
    $tests->assertSame('resumed', SfphpProject\src\Async\await($yielding));

    /*
     * A task parked on something nobody will ever settle is reported rather
     * than hung on. A program that stops with a message can be fixed; one that
     * stops silently gets reported as "the server is slow".
     */
    $never = new class extends SfphpProject\src\Async\Pending {};
    $waiting = SfphpProject\src\Async\async(static fn () => SfphpProject\src\Async\await($never));
    $tests->assertThrows(
        static fn () => SfphpProject\src\Async\await($waiting),
        SfphpProject\src\Async\AsyncException::class
    );
});

$tests->run('a stream reduces across every chunk, not only the first', function () use ($tests): void {
    $sum = static fn (?int $carry, int $item): int => ($carry ?? 0) + $item;

    $tests->assertSame(55, (new SfphpProject\src\Async\StreamFuture(range(1, 10), 3))->reduce($sum, 0));

    $stream = (new SfphpProject\src\Async\StreamFuture(range(1, 10), 3))
        ->filter(static fn (int $n): bool => $n % 2 === 0)
        ->map(static fn (int $n): int => $n * 10);

    $tests->assertSame(300, $stream->reduce($sum, 0));
    $tests->assertSame([20, 40, 60, 80, 100], $stream->getValue());
});

$tests->run('reactive state takes its value from a Future that is still pending', function () use ($tests): void {
    $state = new SfphpProject\src\Async\ReactiveState();
    $seen = [];
    $state->onChange(static function (SfphpProject\src\Async\ReactiveState $s) use (&$seen): void {
        $seen[] = [$s->getValue(), $s->isLoading()];
    });

    $state->updateFromFuture(SfphpProject\src\Async\delay(10, 'ready'));

    $tests->assertSame('ready', $state->getValue());
    $tests->assertSame(false, $state->hasError());
    $tests->assertSame([['ready', false]], $seen);
});

$tests->run('a component with fallbacks renders, retries and falls back', function () use ($tests): void {
    $tests->assertSame('<p>ok</p>', SfphpProject\src\Async\ComponentFuture::withFallbacks(static fn (): string => '<p>ok</p>')->getValue());

    $attempts = 0;
    $flaky = SfphpProject\src\Async\ComponentFuture::withFallbacks(
        static function () use (&$attempts): string {
            if (++$attempts < 3) {
                throw new RuntimeException('not yet');
            }

            return 'third time';
        },
        null,
        null,
        2
    );
    $tests->assertSame('third time', $flaky->getValue());

    $broken = SfphpProject\src\Async\ComponentFuture::withFallbacks(
        static fn () => throw new RuntimeException('down'),
        null,
        static fn (Throwable $e): string => 'fallback: ' . $e->getMessage()
    );
    $tests->assertSame('fallback: down', $broken->getValue());
});

$tests->run('a WebSocketFuture refuses wss:// instead of connecting in the clear', function () use ($tests): void {
    $socket = new SfphpProject\src\Async\WebSocketFuture('wss://example.invalid/socket');

    $tests->assertSame(false, $socket->connect());
    $tests->assertSame(true, $socket->isRejected());
    $tests->assertSame(false, $socket->isConnected());
});

$tests->run('a deadlock is reported once, and the next await is not blamed for it', function () use ($tests): void {
    $never = new class extends SfphpProject\src\Async\Pending {};
    $stuck = SfphpProject\src\Async\async(static fn () => SfphpProject\src\Async\await($never));

    $tests->assertThrows(static fn () => SfphpProject\src\Async\await($stuck), SfphpProject\src\Async\AsyncException::class);
    $tests->assertSame(true, $stuck->isCancelled());

    // The stuck task used to stay parked and fail this unrelated await too.
    $tests->assertSame('fine', SfphpProject\src\Async\await(SfphpProject\src\Async\delay(1, 'fine')));
});

$tests->run('await, all(), a thrown exception, syncRun() and delay() behave as the async guide says', function () use ($tests): void {
    /*
     * From tests/AsyncBasicTest.php, which only ./sfphp test ran and CI never
     * did. One scheduler per case, pushed and popped, as the guide describes.
     */
    $withScheduler = static function (callable $case): void {
        SfphpProject\src\Async\Context::clear();
        SfphpProject\src\Async\Context::pushScheduler(new SfphpProject\src\Async\Scheduler());

        try {
            $case();
        } finally {
            SfphpProject\src\Async\Context::popScheduler();
        }
    };

    $tests->assertSame(true, SfphpProject\src\Async\async(static fn () => 42) instanceof SfphpProject\src\Async\Task);

    $withScheduler(static function () use ($tests): void {
        $tests->assertSame(42, SfphpProject\src\Async\await(SfphpProject\src\Async\async(static fn () => 42)));

        $tests->assertSame([1, 2, 3], SfphpProject\src\Async\await(SfphpProject\src\Async\CompositeFuture::all(
            SfphpProject\src\Async\async(static fn () => 1),
            SfphpProject\src\Async\async(static fn () => 2),
            SfphpProject\src\Async\async(static fn () => 3),
        )));

        $tests->assertThrows(
            static fn () => SfphpProject\src\Async\await(SfphpProject\src\Async\async(static function (): never {
                throw new Exception('inside the task');
            })),
            Exception::class
        );
    });

    $tests->assertSame(123, SfphpProject\src\Async\syncRun(SfphpProject\src\Async\async(static fn () => 123)));

    $withScheduler(static function () use ($tests): void {
        $startedAt = microtime(true);
        SfphpProject\src\Async\await(SfphpProject\src\Async\delay(50));
        $tests->assertSame(true, microtime(true) - $startedAt >= 0.05);

        // Three timers wait together: about the longest, not the sum of 180 ms.
        $startedAt = microtime(true);
        $values = SfphpProject\src\Async\await(SfphpProject\src\Async\CompositeFuture::all(
            SfphpProject\src\Async\async(static function (): string {
                SfphpProject\src\Async\await(SfphpProject\src\Async\delay(50));

                return 'a';
            }),
            SfphpProject\src\Async\async(static function (): string {
                SfphpProject\src\Async\await(SfphpProject\src\Async\delay(100));

                return 'b';
            }),
            SfphpProject\src\Async\async(static function (): string {
                SfphpProject\src\Async\await(SfphpProject\src\Async\delay(30));

                return 'c';
            }),
        ));

        $tests->assertSame(['a', 'b', 'c'], $values);
        $tests->assertSame(true, microtime(true) - $startedAt < 0.16);
    });
});
