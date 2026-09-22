<?php

namespace SfphpProject\src\Cache;

class RedisDriver implements Cache
{
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

        /*
         * increment() stores a bare integer, because that is the only thing
         * Redis knows how to add to. Those values are not serialised, so
         * unserialize() would fail on them. A string that was really stored
         * through put() cannot be mistaken for one of these: serialize('42')
         * is 's:2:"42";', which has no bare digits to match.
         */
        if (preg_match('/^-?\\d+$/', $value) === 1) {
            return (int) $value;
        }

        return unserialize($value);
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $serialized = serialize($value);

        if ($seconds === null) {
            $this->redis->set($this->key($key), $serialized);
        } else {
            $this->redis->setEx($this->key($key), $seconds, $serialized);
        }
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    public function flush(): void
    {
        $pattern = $this->prefix . '*';
        $keys = $this->redis->keys($pattern);

        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    /**
     * Add to a counter and return its new value, atomically.
     *
     * The addition happens in Redis with INCRBY, not in PHP, which is what
     * makes it a counter: several processes adding at once each see their own
     * add reflected, instead of overwriting one another.
     *
     * The expiry is placed with SET NX EX before the add. NX only writes when
     * the key is absent, so the lifetime is set exactly once, when the counter
     * is created — an existing counter keeps the expiry it had, and a client
     * that keeps knocking cannot push its own window forward.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int
    {
        $redisKey = $this->key($key);

        if ($seconds !== null) {
            $this->redis->set($redisKey, 0, ['nx', 'ex' => $seconds]);
        }

        return (int) $this->redis->incrBy($redisKey, $by);
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
