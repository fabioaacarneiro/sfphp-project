<?php

/**
 * What the async runtime actually does, measured.
 *
 * Nothing here sleeps to look busy. The HTTP numbers come from real sockets
 * against benchmarks/origin.php, which answers after a delay it keeps in a
 * single non-blocking process — so what is measured is this client, not the
 * worker count of the server it is talking to.
 *
 *   php benchmarks/origin.php 127.0.0.1:8300 &
 *   php benchmarks/async.php
 *
 * Every number printed is one run on the machine that ran it. The header says
 * which machine, which PHP and what the origin was told to do, because a
 * number without those is a number about nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

$origin = $argv[1] ?? 'http://127.0.0.1:8300';
$delayMs = (int) ($argv[2] ?? 300);
$url = $origin . '/delay?ms=' . $delayMs;

$probe = @file_get_contents($origin . '/delay?ms=1');

if ($probe === false) {
    fwrite(STDERR, "No origin at {$origin}. Start it with:" . PHP_EOL);
    fwrite(STDERR, '  php ' . __DIR__ . '/origin.php 127.0.0.1:8300 &' . PHP_EOL);

    exit(1);
}

printf("SFPHP async benchmark%s", PHP_EOL);
printf("  PHP           %s (%s)%s", PHP_VERSION, PHP_OS, PHP_EOL);
printf("  OS            %s%s", trim((string) shell_exec('uname -sr')), PHP_EOL);
printf("  CPU           %s x %s%s", trim((string) shell_exec('nproc')), trim((string) shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")), PHP_EOL);
printf("  curl          %s%s", curl_version()['version'], PHP_EOL);
printf("  origin        %s, answering after %d ms%s", $origin, $delayMs, PHP_EOL);
printf("%s", PHP_EOL);

/**
 * Time a piece of work.
 *
 * @param string $label What it is
 * @param callable $work The work
 * @param string $note Anything worth saying about the number
 * @return float Milliseconds
 */
function measure(string $label, callable $work, string $note = ''): float
{
    $startedAt = microtime(true);
    $work();
    $elapsed = (microtime(true) - $startedAt) * 1000;

    printf("  %-34s %8.1f ms  %s%s", $label, $elapsed, $note, PHP_EOL);

    return $elapsed;
}

echo "1. Runtime overhead — no I/O at all" . PHP_EOL;

$iterations = 10000;

$plain = measure(sprintf('%d plain function calls', $iterations), static function () use ($iterations): void {
    $sum = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $sum += (static fn (int $n): int => $n + 1)($i);
    }
});

$tasks = measure(sprintf('%d async() + await()', $iterations), static function () use ($iterations): void {
    for ($i = 0; $i < $iterations; $i++) {
        await(async(static fn (): int => $i + 1));
    }
});

printf("  %-34s %8.1f us per task%s%s", '', ($tasks - $plain) * 1000 / $iterations, PHP_EOL, PHP_EOL);

echo "2. HTTP — every request really leaves the machine" . PHP_EOL;

measure('1 request', static function () use ($url): void {
    await(Http::getAsync($url));
});

measure('3 requests, one after another', static function () use ($url): void {
    for ($i = 0; $i < 3; $i++) {
        await(Http::getAsync($url));
    }
}, 'awaited before the next one starts');

foreach ([3, 10, 50] as $count) {
    measure(sprintf('%d requests, all in flight', $count), static function () use ($url, $count): void {
        $futures = [];

        for ($i = 0; $i < $count; $i++) {
            $futures[] = Http::getAsync($url);
        }

        $responses = await(CompositeFuture::all(...$futures));

        foreach ($responses as $response) {
            if ($response->status() !== 200) {
                throw new RuntimeException('The origin answered ' . $response->status());
            }
        }
    });
}

measure('10 requests through async() tasks', static function () use ($url): void {
    $tasks = [];

    for ($i = 0; $i < 10; $i++) {
        $tasks[] = async(static fn () => await(Http::getAsync($url))->status());
    }

    await(CompositeFuture::all(...$tasks));
});

printf("%s", PHP_EOL);
echo "3. Memory" . PHP_EOL;
printf("  %-34s %8.2f MB%s", 'peak', memory_get_peak_usage(true) / 1048576, PHP_EOL);
