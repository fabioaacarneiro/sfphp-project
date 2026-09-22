<?php

namespace SfphpProject\src\Http\Middleware;

use JsonException;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Refuses a request whose body is not the JSON the endpoint expects.
 *
 * This was two methods on a base class an API controller had to extend, which
 * meant the check ran only where somebody remembered to call it, and it put a
 * class between the framework and every endpoint in order to do a job the
 * pipeline already exists for. Rejecting a request that cannot be handled is
 * what middleware is: it happens before the action, once, for everything it is
 * registered on.
 *
 *     Router::group('/api', function (): void {
 *         Router::post('/posts', 'PostController', 'store');
 *     }, 'api.', [new RequireJson()]);
 *
 * The action then reads what was decoded, and can trust it:
 *
 *     $data = $request->attribute('json');
 *
 * The distinction worth keeping: **415** is "I do not speak that", sent when
 * the Content-Type is not JSON, and **400** is "that was not valid JSON". A
 * client debugging one is looking somewhere very different from a client
 * debugging the other, so answering the same code for both wastes their day.
 */
final class RequireJson implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param bool $required Whether a body is required rather than merely checked
     */
    public function __construct(private bool $required = false)
    {
    }

    /**
     * Handle the request.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response
     */
    public function handle(Request $request, callable $next): Response
    {
        /*
         * A GET or a DELETE carries no body to check. Refusing them for not
         * being JSON would make this middleware unusable on a group that has
         * both reads and writes, which is most groups.
         */
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $next($request);
        }

        $contentType = (string) $request->header('Content-Type');

        if ($contentType === '') {
            if (!$this->required) {
                return $next($request);
            }

            return self::refuse('Content-Type must be application/json.', HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if (!str_contains(strtolower($contentType), 'application/json')) {
            return self::refuse('Content-Type must be application/json.', HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $decoded = $request->json();
        } catch (JsonException $exception) {
            /*
             * The parser's own message, because "Syntax error" and "Control
             * character error" point at different mistakes and a client cannot
             * see the body the server received.
             */
            return self::refuse('Invalid JSON body: ' . $exception->getMessage(), HTTP_BAD_REQUEST);
        }

        return $next($request->withAttribute('json', $decoded));
    }

    /**
     * Build the refusal.
     *
     * @param string $message What was wrong
     * @param int $status The status code
     * @return Response The response
     */
    private static function refuse(string $message, int $status): Response
    {
        return Response::json(['message' => $message], $status);
    }
}
