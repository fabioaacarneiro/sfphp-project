<?php

namespace SfphpProject\src\Http;

use Closure;
use RuntimeException;
use SfphpProject\src\Container;

/**
 * Runs a request through a list of middleware and into a destination.
 *
 * The list is folded from the end backwards, so each stage closes over the one
 * after it. Calling the outermost stage therefore runs them in the order they
 * were declared, and each one resumes in reverse order as the response travels
 * back out.
 */
final class Pipeline
{
    /**
     * Create a pipeline.
     *
     * @param Container $container The container used to resolve class names
     */
    public function __construct(private Container $container) {}

    /**
     * Send a request through the middleware and into the destination.
     *
     * @param Request $request The incoming request
     * @param array<int, string|Middleware|callable> $middleware The stages, in order
     * @param callable(Request): Response $destination What runs after the last stage
     * @return Response The response
     * @throws RuntimeException If a stage is not usable or does not return a response
     */
    public function run(Request $request, array $middleware, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            fn (callable $next, string|Middleware|callable $stage): Closure
                => fn (Request $passed): Response => $this->call($stage, $passed, $next),
            $destination
        );

        return $pipeline($request);
    }

    /**
     * Invoke a single middleware stage.
     *
     * @param string|Middleware|callable $stage The stage to run
     * @param Request $request The request handed to the stage
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response the stage produced
     * @throws RuntimeException If the stage does not return a response
     */
    private function call(string|Middleware|callable $stage, Request $request, callable $next): Response
    {
        $resolved = $this->resolve($stage);

        $response = $resolved instanceof Middleware
            ? $resolved->handle($request, $next)
            : $resolved($request, $next);

        if (!$response instanceof Response) {
            /*
             * Without this check, a middleware that forgets to return lets a
             * null travel several frames before failing as "call to a member
             * function on null", pointing at the wrong place entirely.
             */
            throw new RuntimeException(sprintf(
                'Middleware %s must return %s; got %s.',
                is_object($stage) ? $stage::class : (is_string($stage) ? $stage : 'closure'),
                Response::class,
                get_debug_type($response)
            ));
        }

        return $response;
    }

    /**
     * Turn a stage declaration into something callable.
     *
     * Class names are resolved through the container, so a middleware can
     * declare its dependencies in its constructor and have them autowired, and
     * so routes can name middleware in src/routes.php before any instance
     * exists.
     *
     * @param string|Middleware|callable $stage The stage declaration
     * @return Middleware|callable The usable stage
     * @throws RuntimeException If the declaration cannot be used as middleware
     */
    private function resolve(string|Middleware|callable $stage): Middleware|callable
    {
        if ($stage instanceof Middleware || (!is_string($stage) && is_callable($stage))) {
            return $stage;
        }

        if (!is_string($stage) || !class_exists($stage)) {
            throw new RuntimeException(sprintf(
                'Middleware %s is not a class, an instance of %s or a callable.',
                is_string($stage) ? "\"$stage\"" : get_debug_type($stage),
                Middleware::class
            ));
        }

        $instance = $this->container->get($stage);

        if (!$instance instanceof Middleware && !is_callable($instance)) {
            throw new RuntimeException(sprintf(
                'Middleware %s must implement %s or be callable.',
                $stage,
                Middleware::class
            ));
        }

        return $instance;
    }
}
