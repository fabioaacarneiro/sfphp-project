<?php

namespace SfphpProject\src\Cache;

use SfphpProject\src\Config;
use SfphpProject\src\RedisConnection;

class CacheManager
{
    protected Cache $driver;

    public function __construct(?Cache $driver = null)
    {
        $this->driver = $driver ?? new FileDriver();
    }

    /**
     * Build the cache CACHE_DRIVER names.
     *
     * The helper cache() keeps one of these for the process. This is separate
     * from it so the choice can be exercised without the memo in the way, and
     * so an application that needs a second, differently configured cache can
     * ask for one.
     *
     * @return static The cache
     */
    public static function fromConfig(): static
    {
        $driver = match (Config::get('CACHE_DRIVER', 'file')) {
            'redis' => new RedisDriver(
                RedisConnection::get(),
                Config::string('CACHE_PREFIX', 'sfphp:cache:')
            ),
            /*
             * Per-process and gone at the end of the request. Useful in tests
             * and in a console command that only wants memoisation, never in a
             * deployment — which is why it is the default nowhere.
             */
            'array', 'memory' => new MemoryDriver(),
            default => new FileDriver(Config::get('CACHE_PATH') ?: null),
        };

        return new static($driver);
    }

    /**
     * Which driver this manager is using.
     *
     * @return Cache The driver
     */
    public function getDriver(): Cache
    {
        return $this->driver;
    }

    public function driver(Cache $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($key, $default);
    }

    public function put(string $key, mixed $value, ?int $seconds = null): static
    {
        $this->driver->put($key, $value, $seconds);
        return $this;
    }

    public function forget(string $key): static
    {
        $this->driver->forget($key);
        return $this;
    }

    public function flush(): static
    {
        $this->driver->flush();
        return $this;
    }

    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    /**
     * Add to a counter and return its new value, atomically.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int
    {
        return $this->driver->increment($key, $by, $seconds);
    }

    /**
     * Subtract from a counter and return its new value, atomically.
     *
     * @param string $key The counter's key
     * @param int $by How much to subtract
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after subtracting
     */
    public function decrement(string $key, int $by = 1, ?int $seconds = null): int
    {
        return $this->driver->increment($key, -$by, $seconds);
    }

    /**
     * How many seconds remain before an entry expires.
     *
     * @param string $key The entry's key
     * @return int|null The seconds remaining, or null when the key is absent or never expires
     */
    public function ttl(string $key): ?int
    {
        return $this->driver->ttl($key);
    }

    public function remember(string $key, ?int $seconds, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value, $seconds);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }
}
