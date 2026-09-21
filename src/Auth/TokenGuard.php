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
 * what makes this usable from a worker or a second process — and also what
 * means a token cannot be revoked before it expires.
 */
final class TokenGuard implements Guard
{
    /**
     * Create the guard.
     *
     * @param UserProvider $provider Where users are looked up
     * @param string $claim The claim carrying the identifier
     */
    public function __construct(
        private UserProvider $provider,
        private string $claim = 'id'
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

        return $this->provider->retrieveById($claims[$this->claim]);
    }
}
