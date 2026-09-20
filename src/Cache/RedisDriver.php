<?php

namespace SfPhp\Cache;

class RedisDriver implements Cache
{
    protected \Redis $redis;
    protected string $prefix;

    public function __construct(
        \Redis $redis = null,
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

    protected function key(string $key): string
    {
        return $this->prefix . $key;
    }
}
