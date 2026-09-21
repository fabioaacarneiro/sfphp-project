<?php

namespace SfphpProject\src;

use ErrorException;
use SfphpProject\src\Http\Emitter;
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
        error_log(sprintf(
            '%s: %s in %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ));

        if (!headers_sent()) {
            (new Emitter())->emit(self::toResponse($throwable));
        }

        exit(1);
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

        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(
            "<!doctype html><html lang='pt-br'><head><meta charset='UTF-8'><title>Erro interno</title></head><body><h1>500</h1><p>$escaped</p></body></html>",
            HTTP_INTERNAL_SERVER_ERROR
        );
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
        if (defined('APP_ENV') && APP_ENV === 'development') {
            return $throwable->getMessage();
        }

        return 'Internal Server Error';
    }
}
