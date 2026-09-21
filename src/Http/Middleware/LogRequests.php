<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Log\Level;
use SfphpProject\src\Log\LogManager;

/**
 * Gives every request an id, and records that it happened.
 *
 * The id is the point. A production failure is never one log line: it is the
 * request that came in, the query that was slow, the exception that came out,
 * written at different moments and interleaved with every other request the
 * server was handling. Without something joining them there is no way to tell
 * which lines belong together, and reading the log is guesswork.
 *
 *     $router->middleware(new LogRequests());
 *
 * Once this runs, the id is on the request as an attribute, in the shared log
 * context, and on the response as X-Request-Id — so it reaches the visitor too,
 * and a support ticket can carry the one string that finds everything.
 *
 * > **Register it first, or as close to first as the pipeline allows.** Only
 * > what runs after it is covered, and a request that matches no route never
 * > reaches a controller — a 404 is a thing worth having logs for.
 *
 * > **Under a persistent runtime this middleware is mandatory.** The shared log
 * > context lives in an object that outlives a request in a Swoole or
 * > FrankenPHP worker, so one visitor's id would follow the next visitor's
 * > logs. This calls forgetContext() at the start of every request and is the
 * > explicit owner of that reset — exactly as SetLocale is for the active
 * > language and Authenticate for the user.
 */
final class LogRequests implements Middleware
{
    /**
     * What an inbound request id may contain.
     *
     * An id from the client is convenient — it is how a trace follows a request
     * from one service into the next — and it is also client-controlled input
     * going straight into the logs. Unbounded length turns a log into a disk
     * bill, and control characters turn a log viewer into something that no
     * longer shows what it says it shows. Anything that does not match this is
     * replaced with a generated id rather than refused, because the request
     * itself is not the problem.
     */
    private const VALID_ID = '/^[A-Za-z0-9._\-]{1,128}$/';

    private LogManager $log;

    /**
     * Create the middleware.
     *
     * @param LogManager|null $log The logger, or null for the shared one
     * @param string $header The header carrying an inbound id
     * @param bool $logRequests Whether to write a line per request
     */
    public function __construct(
        ?LogManager $log = null,
        private string $header = 'X-Request-Id',
        private bool $logRequests = true
    ) {
        $this->log = $log ?? logger();
    }

    /**
     * Assign the id, run the request, and record the outcome.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        $this->log->forgetContext();

        $id = $this->requestId($request);
        $request = $request->withAttribute('request_id', $id);

        $this->log->withContext([
            'request_id' => $id,
            'method' => $request->method,
            'path' => $request->path,
            'ip' => $request->ip(),
        ]);

        $startedAt = microtime(true);

        /*
         * A failing request is not caught here. The router's own boundary
         * reports it, because that boundary is guaranteed to run while a
         * middleware can be registered in the wrong order — and by then the
         * shared context above is already set, so the record carries the id
         * anyway. Catching here as well would write the same failure twice.
         */
        $response = $next($request);

        if ($this->logRequests) {
            $this->log->log(
                $response->status() >= HTTP_INTERNAL_SERVER_ERROR ? Level::Error : Level::Info,
                'request handled',
                [
                    'status' => $response->status(),
                    'duration_ms' => $this->elapsed($startedAt),
                ]
            );
        }

        return $response->withHeader($this->header, $id);
    }

    /**
     * The id for this request: the client's when it is usable, a new one otherwise.
     *
     * @param Request $request The incoming request
     * @return string The request id
     */
    private function requestId(Request $request): string
    {
        $inbound = (string) $request->header($this->header);

        if ($inbound !== '' && preg_match(self::VALID_ID, $inbound) === 1) {
            return $inbound;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * How long the request took, in milliseconds.
     *
     * @param float $startedAt The value microtime(true) returned at the start
     * @return float The elapsed milliseconds, to three decimal places
     */
    private function elapsed(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 3);
    }
}
