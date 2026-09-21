<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Auth\Auth;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Identifies the user behind a request.
 *
 * Two modes. Registered globally it only resolves, leaving anonymous requests
 * to carry on — which is what a public page needs. Constructed with
 * required: true and attached to a route or group, it refuses an anonymous
 * request instead.
 *
 *     $router->middleware(new Authenticate());              // resolve only
 *     Router::get('/admin', 'AdminController', 'index')
 *         ->middleware(new Authenticate(required: true));   // refuse anonymous
 *
 * It clears the previously resolved user before doing anything. Auth holds the
 * current user in a static so that asking twice does not query twice, and
 * under a persistent runtime that static would otherwise carry one visitor's
 * identity into the next request. This middleware is the explicit owner that
 * resets it, exactly as SetLocale is for the active locale.
 */
final class Authenticate implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param string|null $guard The guard to ask, or null for the default
     * @param bool $required Whether an anonymous request is refused
     */
    public function __construct(
        private ?string $guard = null,
        private bool $required = false
    ) {}

    /**
     * Resolve the user and continue, or refuse.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        Auth::forgetUser();

        $user = Auth::resolve($request, $this->guard);

        if ($user === null && $this->required) {
            return $this->refuse($request);
        }

        return $next($request->withAttribute('user', $user));
    }

    /**
     * Refuse an anonymous request.
     *
     * @param Request $request The incoming request
     * @return Response The refusal
     */
    private function refuse(Request $request): Response
    {
        $message = __('auth.unauthenticated');

        if ($request->expectsJson()) {
            return Response::json(['message' => $message], HTTP_UNAUTHORIZED);
        }

        /*
         * A browser is sent to the login page rather than shown a 401, because
         * a 401 without a WWW-Authenticate header makes some browsers show
         * their own credentials prompt, which is not the form the application
         * has.
         */
        return Response::redirect('/login');
    }
}
