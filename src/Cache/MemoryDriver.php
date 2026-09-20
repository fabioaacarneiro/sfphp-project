<?php

namespace SfPhp\Cache;

class MemoryDriver implements Cache
{
    protected array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->store[$key])) {
            return $default;
        }

        $item = $this->store[$key];

        if ($item['expires'] !== null && $item['expires'] < time()) {
            unset($this->store[$key]);
            return $default;
        }

        return $item['value'] ?? $default;
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expires' => $seconds ? time() + $seconds : null,
        ];
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
