<?php

namespace SfphpProject\src\Cache;

/**
 * What every cache driver does.
 *
 * Lifetimes mean the same thing in every driver: null or 0 never expires, a
 * positive number is seconds from now, a negative one is refused. Values are
 * what JSON can hold — null, scalars and arrays; an object put in comes back
 * as an array. has() says whether a live entry exists, even one holding null.
 */
interface Cache
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, ?int $seconds = null): void;

    public function forget(string $key): void;

    public function flush(): void;

    public function has(string $key): bool;

    /**
     * Remove the entries that have expired, and leave the rest.
     *
     * This is what `./sfphp cache:clear` runs. flush() removes everything,
     * including what is not a cached page at all — revoked tokens, rate-limit
     * counters, sessions kept in the cache.
     *
     * @return int How many entries were removed
     */
    public function prune(): int;

    /**
     * Add to a counter and return its new value, atomically.
     *
     * This exists because `get()` then `put()` is not a counter. Two requests
     * that read 4 both write 5, and one of the two is lost. That is tolerable
     * for a cached page and not tolerable for a rate limiter, which is counting
     * precisely when several requests arrive at once — someone trying passwords
     * sends them in parallel, not one after another.
     *
     * The expiry is set only when the counter is created. A counter that
     * already exists keeps the expiry it had, so a client that keeps knocking
     * cannot push its own window forward and stay inside the limit forever.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int;

    /**
     * How many seconds remain before an entry expires.
     *
     * @param string $key The entry's key
     * @return int|null The seconds remaining, or null when the key is absent or never expires
     */
    public function ttl(string $key): ?int;
}
