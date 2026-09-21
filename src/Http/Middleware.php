<?php

namespace SfphpProject\src\Http;

/**
 * A stage in the request pipeline.
 *
 * A middleware receives the request, may inspect or replace it, and calls
 * $next to hand it to the rest of the pipeline. It can also return a response
 * of its own without calling $next, which stops everything further down: that
 * is how authentication refuses a request, or a cache serves a stored copy.
 *
 * Work placed before the $next call runs on the way in; work placed after it
 * runs on the way out, with the response in hand.
 *
 *     public function handle(Request $request, callable $next): Response
 *     {
 *         if ($request->bearerToken() === null) {
 *             return Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED);
 *         }
 *
 *         return $next($request)->withHeader('X-Served-By', 'sfphp');
 *     }
 *
 * The contract is deliberately this and not PSR-15. PSR-15 needs a second
 * interface for the handler, and its value is being able to run middleware
 * written against the real PSR interfaces — which a hand-written copy cannot
 * do, because PHP would reject it at the type boundary. Paying for the
 * indirection without getting the interoperability is a bad trade for a
 * framework whose selling point is being small enough to read.
 */
interface Middleware
{
    /**
     * Handle the request.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response;
}
