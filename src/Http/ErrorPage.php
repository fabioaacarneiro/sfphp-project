<?php

namespace SfphpProject\src\Http;

use SfphpProject\src\Assets;
use Throwable;

/**
 * The page, or the JSON, the framework answers an error with.
 *
 * There used to be four of these: the router's 404 and 405, the error
 * handler's 500, and bare unstyled pages written inline by the CSRF check and
 * the rate limiter. They disagreed on whether to offer JSON, whether to link
 * home and whether to be styled at all. Every framework error goes through
 * this one now.
 */
final class ErrorPage
{
    /**
     * The classes the page uses, which is all of SFCSS it inlines.
     */
    private const CLASSES = ['sf-error', 'text-center', 'max-w-lg', 'font-bold', 'm-0', 'text-lg', 'text-muted', 'mt-4', 'mb-6', 'btn', 'btn-primary'];

    /**
     * Which catalog entries describe each status.
     */
    private const KEYS = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        429 => 'too_many_requests',
        500 => 'server_error',
        503 => 'unavailable',
    ];

    /**
     * Answer with an error.
     *
     * @param int $status The status code
     * @param string|null $message What to tell the visitor, or null for the status's standard message
     * @param Request|null $request The request, to choose between JSON and HTML
     * @param string|null $title The page title, or null for the status's standard title
     * @return Response The response
     */
    public static function response(int $status, ?string $message = null, ?Request $request = null, ?string $title = null): Response
    {
        $message ??= self::text($status, 'message');
        $title ??= self::text($status, 'title');

        if (self::expectsJson($request)) {
            return Response::json(['message' => $message], $status);
        }

        $language = function_exists('locale') ? str_replace('_', '-', locale()) : 'en';
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $home = function_exists('__') ? __('http.back_home') : 'Back to the home page';

        return Response::html(
            '<!doctype html><html lang="' . $escape($language) . '" data-theme="auto">'
            . '<head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $escape($title) . '</title>'
            . '<link rel="icon" href="data:,">'
            . '<style>' . self::stylesheet() . '</style>'
            . '<style>body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}'
            . '.sf-error h1{font-size:clamp(3.5rem,15vw,5rem);line-height:1;letter-spacing:-.02em}</style>'
            . '</head>'
            . '<body><main class="sf-error text-center max-w-lg">'
            . '<h1 class="font-bold m-0">' . $status . '</h1>'
            . '<p class="text-lg text-muted mt-4 mb-6">' . $escape($message) . '</p>'
            . '<a class="btn btn-primary" href="/">' . $escape($home) . '</a>'
            . '</main></body></html>',
            $status
        );
    }

    /**
     * The standard title or message for a status, translated.
     *
     * @param int $status The status code
     * @param string $part "title" or "message"
     * @return string The text
     */
    public static function text(int $status, string $part): string
    {
        $key = self::KEYS[$status] ?? ($status >= 500 ? 'server_error' : null);

        if ($key === null) {
            return $part === 'title' ? (string) $status : 'Error ' . $status;
        }

        return function_exists('__') ? __('http.' . $key . '_' . $part) : ($part === 'title' ? (string) $status : 'Error ' . $status);
    }

    /**
     * Only the part of SFCSS this page uses.
     *
     * Guarded because this also runs on the shutdown path, where a fatal can
     * mean the stylesheet cannot be read: an unstyled message is a better
     * failure than an error page that fails itself.
     */
    private static function stylesheet(): string
    {
        try {
            return Assets::cssFor(self::CLASSES);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Whether the client wants JSON.
     *
     * Falls back to the server environment when there is no request, which is
     * the case on the shutdown path.
     */
    private static function expectsJson(?Request $request): bool
    {
        if ($request !== null) {
            return $request->expectsJson();
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
    }
}
