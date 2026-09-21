<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Csrf;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\I18n\Translator;

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
 * bearer token is not sent automatically by a browser, which is the attack CSRF
 * describes, so such a request is skipped.
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

        $title = __('http.csrf_title');
        $message = __('http.csrf_message');

        if ($request->expectsJson()) {
            return Response::json(['message' => $message], HTTP_FORBIDDEN);
        }

        $language = str_replace('_', '-', Translator::locale());
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(
            '<!doctype html><html lang="' . $language . '"><head><meta charset="UTF-8">'
            . '<title>' . $heading . '</title></head><body><h1>403</h1>'
            . '<p>' . $escaped . '</p></body></html>',
            HTTP_FORBIDDEN
        );
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
         */
        if ($request->bearerToken() !== null) {
            return true;
        }

        foreach ($this->except as $prefix) {
            if (str_starts_with($request->path, $prefix)) {
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
        foreach ([
            $request->body('_token'),
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
