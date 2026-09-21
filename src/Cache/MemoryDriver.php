<?php

namespace SfphpProject\src\Cache;

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

    /**
     * Add to a counter and return its new value.
     *
     * This driver holds its store in one process's memory, so nothing else can
     * be writing between the read and the write. The method exists to honour
     * the contract, and to make the expiry rule the same everywhere: a counter
     * being created gets the lifetime, an existing one keeps the one it had.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int
    {
        $current = $this->get($key);

        if ($current === null) {
            $this->put($key, $by, $seconds);

            return $by;
        }

        $value = (int) $current + $by;
        $this->store[$key]['value'] = $value;

        return $value;
    }

    /**
     * How many seconds remain before an entry expires.
     *
     * @param string $key The entry's key
     * @return int|null The seconds remaining, or null when the key is absent or never expires
     */
    public function ttl(string $key): ?int
    {
        if ($this->get($key) === null) {
            return null;
        }

        $expires = $this->store[$key]['expires'] ?? null;

        return $expires === null ? null : max(0, $expires - time());
    }
}
