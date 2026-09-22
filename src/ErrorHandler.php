<?php

namespace SfphpProject\src;

use ErrorException;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Assets;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use Throwable;

/**
 * Converts uncaught PHP failures into safe HTTP responses.
 *
 * The global registration stays even now that the router catches failures in
 * the pipeline, because two of these handlers cover ground a try/catch
 * structurally cannot: set_error_handler applies during bootstrap, before any
 * request exists, and register_shutdown_function is the only way to report a
 * fatal — out of memory, exceeded execution time, a parse error in an included
 * file. Remove it and those become blank pages.
 */
final class ErrorHandler
{
    /**
     * Register handlers for exceptions, PHP errors, and fatal shutdown errors.
     *
     * @return void
     */
    public static function register(): void
    {
        set_error_handler([self::class, 'handlePhpError']);
        set_exception_handler([self::class, 'handleThrowable']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Convert non-suppressed PHP errors into exceptions.
     *
     * @param int $severity The PHP error severity
     * @param string $message The PHP error message
     * @param string $file The file where the error occurred
     * @param int $line The line where the error occurred
     * @return bool False for suppressed errors
     * @throws ErrorException For non-suppressed errors
     */
    public static function handlePhpError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Log an uncaught exception and send an HTTP error response.
     *
     * @param Throwable $throwable The uncaught failure
     * @return never
     */
    public static function handleThrowable(Throwable $throwable): never
    {
        self::report($throwable);

        if (!headers_sent()) {
            (new Emitter())->emit(self::toResponse($throwable));
        }

        exit(1);
    }

    /**
     * Write the failure to the log.
     *
     * Goes through the application logger, so the record is structured and
     * carries the request id every other line of the same request carries —
     * which is the whole reason to have an id at all.
     *
     * It falls back to error_log() when logging itself fails. That is not
     * defensive habit: this runs on the path that handles a fatal error, and a
     * log destination that cannot be opened must not be allowed to replace the
     * failure being reported with a different one.
     *
     * @param Throwable $throwable The failure to report
     * @return void
     */
    private static function report(Throwable $throwable): void
    {
        try {
            if (function_exists('logger')) {
                logger()->exception($throwable);

                return;
            }
        } catch (Throwable) {
            // Fall through to error_log below.
        }

        error_log(sprintf(
            '%s: %s in %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ));
    }

    /**
     * Convert fatal errors reported at script shutdown into HTTP responses.
     *
     * @return void
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        )) {
            return;
        }

        self::handleThrowable(new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    /**
     * Render a failure as a client-safe response.
     *
     * Rendering is separated from sending so that the same code serves both
     * paths: the router catches a failing action and returns this response
     * through the pipeline, while the global handler below emits it for
     * failures no try/catch can reach. It also makes the error page the first
     * part of this class that a test can assert on.
     *
     * @param Throwable $throwable The failure being handled
     * @param Request|null $request The current request, when one exists
     * @return Response The response to send
     */
    public static function toResponse(Throwable $throwable, ?Request $request = null): Response
    {
        $message = self::message($throwable);

        if (self::expectsJson($request)) {
            return Response::json(['message' => $message], HTTP_INTERNAL_SERVER_ERROR);
        }

        /*
         * The 404 and 405 pages were translated when the i18n layer landed and
         * this one was missed: it shipped a Portuguese title and lang="pt-br"
         * to every visitor, whatever language they asked for. The catalog keys
         * had existed the whole time with nothing calling them.
         *
         * function_exists() is checked because this also runs on the shutdown
         * path, where a fatal during bootstrap can mean the autoloader never
         * finished and the helpers were never defined.
         */
        $translated = function_exists('__');
        $title = $translated ? __('http.server_error_title') : 'Internal error';
        $language = $translated ? str_replace('_', '-', locale()) : 'en';

        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(
            '<!doctype html><html lang="' . $language . '" data-theme="auto">'
            . '<head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $escapedTitle . '</title>'
            . '<style>' . self::stylesheet() . '</style>'
            . '<style>body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}'
            . '.sf-error h1{font-size:clamp(3.5rem,15vw,5rem);line-height:1;letter-spacing:-.02em}</style>'
            . '</head>'
            . '<body><main class="sf-error text-center max-w-lg">'
            . '<h1 class="font-bold m-0">500</h1>'
            . '<p class="text-lg text-muted mt-4 mb-0">' . $escaped . '</p>'
            . '</main></body></html>',
            HTTP_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * SFCSS, or nothing at all.
     *
     * The framework's own stylesheet, inlined for the same reason the 404 page
     * inlines it: this is what renders when the application is what is broken,
     * so it cannot depend on a request for an asset.
     *
     * Guarded because this also runs on the shutdown path. A fatal during
     * bootstrap can mean the autoloader never finished, and an error page that
     * throws while rendering an error page leaves a visitor with a blank screen
     * — an unstyled message is a much better failure than none.
     *
     * @return string The stylesheet, or an empty string
     */
    private static function stylesheet(): string
    {
        try {
            return class_exists(Assets::class) ? Assets::css() : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Determine whether the client expects a JSON response.
     *
     * Falls back to the server environment when no request is available, which
     * is the case on the shutdown path: a fatal error can happen before the
     * request object is ever built.
     *
     * @param Request|null $request The current request, when one exists
     * @return bool True when JSON is requested or submitted
     */
    private static function expectsJson(?Request $request): bool
    {
        if ($request !== null) {
            return $request->expectsJson();
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
    }

    /**
     * Get the client-safe error message for the active environment.
     *
     * @param Throwable $throwable The handled failure
     * @return string The message to send to the client
     */
    private static function message(Throwable $throwable): string
    {
        if (Config::get('APP_ENV') === 'development') {
            return $throwable->getMessage();
        }

        return function_exists('__')
            ? __('http.server_error_message')
            : 'Internal Server Error';
    }
}
