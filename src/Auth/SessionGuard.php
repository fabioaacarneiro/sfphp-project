<?php

namespace SfphpProject\src\Auth;

use SfphpProject\src\Csrf;
use SfphpProject\src\Http\Request;

/**
 * Identifies a user from the PHP session.
 *
 * The session stores only the identifier, never the user itself. Serialising a
 * model into the session would freeze a copy of the row: a user whose
 * permissions were revoked would keep them until the session expired, and a
 * renamed column would break deserialisation of every live session.
 */
final class SessionGuard implements Guard
{
    private const SESSION_KEY = '_auth_id';

    /**
     * Create the guard.
     *
     * @param UserProvider $provider Where users are looked up
     */
    public function __construct(private UserProvider $provider) {}

    /**
     * Identify the user behind a request.
     *
     * @param Request $request The incoming request
     * @return Authenticatable|null The user, or null when the request is anonymous
     */
    public function resolve(Request $request): ?Authenticatable
    {
        Csrf::startSession();

        $identifier = $_SESSION[self::SESSION_KEY] ?? null;

        if ($identifier === null) {
            return null;
        }

        $user = $this->provider->retrieveById($identifier);

        /*
         * A session pointing at a user that no longer exists is cleared rather
         * than left in place, so a deleted account does not keep a browser in
         * a half-authenticated state for the life of the cookie.
         */
        if ($user === null) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $user;
    }

    /**
     * Record a user as logged in.
     *
     * The session id is regenerated, which is what stops session fixation: an
     * attacker who planted a known session id before the login cannot use it
     * afterwards, because the id the victim ends up with is a new one.
     *
     * @param Authenticatable $user The user to remember
     * @return void
     */
    public function login(Authenticatable $user): void
    {
        Csrf::startSession();

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = $user->getAuthIdentifier();
    }

    /**
     * Forget the logged-in user.
     *
     * @return void
     */
    public function logout(): void
    {
        Csrf::startSession();

        unset($_SESSION[self::SESSION_KEY]);

        /*
         * A new id is issued on the way out too: the old one was seen by the
         * browser, and possibly by whatever shared the machine with it.
         */
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
