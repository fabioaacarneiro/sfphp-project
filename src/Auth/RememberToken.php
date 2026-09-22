<?php

namespace SfphpProject\src\Auth;

use SfphpProject\src\Time;

/**
 * The "remember me" cookie, and the rule that keeps it from being a password.
 *
 * The `remember_token` column has been in the users migration since the
 * beginning with nothing reading it. Implementing it is mostly about one
 * decision, so it is worth stating rather than burying: **the cookie is not a
 * credential you can replay.**
 *
 * A remember cookie is a password that never expires and that the user does not
 * know they have. What limits the damage is that the token is rotated on every
 * use: a cookie works exactly once, and the next request carries its
 * replacement. If a stolen cookie is used, the real user's next request fails
 * and the theft is visible, instead of two people sharing an account quietly
 * for a month.
 *
 * The selector-and-verifier shape is what makes that possible without a table
 * scan: the cookie carries a lookup key in the clear and a secret that is only
 * stored hashed, so a database someone reads does not hand them working
 * cookies.
 *
 *     $cookie = RememberToken::issue($user);   // "selector:verifier"
 *
 *     setcookie('remember', $cookie, [...]);
 */
final class RememberToken
{
    /** How long a remember cookie stays valid, in seconds. */
    public const LIFETIME = 60 * 60 * 24 * 30;

    /**
     * Build a new token for a user, returning the cookie value.
     *
     * The caller stores the returned string in a cookie and the hash in the
     * user's row — `$user->setRememberToken()` if the model has it, or however
     * the application persists it.
     *
     * @param string $selector A lookup key, generated when omitted
     * @return array{cookie: string, selector: string, hash: string, expires: int}
     */
    public static function issue(?string $selector = null): array
    {
        $selector ??= bin2hex(random_bytes(8));
        $verifier = bin2hex(random_bytes(32));

        return [
            'cookie' => $selector . ':' . $verifier,
            'selector' => $selector,
            /*
             * Hashed, not stored. The column is the one thing an attacker with
             * read access to the database gets for free, and a plain verifier
             * there is a working cookie for every remembered user.
             *
             * sha256 rather than password_hash: this is a 32-byte random value,
             * not a password, so there is nothing to brute-force and no reason
             * to pay bcrypt's cost on every request that carries a cookie.
             */
            'hash' => hash('sha256', $verifier),
            'expires' => Time::now()->getTimestamp() + self::LIFETIME,
        ];
    }

    /**
     * Split a cookie into its two halves.
     *
     * @param string $cookie The cookie value
     * @return array{selector: string, verifier: string}|null The parts, or null when malformed
     */
    public static function parse(string $cookie): ?array
    {
        $parts = explode(':', $cookie, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return ['selector' => $parts[0], 'verifier' => $parts[1]];
    }

    /**
     * Whether a cookie's verifier matches the stored hash.
     *
     * Compared in constant time. The verifier is a secret being checked against
     * a stored value, which is the situation `hash_equals` exists for: a
     * comparison that returns early tells an attacker how much of their guess
     * was right.
     *
     * @param string $verifier The verifier from the cookie
     * @param string $storedHash The hash from the user's row
     * @return bool True when they match
     */
    public static function matches(string $verifier, string $storedHash): bool
    {
        return hash_equals($storedHash, hash('sha256', $verifier));
    }
}
