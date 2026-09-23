<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Scheduler;

/**
 * Middleware that automatically enables async/await for each request
 *
 * This middleware:
 * 1. Creates a Scheduler at the start of each request
 * 2. Pushes it to the async Context
 * 3. Allows controllers and components to use await() without manual setup
 * 4. Cleans up the context after the response is ready
 *
 * Add this to your router middleware stack to enable async/await:
 *
 *     $router = (new Router($container))
 *         ->middleware(new EnableAsync())
 *         ->middleware(...)
 *         ->middleware(...)
 */
class EnableAsync implements Middleware
{
    /**
     * Handle the request with async support enabled
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        // Create a Scheduler for this request
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Call the rest of the pipeline
            // Controllers can now use await() directly
            $response = $next($request);

            return $response;
        } finally {
            // Clean up the context when done
            Context::popScheduler();
        }
    }
}
