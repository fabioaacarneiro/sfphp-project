<?php

namespace SfphpProject\src\Auth;

use SfphpProject\src\Http\Request;

/**
 * How a request proves who it is.
 *
 * A guard answers one question — which user, if any, this request belongs to —
 * and gets the answer from the request itself: a session cookie, a bearer
 * token, a signed header. Where the user is then looked up is the provider's
 * job, which is why the two are separate.
 */
interface Guard
{
    /**
     * Identify the user behind a request.
     *
     * @param Request $request The incoming request
     * @return Authenticatable|null The user, or null when the request is anonymous
     */
    public function resolve(Request $request): ?Authenticatable;
}
