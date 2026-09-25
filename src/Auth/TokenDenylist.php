<?php

namespace SfphpProject\src\Auth;

use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\JWT;
use SfphpProject\src\Time;

/**
 * Makes a token stop working before it expires.
 *
 * A JWT is valid because its signature is valid, which is what makes it
 * checkable without asking anything — and also what means nothing can take it
 * back. Someone whose account you just disabled keeps working until their token
 * runs out, and "log out on every device" cannot be honoured at all.
 *
 * The only way to revoke a stateless token is to stop being stateless about the
 * ones you have revoked. That is the trade being made here, and it is worth
 * seeing plainly: the guard now asks a store on every request, so a token is no
 * longer free to verify.
 *
 * What keeps the cost small is that the store only has to remember a token
 * until it would have expired anyway. A denylist of everything ever revoked
 * would grow forever; this one is entries with a lifetime, so it stays the size
 * of "revoked recently".
 *
 *     TokenDenylist::revoke($token);          // this one token
 *     TokenDenylist::revokeUser($user->id);   // every token issued before now
 */
final class TokenDenylist
{
    private static ?CacheManager $cache = null;

    /**
     * Use this cache instead of the shared one.
     *
     * With the default file driver the denylist is local to one machine, which
     * means a token revoked on one instance still works on another. A shared
     * driver is not optional here the way it is elsewhere.
     *
     * @param CacheManager|null $cache The store, or null for the shared one
     * @return void
     */
    public static function useCache(?CacheManager $cache): void
    {
        self::$cache = $cache;
    }

    /**
     * Stop accepting one token.
     *
     * @param string $token The token to revoke
     * @return bool True when it was revoked, false when it was already invalid
     */
    public static function revoke(string $token): bool
    {
        $claims = JWT::claims($token);

        if ($claims === null) {
            // Nothing to revoke: an invalid token is already being refused.
            return false;
        }

        $expiresAt = (int) ($claims['exp'] ?? 0);
        $seconds = max(1, $expiresAt - Time::now()->getTimestamp());

        self::cache()->put(self::key($token), 1, $seconds);

        return true;
    }

    /**
     * Stop accepting every token issued to a user before now.
     *
     * This is "log out everywhere". It works by remembering when a user's
     * tokens stopped counting, rather than by listing them — the tokens
     * themselves were never recorded anywhere, so there is nothing to list.
     *
     * @param string|int $identifier The user's key
     * @param int|null $lifetime How long to remember, or null for the token lifetime
     * @return void
     */
    public static function revokeUser(string|int $identifier, ?int $lifetime = null): void
    {
        self::cache()->put(
            self::userKey($identifier),
            Time::now()->getTimestamp(),
            $lifetime ?? JWT::lifetime()
        );
    }

    /**
     * Whether a token has been revoked.
     *
     * @param string $token The token
     * @param array<string, mixed>|null $claims Its claims, when already read
     * @return bool True when the token must be refused
     */
    public static function isRevoked(string $token, ?array $claims = null, string $claim = 'id'): bool
    {
        if (self::cache()->has(self::key($token))) {
            return true;
        }

        $claims ??= JWT::claims($token);

        if ($claims === null) {
            return false;
        }

        /*
         * The claim the guard identifies users by. It was always "id" here,
         * so a TokenGuard configured with another claim revoked nothing when
         * revokeUser() was called.
         */
        $identifier = $claims[$claim] ?? null;

        if ($identifier === null) {
            return false;
        }

        $revokedAt = self::cache()->get(self::userKey($identifier));

        if ($revokedAt === null) {
            return false;
        }

        /*
         * "iat" is when the token was issued. A token issued before the user's
         * tokens were revoked is one of the tokens that was revoked; one issued
         * after is a fresh login and has to keep working, or logging out
         * everywhere would lock the user out of logging back in.
         */
        $issuedAt = (int) ($claims['iat'] ?? 0);

        return $issuedAt <= (int) $revokedAt;
    }

    /**
     * The key under which one token is remembered.
     *
     * The token is hashed rather than stored. A cache that someone can read —
     * a shared Redis, a dump taken for debugging — would otherwise hand out
     * working credentials for every token that has not expired yet.
     *
     * @param string $token The token
     * @return string The cache key
     */
    private static function key(string $token): string
    {
        return 'auth:revoked:' . hash('sha256', $token);
    }

    /**
     * The key under which a user's revocation time is remembered.
     *
     * @param string|int $identifier The user's key
     * @return string The cache key
     */
    private static function userKey(string|int $identifier): string
    {
        return 'auth:revoked-user:' . hash('sha256', (string) $identifier);
    }

    /**
     * The store.
     *
     * @return CacheManager The cache
     */
    private static function cache(): CacheManager
    {
        return self::$cache ??= cache();
    }
}
