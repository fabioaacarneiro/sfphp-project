<?php

namespace SfphpProject\src\Auth;

use SfphpProject\src\Session\Session;

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

        $identifier = Session::get(self::SESSION_KEY);

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
            Session::forget(self::SESSION_KEY);
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

        /*
         * A new CSRF token as well as a new id. The token issued before login
         * may have been seen by whoever planted the session, and it kept
         * working for the authenticated session afterwards.
         */
        Csrf::rotate();

        Session::put(self::SESSION_KEY, $user->getAuthIdentifier());
    }

    /**
     * Forget the logged-in user.
     *
     * @return void
     */
    public function logout(): void
    {
        Csrf::startSession();

        /*
         * Everything goes, not only the user id: a cart, a flash message or
         * anything else the application kept for this user would otherwise
         * be handed to whoever uses the browser next. A new id is issued too,
         * because the old one was seen by the browser.
         */
        Session::invalidate();
    }
}
