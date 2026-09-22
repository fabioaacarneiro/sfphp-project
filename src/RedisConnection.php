<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * The one Redis connection this process uses.
 *
 * The cache driver and the queue driver each used to build their own, both
 * hardcoded to 127.0.0.1:6379 with no password and no database number. That is
 * fine on a laptop and wrong everywhere else, and it meant the only way to point
 * them at a real server was to construct the drivers by hand in application
 * code — which is why nothing read a `CACHE_DRIVER` setting: there was nothing
 * useful for it to select.
 *
 * Shared rather than per-driver because a request that touches the cache, the
 * session and the queue would otherwise open three sockets to the same server.
 *
 *     Config: REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, REDIS_DB, REDIS_TIMEOUT
 */
final class RedisConnection
{
    private static ?\Redis $connection = null;

    /**
     * The connection, opened on first use.
     *
     * @return \Redis The connection
     * @throws RuntimeException When ext-redis is absent or the server refuses
     */
    public static function get(): \Redis
    {
        if (self::$connection instanceof \Redis) {
            return self::$connection;
        }

        if (!extension_loaded('redis')) {
            /*
             * Refused rather than degraded. Falling back to the file driver here
             * would leave an operator believing revoked tokens and rate limits
             * are shared between instances when each machine is keeping its own
             * copy — the failure would show up as a security hole, months later,
             * and never as an error.
             */
            throw new RuntimeException(
                'Redis was selected but ext-redis is not installed. Install the extension, or choose another driver.'
            );
        }

        $redis = new \Redis();

        $host = Config::string('REDIS_HOST', '127.0.0.1');
        $port = Config::int('REDIS_PORT', 6379);
        $timeout = Config::int('REDIS_TIMEOUT', 2);

        try {
            $connected = $redis->connect($host, $port, $timeout);
        } catch (\RedisException $exception) {
            throw new RuntimeException(
                sprintf('Could not connect to Redis at %s:%d: %s', $host, $port, $exception->getMessage()),
                0,
                $exception
            );
        }

        if ($connected !== true) {
            throw new RuntimeException(sprintf('Could not connect to Redis at %s:%d.', $host, $port));
        }

        $password = Config::string('REDIS_PASSWORD', '');

        if ($password !== '') {
            $redis->auth($password);
        }

        $database = Config::int('REDIS_DB', 0);

        if ($database !== 0) {
            $redis->select($database);
        }

        return self::$connection = $redis;
    }

    /**
     * Use this connection instead of opening one.
     *
     * For tests, and for an application that builds its own — a TLS socket or a
     * cluster client, neither of which the framework has an opinion about.
     *
     * @param \Redis|null $redis The connection, or null to forget the current one
     * @return void
     */
    public static function use(?\Redis $redis): void
    {
        self::$connection = $redis;
    }

    /**
     * Whether a connection is open in this process.
     *
     * @return bool True when one is held
     */
    public static function isConnected(): bool
    {
        return self::$connection instanceof \Redis;
    }
}
