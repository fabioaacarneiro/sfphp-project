<?php

namespace SfphpProject\src\Auth;

use RuntimeException;
use SfphpProject\src\Http\Request;
use SfphpProject\src\JWT;

/**
 * Identifies a user from a bearer token.
 *
 * The token is a JWT signed with JWT_KEY, so it carries the identifier and
 * proves it was not tampered with. Nothing is stored server-side, which is
 * what makes this usable from a worker or a second process. It is also why
 * revocation costs something: a signed token carries no way to take it back, so
 * the guard consults TokenDenylist on every request unless told not to.
 */
final class TokenGuard implements Guard
{
    /**
     * Create the guard.
     *
     * @param UserProvider $provider Where users are looked up
     * @param string $claim The claim carrying the identifier
     * @param bool $checkRevocation Whether to consult the denylist on every request
     */
    public function __construct(
        private UserProvider $provider,
        private string $claim = 'id',
        private bool $checkRevocation = true
    ) {}

    /**
     * Identify the user behind a request.
     *
     * @param Request $request The incoming request
     * @return Authenticatable|null The user, or null when the request is anonymous
     */
    public function resolve(Request $request): ?Authenticatable
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        try {
            $claims = JWT::claims($token);
        } catch (RuntimeException) {
            /*
             * JWT_KEY missing or too short. That is a deployment fault, not an
             * authentication one, and it is already logged where it is raised;
             * treating the request as anonymous here keeps a misconfigured
             * server from leaking the reason to whoever is knocking.
             */
            return null;
        }

        if ($claims === null || !isset($claims[$this->claim])) {
            return null;
        }

        /*
         * A signature being valid is not the same as a token being accepted.
         * Checking the denylist is what lets an account be disabled, or a
         * device logged out, before the token would have expired on its own —
         * and it is the price of revoking something stateless.
         */
        if ($this->checkRevocation && TokenDenylist::isRevoked($token, $claims, $this->claim)) {
            return null;
        }

        return $this->provider->retrieveById($claims[$this->claim]);
    }
}
