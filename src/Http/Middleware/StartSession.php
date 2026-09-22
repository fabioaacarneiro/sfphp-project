<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Config;
use SessionHandlerInterface;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Session\CacheHandler;
use SfphpProject\src\Session\DatabaseHandler;
use SfphpProject\src\Session\Session;

/**
 * Starts the session before the request is handled.
 *
 * The session used to be started from public/index.php. Moving it into the
 * pipeline puts it in the same ordered list as everything else that runs before
 * a controller, so the order is readable instead of implied by the order of
 * statements in the front controller.
 *
 * It is also where the two deadlines are applied, which is the only place they
 * can be applied once and cover every route.
 */
final class StartSession implements Middleware
{
    private ?SessionHandlerInterface $handler;

    /**
     * Create the middleware.
     *
     * Everything defaults to the configuration, so the common case is
     * `StartSession::class` in the pipeline with nothing to pass.
     *
     * @param SessionHandlerInterface|null $handler Where sessions are stored, or null to follow SESSION_DRIVER
     * @param int|null $idleSeconds Inactivity allowed, or null for SESSION_LIFETIME
     * @param int|null $absoluteSeconds Lifetime allowed, or null for SESSION_ABSOLUTE_LIFETIME
     */
    public function __construct(
        ?SessionHandlerInterface $handler = null,
        private ?int $idleSeconds = null,
        private ?int $absoluteSeconds = null
    ) {
        $this->handler = $handler ?? self::configuredHandler();
    }

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
         * The request decides whether the cookie is secure, not the session
         * layer. Behind a TLS-terminating proxy the PHP process sees plain
         * HTTP, and a session cookie without the "secure" flag travels in the
         * clear the moment a visitor reaches the site over HTTP.
         * Request::isSecure() consults the trusted-proxy configuration.
         */
        Session::start(
            $request->isSecure(),
            $this->handler,
            $this->idleSeconds ?? (Config::int('SESSION_LIFETIME', 0)),
            $this->absoluteSeconds ?? (Config::int('SESSION_ABSOLUTE_LIFETIME', 0))
        );

        return $next($request);
    }

    /**
     * The handler SESSION_DRIVER names.
     *
     * @return SessionHandlerInterface|null The handler, or null for PHP's own files
     */
    private static function configuredHandler(): ?SessionHandlerInterface
    {
        $driver = Config::get('SESSION_DRIVER', 'native');

        return match ($driver) {
            'database' => new DatabaseHandler(Config::get('SESSION_TABLE', 'sessions')),
            'cache' => new CacheHandler(),
            default => null,
        };
    }
}
