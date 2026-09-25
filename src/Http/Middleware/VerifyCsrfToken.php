<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Csrf;
use SfphpProject\src\Http\ErrorPage;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Rejects state-changing requests that arrive without a valid CSRF token.
 *
 * The framework has had CSRF tokens, a constant-time comparison and the
 * helpers to render them since well before this class existed — but nothing
 * ever called the verification, so every application had to remember to do it
 * by hand in each action, and forgetting produced no error. A pipeline is the
 * first place the check can live where it applies by default.
 *
 * Safe methods pass through untouched, because they are not supposed to change
 * state and blocking them would break ordinary navigation.
 *
 * Token-authenticated APIs should not be behind this. A request carrying a
 * bearer token and no session cookie is not sent automatically by a browser,
 * which is the attack CSRF describes, so such a request is skipped.
 *
 * The token is read from the `_token` form field, from `_token` in a JSON
 * body, or from the X-CSRF-Token / X-XSRF-Token header. SFJS submits a form
 * as JSON, and a JSON body used to be the one place the check did not look:
 * a form with csrf_field() sent through SFJS was refused as expired.
 */
final class VerifyCsrfToken implements Middleware
{
    /**
     * Methods that are not expected to change state.
     */
    private const SAFE_METHODS = [GET, HEAD, OPTIONS];

    /**
     * Paths exempt from verification, as prefixes.
     *
     * @param array<int, string> $except Path prefixes to skip, such as "/api"
     */
    public function __construct(private array $except = []) {}

    /**
     * Verify the token and continue, or refuse the request.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        if ($this->shouldSkip($request) || Csrf::validate($this->token($request))) {
            return $next($request);
        }

        return ErrorPage::response(HTTP_FORBIDDEN, __('http.csrf_message'), $request, __('http.csrf_title'));
    }

    /**
     * Determine whether this request needs no verification.
     *
     * @param Request $request The incoming request
     * @return bool True when the request is exempt
     */
    private function shouldSkip(Request $request): bool
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return true;
        }

        /*
         * A bearer token is attached by the client on purpose; a browser never
         * sends one on its own, so there is no cross-site request to forge.
         * Only when there is no session cookie, though: a request that carries
         * the session is one a browser can send by itself, and a forged
         * Authorization header must not switch the check off for it.
         */
        if ($request->bearerToken() !== null && $request->cookie(session_name() ?: 'PHPSESSID') === null) {
            return true;
        }

        /*
         * By path segment. A plain prefix let "/api" exempt "/apikeys" and
         * "/api-admin" as well.
         */
        foreach ($this->except as $prefix) {
            $prefix = rtrim($prefix, '/');

            if ($prefix === '' || $request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the submitted token from the form field or the usual headers.
     *
     * @param Request $request The incoming request
     * @return string|null The token, or null when absent
     */
    private function token(Request $request): ?string
    {
        $json = null;

        if (str_contains(strtolower((string) $request->header('Content-Type')), 'json')) {
            try {
                $json = $request->json()['_token'] ?? null;
            } catch (\JsonException) {
                $json = null;
            }
        }

        foreach ([
            $request->body('_token'),
            $json,
            $request->header('X-CSRF-Token'),
            $request->header('X-XSRF-Token'),
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
