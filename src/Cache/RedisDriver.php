<?php

namespace SfphpProject\src\Cache;

/**
 * A cache kept in Redis.
 *
 * Values are stored as JSON, as the file driver stores them. This driver used
 * to serialize(), which made it the only one that kept objects — so code that
 * worked against it broke on the file driver — and it unserialize()d whatever
 * the server held, which is how a writable Redis becomes code execution.
 */
class RedisDriver implements Cache
{
    /**
     * Adds to a counter and gives it a lifetime in one step.
     *
     * SET NX EX followed by INCRBY is two commands, and a key that expired
     * between them came back from INCRBY with no lifetime at all: a rate
     * limit that never resets. A script runs atomically on the server.
     */
    private const INCREMENT = <<<'LUA'
        local value = redis.call('INCRBY', KEYS[1], ARGV[1])
        if tonumber(ARGV[2]) > 0 and redis.call('TTL', KEYS[1]) == -1 and tonumber(value) == tonumber(ARGV[1]) then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        return value
        LUA;

    protected \Redis $redis;
    protected string $prefix;

    public function __construct(
        ?\Redis $redis = null,
        string $prefix = 'sfphp:cache:'
    ) {
        if ($redis === null) {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
        }

        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->key($key));

        if ($value === false) {
            return $default;
        }

        // A counter is a bare integer, which is also valid JSON.
        return json_decode((string) $value, true);
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if (Ttl::forever($seconds)) {
            $this->redis->set($this->key($key), $encoded);
        } else {
            $this->redis->setEx($this->key($key), $seconds, $encoded);
        }
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    /**
     * Remove every entry under this cache's prefix.
     *
     * With SCAN, a batch at a time. KEYS walks the whole keyspace in one
     * command and blocks the server while it does, which on a shared Redis
     * stalls every other client.
     */
    public function flush(): void
    {
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 1000);

            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($iterator !== 0 && $iterator !== null && $iterator !== false);
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    /**
     * Redis expires entries itself, so there is nothing to prune.
     */
    public function prune(): int
    {
        return 0;
    }

    /**
     * Add to a counter and return its new value, atomically.
     *
     * The addition and the lifetime happen in one script on the server. The
     * lifetime is only given to a counter that this call created, so an
     * existing counter keeps the expiry it had and a client that keeps
     * knocking cannot push its own window forward.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int
    {
        $lifetime = Ttl::forever($seconds) ? 0 : $seconds;

        return (int) $this->redis->eval(self::INCREMENT, [$this->key($key), $by, $lifetime], 1);
    }

    /**
     * How many seconds remain before an entry expires.
     *
     * Redis answers -2 for a key that is not there and -1 for one that never
     * expires; both mean "no deadline to report" here.
     *
     * @param string $key The entry's key
     * @return int|null The seconds remaining, or null when the key is absent or never expires
     */
    public function ttl(string $key): ?int
    {
        $ttl = $this->redis->ttl($this->key($key));

        return is_int($ttl) && $ttl >= 0 ? $ttl : null;
    }

    protected function key(string $key): string
    {
        return $this->prefix . $key;
    }
}
