<?php

namespace SfphpProject\src\Auth;

/**
 * Password hashing, over PHP's own implementation.
 *
 * This is a thin wrapper on purpose. password_hash() already picks a sound
 * algorithm, generates the salt, and encodes the parameters into the result so
 * the hash carries everything needed to verify it. Writing anything cleverer
 * here would be a step backwards.
 *
 * PASSWORD_DEFAULT is used rather than naming an algorithm, so a PHP upgrade
 * that adopts a better default is picked up for new passwords automatically —
 * and needsRehash() is how existing ones catch up.
 */
final class Hash
{
    /**
     * Hash a plain-text password.
     *
     * @param string $plain The password as the user typed it
     * @param array<string, int> $options Algorithm options, such as cost
     * @return string The hash, safe to store
     */
    public static function make(string $plain, array $options = []): string
    {
        return password_hash($plain, PASSWORD_DEFAULT, $options);
    }

    /**
     * Check a plain-text password against a stored hash.
     *
     * The comparison is done by password_verify(), which takes constant time
     * with respect to the hash, so a wrong password cannot be distinguished
     * from an almost-right one by timing.
     *
     * @param string $plain The password as the user typed it
     * @param string $hash The stored hash
     * @return bool True when the password matches
     */
    public static function check(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Check whether a stored hash should be replaced.
     *
     * Call this after a successful login, while the plain-text password is
     * still in hand, and re-hash when it returns true. It is the only moment
     * an application can upgrade a password's algorithm without asking the
     * user to type it again.
     *
     * @param string $hash The stored hash
     * @param array<string, int> $options Algorithm options, such as cost
     * @return bool True when the hash uses outdated parameters
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, $options);
    }
}
