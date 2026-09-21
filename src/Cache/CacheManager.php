<?php

namespace SfphpProject\src\Cache;

class CacheManager
{
    protected Cache $driver;

    public function __construct(Cache $driver = null)
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
