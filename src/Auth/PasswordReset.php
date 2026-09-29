<?php

namespace SfphpProject\src\Auth;

use RuntimeException;
use SfphpProject\src\Env;
use SfphpProject\src\Time;

/**
 * The token a "forgot your password" link carries.
 *
 *     $token = PasswordReset::token($user);          // put it in the e-mailed link
 *
 *     $user = PasswordReset::user($token);           // on the reset page: the user, or null
 *     if ($user !== null) { ... set the new password ... }
 *
 * Nothing is stored. The token is the user's id and an expiry, signed with
 * JWT_KEY over the user's current password hash — so it stops working at the
 * expiry, and the moment the password changes: once the reset has happened,
 * the link that did it is dead, and so is every other link issued before it.
 * No table, no clean-up job, and a leaked database holds no working tokens.
 *
 * What it does not do is send the e-mail, draw the forms, or refuse a user
 * who asks too often. Those are the application's, and rate limiting the
 * "send me a link" form is RateLimit's.
 */
final class PasswordReset
{
    /** How long a link works when the caller says nothing, in seconds. */
    public const LIFETIME = 3600;

    /** What the signature is made for, so it can never pass for another. */
    private const PURPOSE = 'sfphp-password-reset';

    /**
     * A token for a user.
     *
     * @param Authenticatable $user Who it is for
     * @param int $lifetime Seconds it stays valid
     * @return string URL-safe; put it in the link as it is
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function token(Authenticatable $user, int $lifetime = self::LIFETIME): string
    {
        $id = (string) $user->getAuthIdentifier();
        $expires = Time::now()->getTimestamp() + max(1, $lifetime);

        return self::encode($id) . '.' . $expires . '.' . self::sign($id, $expires, $user->getAuthPassword());
    }

    /**
     * The user a token was issued for, if it still works.
     *
     * Null for a token that is malformed, expired, signed with another key,
     * for a user who no longer exists, or issued before the user's password
     * last changed.
     *
     * @param string $token What the link carried
     * @param UserProvider|null $provider Where users are found, or null for Auth::provider()
     * @return Authenticatable|null
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function user(string $token, ?UserProvider $provider = null): ?Authenticatable
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || !ctype_digit($parts[1])) {
            return null;
        }

        [$encodedId, $expires, $signature] = $parts;
        $id = self::decode($encodedId);

        if ($id === null || (int) $expires < Time::now()->getTimestamp()) {
            return null;
        }

        $user = ($provider ?? Auth::provider())->retrieveById($id);

        if ($user === null) {
            return null;
        }

        return hash_equals(self::sign($id, (int) $expires, $user->getAuthPassword()), $signature) ? $user : null;
    }

    private static function sign(string $id, int $expires, string $passwordHash): string
    {
        return self::encode(hash_hmac('sha256', self::PURPOSE . '|' . $id . '|' . $expires . '|' . $passwordHash, self::key(), true));
    }

    /**
     * JWT_KEY, held to the same rules the JWT class holds it to.
     *
     * One secret for both is safe because each signature says what it is for:
     * a JWT's never starts with this class's purpose, so neither can be passed
     * off as the other.
     */
    private static function key(): string
    {
        $key = (string) (Env::get('JWT_KEY') ?? '');

        if ($key === '' || $key === 'your_secret_token_here') {
            throw new RuntimeException('JWT_KEY is not set. Define it in your .env file before issuing or checking a password reset token.');
        }

        if (strlen($key) < 32) {
            throw new RuntimeException('JWT_KEY must be at least 32 bytes long.');
        }

        return $key;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
