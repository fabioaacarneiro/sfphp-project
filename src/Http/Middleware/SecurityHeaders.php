<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Adds the response headers that tell a browser what not to do.
 *
 * Each of these closes an attack the application cannot close by itself,
 * because the decision belongs to the browser:
 *
 *   X-Content-Type-Options   stops MIME sniffing, so an uploaded file served
 *                            as text/plain is not executed as JavaScript
 *                            because its first bytes look like a script.
 *   X-Frame-Options          stops clickjacking — the site being framed
 *                            invisibly over something the visitor means to
 *                            click.
 *   Referrer-Policy          stops a full URL, including anything in its query
 *                            string, from leaking to every site a visitor
 *                            follows a link to.
 *
 * Two headers are deliberately off unless asked for.
 *
 * Content-Security-Policy is the strongest of them and the easiest to get
 * wrong: a policy that does not match the application's own assets breaks the
 * page with no error the developer sees, and the framework cannot know what
 * those assets are. The documentation carries a recommended value to start
 * from.
 *
 * Strict-Transport-Security is off because turning it on is hard to undo — a
 * browser that has seen it refuses plain HTTP for the whole max-age, including
 * for a site that later has to serve HTTP for a reason. It is also only sent
 * over a secure connection, since a browser must ignore it otherwise and
 * sending it over HTTP would be a false sense of protection.
 */
final class SecurityHeaders implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param string|null $contentSecurityPolicy The policy, or null to send none
     * @param string $frameOptions DENY or SAMEORIGIN
     * @param string $referrerPolicy The referrer policy
     * @param int|null $hstsMaxAge Seconds for HSTS, or null to send none
     * @param bool $hstsIncludeSubdomains Whether HSTS covers subdomains
     */
    public function __construct(
        private ?string $contentSecurityPolicy = null,
        private string $frameOptions = 'DENY',
        private string $referrerPolicy = 'strict-origin-when-cross-origin',
        private ?int $hstsMaxAge = null,
        private bool $hstsIncludeSubdomains = false
    ) {}

    /**
     * Add the headers on the way out.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', $this->frameOptions)
            ->withHeader('Referrer-Policy', $this->referrerPolicy);

        if ($this->contentSecurityPolicy !== null) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                $this->contentSecurityPolicy
            );
        }

        /*
         * Only over HTTPS. A browser ignores HSTS on a plain connection, so
         * sending it there would achieve nothing while looking like it had.
         */
        if ($this->hstsMaxAge !== null && $request->isSecure()) {
            $value = 'max-age=' . $this->hstsMaxAge;

            if ($this->hstsIncludeSubdomains) {
                $value .= '; includeSubDomains';
            }

            $response = $response->withHeader('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
