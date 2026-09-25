<?php

namespace SfphpProject\src\Log;

/**
 * Counts things, and times things.
 *
 * A log line carries a duration, which answers "how long did this request
 * take". It does not answer "how long do requests take", and the difference is
 * the whole reason metrics exist: one is an anecdote and the other is the shape
 * of the system. Reading a million log lines to find out how often something
 * fails is not a substitute for having counted.
 *
 *     Metrics::count('orders.placed');
 *     Metrics::count('payments.failed', ['gateway' => 'stripe']);
 *
 *     $result = Metrics::time('report.build', fn () => $report->build());
 *
 * The collector is in-process and holds no state between requests, because
 * anything else needs a store and a story about aggregation that the framework
 * cannot have on an application's behalf. What it gives is a snapshot at the
 * end of a request, and a rendering a scraper understands:
 *
 *     Router::get('/metrics', ...);   // Response::text(Metrics::prometheus())
 *
 * Under a persistent runtime the snapshot accumulates across the requests one
 * worker handled, which is usually what is wanted — and reset() is there for
 * when it is not.
 */
final class Metrics
{
    /** @var array<string, array{value: float, labels: array<string, string>}> */
    private static array $counters = [];

    /** @var array<string, array{count: int, total: float, min: float, max: float, labels: array<string, string>}> */
    private static array $timers = [];

    /**
     * Add to a counter.
     *
     * @param string $name What is being counted
     * @param array<string, string> $labels Dimensions, such as a gateway or a status
     * @param float $by How much to add
     * @return void
     */
    public static function count(string $name, array $labels = [], float $by = 1.0): void
    {
        $key = self::key($name, $labels);

        self::$counters[$key] ??= ['name' => $name, 'value' => 0.0, 'labels' => $labels];
        self::$counters[$key]['value'] += $by;
    }

    /**
     * Record how long something took, in milliseconds.
     *
     * @param string $name What was timed
     * @param float $milliseconds How long it took
     * @param array<string, string> $labels Dimensions
     * @return void
     */
    public static function record(string $name, float $milliseconds, array $labels = []): void
    {
        $key = self::key($name, $labels);

        if (!isset(self::$timers[$key])) {
            self::$timers[$key] = [
                'name' => $name,
                'count' => 0,
                'total' => 0.0,
                'min' => $milliseconds,
                'max' => $milliseconds,
                'labels' => $labels,
            ];
        }

        $timer = &self::$timers[$key];
        $timer['count']++;
        $timer['total'] += $milliseconds;
        $timer['min'] = min($timer['min'], $milliseconds);
        $timer['max'] = max($timer['max'], $milliseconds);
    }

    /**
     * Run something and record how long it took.
     *
     * The timing is recorded whether the callable returns or throws, because a
     * call that failed after four seconds still took four seconds — and an
     * operation that only gets slow when it is failing is exactly the one worth
     * seeing.
     *
     * @template T
     * @param string $name What is being timed
     * @param callable(): T $work The work
     * @param array<string, string> $labels Dimensions
     * @return T Whatever the work returned
     */
    public static function time(string $name, callable $work, array $labels = []): mixed
    {
        $startedAt = microtime(true);

        try {
            return $work();
        } finally {
            self::record($name, (microtime(true) - $startedAt) * 1000, $labels);
        }
    }

    /**
     * Everything collected so far.
     *
     * @return array{counters: array<string, array{value: float, labels: array<string, string>}>, timers: array<string, array{count: int, total: float, min: float, max: float, avg: float, labels: array<string, string>}>}
     */
    public static function snapshot(): array
    {
        $timers = [];

        foreach (self::$timers as $key => $timer) {
            $timers[$key] = $timer + ['avg' => round($timer['total'] / max(1, $timer['count']), 3)];
        }

        return ['counters' => self::$counters, 'timers' => $timers];
    }

    /**
     * Render the snapshot in the text format a scraper reads.
     *
     * Plain text assembled here rather than through a client library, which
     * keeps the zero-dependency rule and costs about thirty lines. The format
     * is stable and simple; a library would mostly be adding a push gateway
     * this does not have.
     *
     * @return string The metrics, one per line
     */
    public static function prometheus(): string
    {
        $lines = [];
        $typed = [];

        /*
         * Each metric family is declared once with # TYPE, which is what tells
         * a scraper a counter from a gauge; without it every series was read
         * as untyped. Values are written with at most three decimals, as the
         * documentation shows them, not with PHP's full float precision.
         */
        $declare = static function (string $name, string $type) use (&$lines, &$typed): void {
            if (!isset($typed[$name])) {
                $typed[$name] = true;
                $lines[] = '# TYPE ' . $name . ' ' . $type;
            }
        };

        foreach (self::$counters as $counter) {
            $name = self::sanitise($counter['name']);
            $declare($name, 'counter');
            $lines[] = self::line($name, $counter['labels'], $counter['value']);
        }

        foreach (self::snapshot()['timers'] as $timer) {
            $name = self::sanitise($timer['name']);

            foreach (['count' => ['counter', $timer['count']], 'sum' => ['counter', $timer['total']],
                      'min' => ['gauge', $timer['min']], 'max' => ['gauge', $timer['max']]] as $suffix => [$type, $value]) {
                $declare($name . '_ms_' . $suffix, $type);
                $lines[] = self::line($name . '_ms_' . $suffix, $timer['labels'], $value);
            }
        }

        return implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }

    /**
     * Forget everything collected.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$counters = [];
        self::$timers = [];
    }

    /**
     * The key one metric is stored under.
     *
     * @param string $name The metric name
     * @param array<string, string> $labels The dimensions
     * @return string The key
     */
    private static function key(string $name, array $labels): string
    {
        ksort($labels);

        return $name . ($labels === [] ? '' : '|' . json_encode($labels));
    }

    /**
     * Reduce a metric name to the characters a scraper accepts.
     *
     * @param string $name The name as it was given
     * @return string The name, safe to write
     */
    private static function sanitise(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? 'metric';

        // A name may not start with a digit; "5xx_errors" is not a metric name.
        return preg_match('/^[0-9]/', $name) === 1 ? '_' . $name : $name;
    }

    /**
     * One line of the text format.
     *
     * @param string $name The metric name
     * @param array<string, string> $labels The dimensions
     * @param float|int $value The value
     * @return string The line
     */
    private static function line(string $name, array $labels, float|int $value): string
    {
        $value = is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : (string) $value;

        if ($labels === []) {
            return $name . ' ' . $value;
        }

        $rendered = [];

        foreach ($labels as $label => $labelValue) {
            // A quote or a backslash in a label value would end it early.
            $rendered[] = preg_replace('/[^a-zA-Z0-9_]/', '_', $label)
                . '="' . addcslashes((string) $labelValue, "\"\\\n") . '"';
        }

        return $name . '{' . implode(',', $rendered) . '} ' . $value;
    }
}
