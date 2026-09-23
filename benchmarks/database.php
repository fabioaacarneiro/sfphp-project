<?php

/**
 * What a query costs, and what awaiting one does not buy.
 *
 * The point of this benchmark is a negative result, which is why it exists:
 * three queries awaited together take as long as three queries, because PDO
 * blocks and no Fiber changes that. `SELECT SLEEP(n)` is used deliberately —
 * the wait happens inside the database server, so it is exactly the kind of
 * wait an async runtime is supposed to overlap, and this shows that for
 * queries it does not.
 *
 *   php benchmarks/database.php "mysql:host=127.0.0.1;port=33061;dbname=bench" root secret
 */

require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Async\Adapters\QueryFuture;
use SfphpProject\src\Async\CompositeFuture;

use function SfphpProject\src\Async\await;

$dsn = $argv[1] ?? 'mysql:host=127.0.0.1;port=3306;dbname=bench';
$user = $argv[2] ?? 'root';
$password = $argv[3] ?? 'secret';

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

printf("SFPHP database benchmark%s", PHP_EOL);
printf("  PHP           %s%s", PHP_VERSION, PHP_EOL);
printf("  driver        %s%s", $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), PHP_EOL);
printf("  server        %s%s", $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), PHP_EOL);
printf("%s", PHP_EOL);

/**
 * Time a piece of work.
 *
 * @param string $label What it is
 * @param callable $work The work
 * @return void
 */
function measure(string $label, callable $work): void
{
    $startedAt = microtime(true);
    $work();

    printf("  %-40s %8.1f ms%s", $label, (microtime(true) - $startedAt) * 1000, PHP_EOL);
}

$sleep = static function (PDO $pdo, float $seconds): callable {
    return static function () use ($pdo, $seconds) {
        $statement = $pdo->prepare('SELECT SLEEP(?) AS slept');
        $statement->execute([$seconds]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    };
};

echo 'A query that the server spends 200 ms on' . PHP_EOL;

measure('1 query, synchronous', $sleep($pdo, 0.2));

measure('3 queries, synchronous', static function () use ($sleep, $pdo): void {
    for ($i = 0; $i < 3; $i++) {
        ($sleep($pdo, 0.2))();
    }
});

measure('3 queries, awaited together', static function () use ($sleep, $pdo): void {
    await(CompositeFuture::all(
        new QueryFuture($sleep($pdo, 0.2)),
        new QueryFuture($sleep($pdo, 0.2)),
        new QueryFuture($sleep($pdo, 0.2))
    ));
});

printf("%s", PHP_EOL);
echo 'The last two are the same number, and that is the finding: awaiting a' . PHP_EOL;
echo 'PDO query schedules it, it does not overlap it. See ASYNC_RUNTIME_AUDIT.md.' . PHP_EOL;
printf("%s", PHP_EOL);

echo 'For contrast, the same shape over HTTP' . PHP_EOL;

$origin = getenv('SFPHP_BENCH_ORIGIN') ?: 'http://127.0.0.1:8300';

if (@file_get_contents($origin . '/delay?ms=1') !== false) {
    measure('3 requests, awaited together', static function () use ($origin): void {
        $url = $origin . '/delay?ms=200';

        await(CompositeFuture::all(
            SfphpProject\src\Http\Http::getAsync($url),
            SfphpProject\src\Http\Http::getAsync($url),
            SfphpProject\src\Http\Http::getAsync($url)
        ));
    });
}
