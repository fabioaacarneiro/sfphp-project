<?php

namespace SfphpProject\src;

use ErrorException;
use Throwable;

/**
 * Converts uncaught PHP failures into safe HTTP responses.
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

        self::sendResponse($throwable);

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
     * Send a safe error response for the current request.
     *
     * @param Throwable $throwable The failure being handled
     * @return void
     */
    private static function sendResponse(Throwable $throwable): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(HTTP_INTERNAL_SERVER_ERROR);

        if (self::expectsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['message' => self::message($throwable)]);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        $message = htmlspecialchars(self::message($throwable), ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><html lang='pt-br'><head><meta charset='UTF-8'><title>Erro interno</title></head><body><h1>500</h1><p>$message</p></body></html>";
    }

    /**
     * Determine whether the current request expects a JSON response.
     *
     * @return bool True when JSON is requested or submitted
     */
    private static function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains(strtolower($accept), 'application/json')
            || str_contains(strtolower($contentType), 'application/json');
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
