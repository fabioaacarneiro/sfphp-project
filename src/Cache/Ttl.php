<?php

namespace SfphpProject\src\Cache;

use InvalidArgumentException;

/**
 * What a cache lifetime means, in one place.
 *
 * The drivers used to disagree: 0 meant "forever" to the file and memory
 * drivers and was an error in Redis, which rejects SETEX with 0. Now every
 * driver reads a lifetime the same way — null or 0 never expires, a positive
 * number is seconds from now, and a negative number is a mistake.
 */
final class Ttl
{
    /**
     * The Unix time an entry stored now expires, or null for never.
     *
     * @param int|null $seconds The lifetime
     * @return int|null The expiry time
     * @throws InvalidArgumentException When the lifetime is negative
     */
    public static function expiresAt(?int $seconds): ?int
    {
        return self::forever($seconds) ? null : time() + $seconds;
    }

    /**
     * Whether a lifetime means "never expires".
     *
     * @param int|null $seconds The lifetime
     * @return bool
     * @throws InvalidArgumentException When the lifetime is negative
     */
    public static function forever(?int $seconds): bool
    {
        if ($seconds !== null && $seconds < 0) {
            throw new InvalidArgumentException(sprintf(
                'A cache lifetime cannot be negative (%d). Use null or 0 for an entry that never expires, or forget() to remove one.',
                $seconds
            ));
        }

        return $seconds === null || $seconds === 0;
    }

    /**
     * Whether an entry with this expiry time has expired.
     *
     * @param mixed $expires The stored expiry time, or null for never
     * @return bool
     */
    public static function expired(mixed $expires): bool
    {
        return $expires !== null && (int) $expires < time();
    }
}
