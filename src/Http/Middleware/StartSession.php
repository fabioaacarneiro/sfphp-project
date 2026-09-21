<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Csrf;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Starts the PHP session before the request is handled.
 *
 * The session used to be started from public/index.php. Moving it into the
 * pipeline puts it in the same ordered list as everything else that runs
 * before a controller, so the order is readable instead of implied by the
 * order of statements in the front controller.
 */
final class StartSession implements Middleware
{
    /**
     * Start the session and continue.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        /*
         * The request decides, not the session layer. Behind a TLS-terminating
         * proxy the PHP process sees plain HTTP, and a session cookie without
         * the "secure" flag travels in the clear the moment a visitor reaches
         * the site over HTTP. Request::isSecure() consults the trusted-proxy
         * configuration to get this right.
         */
        Csrf::startSession($request->isSecure());

        return $next($request);
    }
}
