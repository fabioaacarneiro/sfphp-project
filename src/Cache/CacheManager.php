<?php

namespace SfphpProject\src\Cache;

class CacheManager
{
    protected Cache $driver;

    public function __construct(?Cache $driver = null)
    {
        $this->driver = $driver ?? new FileDriver();
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
