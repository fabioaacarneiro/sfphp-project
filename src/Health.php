<?php

namespace SfphpProject\src;

use Throwable;

/**
 * Answers whether this instance is able to do its job.
 *
 * A load balancer needs somewhere to ask, and "the process is running" is the
 * wrong question: an instance whose database is unreachable still answers a TCP
 * connection and still serves errors to everyone routed to it. What a balancer
 * needs to know is whether sending traffic here will work.
 *
 * The framework ships the checks and not the route, because where it lives and
 * who may see it are the application's to decide:
 *
 *     Router::get('/health', [HealthController::class, 'show']);
 *
 *     public function show(Request $request): Response
 *     {
 *         $report = Health::check();
 *
 *         return Response::json($report, $report['healthy'] ? HTTP_OK : 503);
 *     }
 *
 * > **A health endpoint is a description of your infrastructure.** Left public,
 * > it tells anyone which dependencies you have and which are currently down —
 * > which is the first thing worth knowing before attacking something. Put it
 * > behind the load balancer's network, or behind a token.
 */
final class Health
{
    /** @var array<string, callable(): bool> */
    private static array $checks = [];

    /**
     * Register a check of your own.
     *
     * The callable returns true when that dependency is usable. Throwing counts
     * as a failure and the message is reported, so a check does not have to
     * catch its own errors.
     *
     * @param string $name What is being checked
     * @param callable(): bool $check The check
     * @return void
     */
    public static function register(string $name, callable $check): void
    {
        self::$checks[$name] = $check;
    }

    /**
     * Forget every registered check.
     *
     * @return void
     */
    public static function forget(): void
    {
        self::$checks = [];
    }

    /**
     * Run every check and report what happened.
     *
     * Each check is timed, because "the database answered" and "the database
     * answered in four seconds" are different states and only one of them is
     * visible in a boolean. A balancer reads `healthy`; a person reads the rest.
     *
     * @param list<string>|null $only Run only these checks, or null for all
     * @return array{healthy: bool, checks: array<string, array{ok: bool, ms: float, error?: string}>}
     */
    public static function check(?array $only = null): array
    {
        $checks = self::$checks;

        if ($only !== null) {
            $checks = array_intersect_key($checks, array_flip($only));
        }

        $report = [];
        $healthy = true;

        foreach ($checks as $name => $check) {
            $startedAt = microtime(true);

            try {
                $ok = $check() === true;
                $entry = ['ok' => $ok, 'ms' => self::elapsed($startedAt)];
            } catch (Throwable $throwable) {
                $ok = false;
                $entry = [
                    'ok' => false,
                    'ms' => self::elapsed($startedAt),
                    /*
                     * The message, not the trace. This response may be read by
                     * something outside the deployment, and a stack trace names
                     * paths and classes that are nobody else's business.
                     */
                    'error' => $throwable->getMessage(),
                ];
            }

            $healthy = $healthy && $ok;
            $report[$name] = $entry;
        }

        return ['healthy' => $healthy, 'checks' => $report];
    }

    /**
     * Register the checks for the pieces the framework knows about.
     *
     * Called by an application that wants the obvious ones without writing
     * them. Nothing is registered by default, because a health endpoint that
     * reports on a database an application does not use would be answering the
     * wrong question.
     *
     * @param list<string> $names Any of "database", "cache", "queue"
     * @return void
     */
    public static function registerDefaults(array $names = ['database', 'cache']): void
    {
        foreach ($names as $name) {
            match ($name) {
                'database' => self::register('database', static function (): bool {
                    /*
                     * A statement, not just a connection: PDO can hold a handle
                     * to a server that has stopped answering, and the point is
                     * to find that out here rather than in a request.
                     */
                    Database::connect()->query('SELECT 1')->fetchColumn();

                    return true;
                }),
                'cache' => self::register('cache', static function (): bool {
                    $key = 'health:' . bin2hex(random_bytes(8));
                    cache()->put($key, 'ok', 10);
                    $read = cache()->get($key);
                    cache()->forget($key);

                    // A write nobody can read back is a cache that is not working.
                    return $read === 'ok';
                }),
                'queue' => self::register('queue', static function (): bool {
                    queue()->size();

                    return true;
                }),
                default => null,
            };
        }
    }

    /**
     * How long a check took, in milliseconds.
     *
     * @param float $startedAt The value microtime(true) returned before it ran
     * @return float The elapsed milliseconds
     */
    private static function elapsed(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 3);
    }
}
