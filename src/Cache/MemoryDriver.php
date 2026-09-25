<?php

namespace SfphpProject\src\Cache;

/**
 * A cache held in this process's memory.
 *
 * Values go through the same JSON round trip as the file and Redis drivers,
 * so a test written against this driver sees what production will: an object
 * put in comes back as an array, and a value JSON cannot hold is refused
 * here rather than in production.
 */
class MemoryDriver implements Cache
{
    /** @var array<string, array{value: string, expires: int|null}> */
    protected array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->live($key);

        return $item === null ? $default : json_decode($item['value'], true);
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $this->store[$key] = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'expires' => Ttl::expiresAt($seconds),
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
        return $this->live($key) !== null;
    }

    public function prune(): int
    {
        $removed = 0;

        foreach (array_keys($this->store) as $key) {
            if (Ttl::expired($this->store[$key]['expires'])) {
                unset($this->store[$key]);
                $removed++;
            }
        }

        return $removed;
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
        $expiresAt = Ttl::expiresAt($seconds);
        $item = $this->live($key);

        if ($item === null) {
            $this->store[$key] = ['value' => (string) $by, 'expires' => $expiresAt];

            return $by;
        }

        $value = (int) json_decode($item['value'], true) + $by;
        $this->store[$key]['value'] = (string) $value;

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
        $item = $this->live($key);

        return $item === null || $item['expires'] === null ? null : max(0, $item['expires'] - time());
    }

    /**
     * @return array{value: string, expires: int|null}|null
     */
    private function live(string $key): ?array
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }

        if (Ttl::expired($this->store[$key]['expires'])) {
            unset($this->store[$key]);

            return null;
        }

        return $this->store[$key];
    }
}
