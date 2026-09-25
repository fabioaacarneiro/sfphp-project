<?php

namespace SfphpProject\src;

use ErrorException;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\ErrorPage;
use SfphpProject\src\Http\HttpException;
use SfphpProject\src\Http\HttpStatus;
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

        /*
         * A deprecation is a warning about the future, not a failure now. It
         * used to become an exception, so upgrading PHP or a library turned
         * pages into 500s over code that still works. It is logged instead.
         */
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            if (function_exists('logger')) {
                logger()->warning('deprecated: ' . $message, ['file' => $file, 'line' => $line]);
            }

            return true;
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
        /*
         * An exception that names its status is answered with it. Everything
         * used to be a 500 — a missing record, a refused authorization and a
         * malformed body all read as the server having broken.
         */
        $status = $throwable instanceof HttpStatus ? $throwable->status() : HTTP_INTERNAL_SERVER_ERROR;

        return ErrorPage::response($status, self::message($throwable, $status), $request);
    }


    /**
     * The message the visitor sees.
     *
     * A 4xx describes what the client did, so its message is shown when the
     * exception carries one. A 5xx is the server's failure: outside
     * development its message stays in the log, because it names files,
     * queries and hosts.
     *
     * @param Throwable $throwable The handled failure
     * @param int $status The status being answered
     * @return string|null The message, or null for the status's standard one
     */
    private static function message(Throwable $throwable, int $status): ?string
    {
        if (Config::get('APP_ENV') === 'development') {
            return $throwable->getMessage() !== '' ? $throwable->getMessage() : null;
        }

        if ($status < 500 && $throwable instanceof HttpException && $throwable->getMessage() !== '') {
            return $throwable->getMessage();
        }

        return null;
    }
}
