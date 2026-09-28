<?php

/*
 * Helpers several parts of the suite share: fixtures, fakes and the
 * headless-browser harness. Loaded by tests/run.php before the suite.
 */

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Assets;

$compileSchema = static function (string $driver, string $mode, string $table, callable $define): array {
    $blueprint = new Blueprint($table, $driver, $mode);
    $define($blueprint);

    return $blueprint->compileStatements();
};

/**
 * Build SFCSS from a project config and return the stylesheet and the warnings.
 *
 * @return array{css: string, stderr: string, status: int}
 */
$buildSfcss = static function (array $config): array {
    $directory = sys_get_temp_dir() . '/sfcss-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/config.json', json_encode($config === [] ? new stdClass() : $config));

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/tools/css-builder/sfcss-builder.php', $directory . '/config.json', $directory],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    $css = (string) @file_get_contents($directory . '/sfcss.css');
    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);

    return ['css' => $css, 'stderr' => $stderr, 'status' => $status];
};

/**
 * Load a page with SFJS into headless Chrome and read back what it reported.
 *
 * The page calls report(value) when it is done; this returns the value,
 * decoded. Before SFJS loads, console.warn and console.error are recorded in
 * window.logged, and wait(ms) is there for the page's own script. Null means
 * there is no Chrome here, and the test has nothing to check — the CI runner
 * has one.
 *
 * @param string $markup The body's markup
 * @param string $before Script that runs before SFJS: the fake fetch, above all
 * @param string $after Script that runs after SFJS
 * @return mixed What the page reported, or null
 */
function sfjsInBrowser(string $markup, string $before, string $after): mixed
{
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return null;
    }

    $directory = sys_get_temp_dir() . '/sfphp-sfjs-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $script = Assets::path() . '/js/sfjs.min.js';

    file_put_contents($directory . '/harness.html', <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"><meta name="csrf-token" content="the-token"></head>
    <body>
    {$markup}
    <pre id="log">nothing happened</pre>
    <script>
      window.logged = { warn: [], error: [] };
      ['warn', 'error'].forEach((level) => {
        console[level] = (...args) => window.logged[level].push(args.map(String).join(' '));
      });
      window.addEventListener('error', (e) => window.logged.error.push('uncaught: ' + e.message));
      window.addEventListener('unhandledrejection', (e) => window.logged.error.push('unhandled: ' + e.reason));
      window.wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
      window.report = (value) => { document.getElementById('log').textContent = JSON.stringify(value); };
      {$before}
    </script>
    <script src="file://{$script}"></script>
    <script>
      {$after}
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=5000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $directory . '/harness.html') . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        if (!preg_match('/<pre id="log">(.*?)<\/pre>/s', $dom, $matches)) {
            return null;
        }

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    } finally {
        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
}

/*
 * Jobs for the queue tests below. Declared at the top level, not as anonymous
 * classes, because a queued payload names its class and the worker rebuilds
 * the job from that name.
 */
final class QueueProbeInvoiceJob extends \SfphpProject\src\Queue\Job
{
    public static array $sent = [];

    public function __construct(private readonly int $invoiceId, private string $to)
    {
    }

    public function handle(): void
    {
        self::$sent[] = $this->invoiceId . ':' . $this->to;
    }
}

final class QueueProbeFailingJob extends \SfphpProject\src\Queue\Job
{
    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
        throw new RuntimeException('always fails');
    }
}

final class QueueProbeSlowJob extends \SfphpProject\src\Queue\Job
{
    public function handle(): void
    {
        sleep(5);
    }
}

/**
 * A queue kept in memory that stores what the real drivers store: the JSON
 * payload, not the object, so every pop() goes through Job::fromPayload().
 * When it runs dry it sends the process SIGTERM, which is how a test finds
 * out whether the worker listens.
 */
final class QueueProbeDriver implements \SfphpProject\src\Queue\Queue
{
    /** @var list<array{id: string, attempts: int, payload: string}> */
    public array $jobs = [];

    /** @var list<array{id: string, exception: string}> */
    public array $failures = [];

    public bool $terminateWhenEmpty = true;

    public function push(\SfphpProject\src\Queue\Job $job, ?int $delay = null): string
    {
        $id = 'probe_' . count($this->jobs) . '_' . bin2hex(random_bytes(3));
        $this->jobs[] = ['id' => $id, 'attempts' => 0, 'payload' => $this->encode($job)];

        return $id;
    }

    public function pop(): ?\SfphpProject\src\Queue\Job
    {
        $row = array_shift($this->jobs);

        if ($row === null) {
            if ($this->terminateWhenEmpty) {
                posix_kill(getmypid(), SIGTERM);
            }

            return null;
        }

        return \SfphpProject\src\Queue\Job::fromPayload(json_decode($row['payload'], true), $row['id'], $row['attempts']);
    }

    public function failed(\SfphpProject\src\Queue\Job $job, \Throwable $exception): void
    {
        $this->failures[] = ['id' => (string) $job->getId(), 'exception' => get_class($exception)];
    }

    public function failedJobs(): array
    {
        return [];
    }

    public function retry(\SfphpProject\src\Queue\Job $job): void
    {
        // What both real drivers do: count the attempt, then put it back.
        $job->setAttempts($job->getAttempts() + 1);
        $this->jobs[] = ['id' => (string) $job->getId(), 'attempts' => $job->getAttempts(), 'payload' => $this->encode($job)];
    }

    public function release(\SfphpProject\src\Queue\Job $job, ?int $delay = null): void
    {
    }

    public function delete(\SfphpProject\src\Queue\Job $job): void
    {
    }

    public function flush(): void
    {
        $this->jobs = [];
    }

    public function size(): int
    {
        return count($this->jobs);
    }

    private function encode(\SfphpProject\src\Queue\Job $job): string
    {
        return json_encode(['class' => get_class($job), 'data' => $job->payload(), 'options' => $job->options()]);
    }
}

/**
 * An in-memory stand-in for the phpredis methods the queue driver uses, so the
 * driver is tested here without a Redis server or ext-redis.
 */
$fakeRedis = static fn (): object => new class () {
    /** @var array<string, array<string, float>> */
    public array $sorted = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    public function zAdd(string $key, float $score, string $member): int
    {
        $new = !isset($this->sorted[$key][$member]);
        $this->sorted[$key][$member] = $score;

        return $new ? 1 : 0;
    }

    public function zRangeByScore(string $key, $min, $max, array $options = []): array
    {
        $members = array_filter($this->sorted[$key] ?? [], fn (float $score): bool => $score >= $min && $score <= $max);
        asort($members);
        [$offset, $count] = $options['limit'] ?? [0, null];

        return array_slice(array_keys($members), $offset, $count);
    }

    public function zRem(string $key, string $member): int
    {
        if (!isset($this->sorted[$key][$member])) {
            return 0;
        }

        unset($this->sorted[$key][$member]);

        return 1;
    }

    public function zCard(string $key): int
    {
        return count($this->sorted[$key] ?? []);
    }

    public function hSet(string $key, string $field, string $value): int
    {
        $this->hashes[$key][$field] = $value;

        return 1;
    }

    public function hGet(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hDel(string $key, string $field): int
    {
        $had = isset($this->hashes[$key][$field]);
        unset($this->hashes[$key][$field]);

        return $had ? 1 : 0;
    }

    public function hGetAll(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    public function del(string ...$keys): int
    {
        foreach ($keys as $key) {
            unset($this->sorted[$key], $this->hashes[$key]);
        }

        return count($keys);
    }

    public function keys(string $pattern): array
    {
        $all = array_unique(array_merge(array_keys($this->sorted), array_keys($this->hashes)));

        return array_values(array_filter($all, fn (string $key): bool => fnmatch($pattern, $key)));
    }
};

/**
 * Start tests/fixtures/http-server.php, and say where it is.
 *
 * The port is one the system says is free, rather than one worked out from
 * the process id: two tests that worked it out the same way took the same
 * port, and the second connected to the first one's server while it was
 * still going away — "Connection reset by peer", on CI and nowhere else.
 * It is only handed back once the server that answers is this fixture, and
 * stopping it waits until the process is gone.
 *
 * @return array{0: string, 1: Closure(): void}|null The base URL and a function that stops it, or null when it did not start
 */
function fixtureServer(): ?array
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($socket === false) {
        return null;
    }

    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    fclose($socket);

    $root = __DIR__ . '/fixtures';
    $pid = (int) trim((string) shell_exec(sprintf(
        '%s -S 127.0.0.1:%d -t %s %s/http-server.php > /dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($root),
        escapeshellarg($root)
    )));

    $stop = static function () use ($pid): void {
        if ($pid <= 0) {
            return;
        }

        exec('kill ' . $pid . ' 2>/dev/null');

        for ($wait = 0; $wait < 100 && is_dir('/proc/' . $pid); $wait++) {
            usleep(20_000);
        }
    };

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($connection !== false) {
            fwrite($connection, "GET /status/404 HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
            $answer = (string) stream_get_contents($connection);
            fclose($connection);

            if (str_contains($answer, 'X-Served-By: sfphp-test')) {
                return ['http://127.0.0.1:' . $port, $stop];
            }
        }

        usleep(50_000);
    }

    $stop();

    return null;
}
