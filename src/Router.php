<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SfphpProject\src\Http\ErrorPage;
use SfphpProject\src\Http\HttpStatus;
use SfphpProject\src\Http\Pipeline;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use Throwable;

/**
 * Registers routes, dispatches requests, and generates named URLs.
 */
class Router
{
    private static array $routes = [];
    private static array $namedRoutes = [];
    private static array $groups = [];

    /**
     * Middleware that runs for every request, including 404 and 405.
     *
     * @var array<int, mixed>
     */
    private array $middleware = [];

    /**
     * Create a router.
     *
     * Routes name their controller by its class — [PostController::class,
     * 'index'] — so the router needs no namespace of its own: an application
     * keeps its controllers wherever it likes, and the editor can follow,
     * rename and check every one of them.
     *
     * @param Container $container The dependency injection container
     */
    public function __construct(
        private Container $container
    ) {}

    /**
     * Add middleware that runs for every request.
     *
     * Global middleware wraps the whole dispatch, so it also runs for requests
     * that match no route. That is deliberate: CORS headers and request
     * logging that skip 404s are a bug, not an optimisation.
     *
     * @param mixed ...$middleware Class names, instances or callables
     * @return self The router
     */
    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $stage) {
                $this->middleware[] = $stage;
            }
        }

        return $this;
    }

    /**
     * Dispatch a request and produce a response.
     *
     * @param Request $request The incoming request
     * @return Response The response to send
     */
    public function dispatch(Request $request): Response
    {
        $pipeline = new Pipeline($this->container);

        /*
         * The failure boundary is here rather than in a middleware because a
         * middleware can be registered in the wrong order and quietly stop
         * catching anything. A try/catch around the whole pipeline cannot.
         */
        try {
            return $pipeline->run(
                $request,
                $this->middleware,
                fn (Request $passed): Response => $this->route($pipeline, $passed)
            );
        } catch (Throwable $throwable) {
            /*
             * The one place a failed request is reported. LogRequests does not
             * also record it: this boundary is guaranteed to run and that
             * middleware is not, so logging in both would mean a duplicate
             * whenever both are present and nothing whenever neither is.
             *
             * The record still carries the request id, because the shared log
             * context LogRequests set on the way in is untouched by an
             * exception on the way out.
             */
            /*
             * A 4xx an exception asked for — a missing record, a refused
             * authorization — is the client's mistake, not a failure to be
             * paged about, so it is recorded as information.
             */
            if ($throwable instanceof HttpStatus && $throwable->status() < 500) {
                logger()->info($throwable->getMessage(), ['status' => $throwable->status(), 'exception' => $throwable::class]);
            } else {
                logger()->exception($throwable);
            }

            $response = ErrorHandler::toResponse($throwable, $request);

            /*
             * An exception skips the rest of the pipeline, so the middleware
             * that would have added this header never gets its turn. A visitor
             * reporting a 500 is the person most in need of the id, so it is
             * attached here instead of being lost.
             *
             * It is read from the log context rather than from the request:
             * withAttribute() clones, so the request this method is holding is
             * the one that came in, not the one the middleware handed onwards.
             * The shared context is the copy an exception does not unwind.
             */
            $id = logger()->context()['request_id'] ?? null;

            return is_string($id) ? $response->withHeader('X-Request-Id', $id) : $response;
        }
    }

    /**
     * Match the request against the registered routes.
     *
     * @param Pipeline $pipeline The pipeline used for route middleware
     * @param Request $request The incoming request
     * @return Response The response to send
     */
    private function route(Pipeline $pipeline, Request $request): Response
    {
        $allowedMethods = [];

        foreach (self::$routes as $route) {
            $parameters = $route->match($request->path);
            if ($parameters === null) {
                continue;
            }

            $allowedMethods[] = $route->getMethod();

            /*
             * HEAD is GET without the body, and the Emitter already leaves the
             * body out. It used to answer 405 on every GET route, which is
             * what link checkers and uptime probes send.
             */
            $answers = $route->getMethod() === $request->method
                || ($request->method === HEAD && $route->getMethod() === GET);

            if (!$answers) {
                continue;
            }

            return $pipeline->run(
                $request->withRouteParameters($parameters, $route->getPath()),
                $route->getMiddleware(),
                fn (Request $passed): Response => $this->call($route, $passed, $parameters)
            );
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        if ($allowedMethods === []) {
            return ErrorPage::response(HTTP_NOT_FOUND, request: $request);
        }

        /*
         * Allow has to be attached to both branches. It used to be emitted
         * once with header() before the split, so both inherited it; a
         * returned response carries only what it was given.
         */
        if (in_array(GET, $allowedMethods, true)) {
            $allowedMethods[] = HEAD;
        }

        $allowedMethods[] = OPTIONS;
        $allow = implode(', ', array_values(array_unique($allowedMethods)));

        if ($request->isMethod(OPTIONS)) {
            return Response::noContent()->withHeader('Allow', $allow);
        }

        return ErrorPage::response(HTTP_METHOD_NOT_ALLOWED, request: $request)->withHeader('Allow', $allow);
    }

    /**
     * Resolve the controller and invoke the action.
     *
     * The request is always the first argument and route parameters follow in
     * the order they appear in the URL. One rule, no reflection, no exception.
     *
     * @param Route $route The matched route
     * @param Request $request The incoming request
     * @param array<string, string> $parameters The route parameters
     * @return Response The response the action produced
     * @throws RuntimeException If the controller or action does not exist
     * @throws LogicException If the action returns something unusable
     */
    private function call(Route $route, Request $request, array $parameters): Response
    {
        $controllerClass = $route->getController();

        if (!class_exists($controllerClass)) {
            /*
             * Almost always a missing `use` line in the routes file: the name
             * resolved against the file's own namespace, or the global one.
             */
            throw new RuntimeException(sprintf(
                'Controller %s not found. If it lives in another namespace, import it at the top of the routes file: use Its\\Namespace\\%s;',
                $controllerClass,
                substr((string) strrchr('\\' . $controllerClass, '\\'), 1)
            ));
        }

        $controller = $this->container->get($controllerClass);
        if (!method_exists($controller, $route->getAction())) {
            throw new RuntimeException(
                "Action {$route->getAction()} not found in $controllerClass."
            );
        }

        if (!(new \ReflectionMethod($controller, $route->getAction()))->isPublic()) {
            throw new RuntimeException(
                "Action {$route->getAction()} in $controllerClass is not public, so the router cannot call it."
            );
        }

        $result = $controller->{$route->getAction()}($request, ...array_values($parameters));

        return Response::from($result, $controllerClass . '::' . $route->getAction() . '()');
    }

    /**
     * Forget every registered route.
     *
     * @internal Exposed for tests and for worker reloads in a persistent runtime.
     * @return void
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$namedRoutes = [];
        self::$groups = [];
    }

    /**
     * Define a GET route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function get(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(GET, $url, $action, $method);
    }

    /**
     * Define a POST route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function post(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(POST, $url, $action, $method);
    }

    /**
     * Define a PUT route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function put(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(PUT, $url, $action, $method);
    }

    /**
     * Define a DELETE route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function delete(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(DELETE, $url, $action, $method);
    }

    /**
     * Define a PATCH route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function patch(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(PATCH, $url, $action, $method);
    }

    /**
     * Define a HEAD route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function head(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(HEAD, $url, $action, $method);
    }

    /**
     * Define an OPTIONS route.
     *
     * @param string $url The route URL
     * @param array{0: class-string, 1: string} $action [Controller::class, 'method']
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    public static function options(
        string $url,
        array|string $action,
        ?string $method = null
    ): Route {
        return self::addRoute(OPTIONS, $url, $action, $method);
    }

    /**
     * Define a group of routes that share a URL and name prefix.
     *
     * @param string $prefix The URL prefix applied to registered routes
     * @param callable $routes The callback that registers the grouped routes
     * @param string $namePrefix The route name prefix
     * @return void
     */
    public static function group(
        string $prefix,
        callable $routes,
        string $namePrefix = '',
        array $middleware = []
    ): void {
        $parentGroup = end(self::$groups)
            ?: ['prefix' => '', 'namePrefix' => '', 'middleware' => []];

        self::$groups[] = [
            'prefix' => self::joinUri($parentGroup['prefix'], $prefix),
            'namePrefix' => $parentGroup['namePrefix'] . $namePrefix,
            'middleware' => array_merge($parentGroup['middleware'], $middleware),
        ];

        try {
            $routes();
        } finally {
            array_pop(self::$groups);
        }
    }

    /**
     * Get all registered routes.
     *
     * @return array<int, Route> The routes in registration order
     */
    public function routes(): array
    {
        return self::$routes;
    }

    /**
     * Register a name for a route.
     *
     * @internal Called by Route::name().
     * @param Route $route The route to name
     * @param string $name The local route name
     * @return void
     * @throws InvalidArgumentException If the name is invalid or already registered
     */
    public static function registerName(Route $route, string $name): void
    {
        $name = $route->getNamePrefix() . $name;
        if (!preg_match(
            '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/',
            $name
        )) {
            throw new InvalidArgumentException("Invalid route name \"$name\".");
        }

        if (isset(self::$namedRoutes[$name])) {
            throw new InvalidArgumentException("Route name \"$name\" is already registered.");
        }

        $route->setName($name);
        self::$namedRoutes[$name] = $route;
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name The route name
     * @param array<string, mixed> $parameters The route parameters
     * @param array<string, mixed> $query The query string parameters
     * @return string The generated URL
     * @throws RuntimeException If the named route does not exist
     */
    public static function url(string $name, array $parameters = [], array $query = []): string
    {
        if (!isset(self::$namedRoutes[$name])) {
            throw new RuntimeException("Named route \"$name\" not found.");
        }

        $url = self::$namedRoutes[$name]->generateUrl($parameters);
        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Register a route using the active group context.
     *
     * The second parameter of each verb takes the old controller-name string
     * only to refuse it with the new spelling (see action()); a route always
     * names its action as [Controller::class, 'method'].
     *
     * @param string $verb The HTTP method
     * @param string $url The route URL
     * @param array<int, mixed>|string $action [Controller::class, 'method']
     * @param string|null $oldMethod The method, from the old three-argument form
     * @return Route The registered route
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    private static function addRoute(
        string $verb,
        string $url,
        array|string $action,
        ?string $oldMethod = null
    ): Route {
        [$controller, $method] = self::action($verb, $url, $action, $oldMethod);

        $group = end(self::$groups)
            ?: ['prefix' => '', 'namePrefix' => '', 'middleware' => []];

        $route = new Route(
            $verb,
            self::joinUri($group['prefix'], $url),
            $controller,
            $method,
            $group['namePrefix']
        );

        $route->middleware($group['middleware']);

        self::$routes[] = $route;

        return $route;
    }

    /**
     * Read a route's action: [Controller::class, 'method'].
     *
     * The pair is what an editor understands: it follows the class, renames
     * it with the rest of the code, and flags one that does not exist — where
     * the old 'PostController', 'index' was two strings nothing could check
     * before a request arrived. The old form is refused with the new spelling
     * in the message, so an upgrade fails at boot with the fix in hand rather
     * than at the first request with a class-not-found.
     *
     * @param array<int, mixed>|string $action The action as given
     * @return array{0: string, 1: string} The controller class and the method
     * @throws InvalidArgumentException When the action is not a [class, method] pair
     */
    private static function action(string $verb, string $url, array|string $action, ?string $oldMethod): array
    {
        if (is_string($action)) {
            $short = substr((string) strrchr('\\' . $action, '\\'), 1);

            throw new InvalidArgumentException(sprintf(
                "Route %s %s names its controller as a string. Name the class and the method as a pair: [%s::class, '%s'].",
                $verb,
                $url,
                $short,
                $oldMethod ?? 'method'
            ));
        }

        $valid = $oldMethod === null
            && array_is_list($action)
            && count($action) === 2
            && is_string($action[0]) && $action[0] !== ''
            && is_string($action[1]) && $action[1] !== '';

        if (!$valid) {
            throw new InvalidArgumentException(sprintf(
                "Route %s %s needs its action as [Controller::class, 'method'].",
                $verb,
                $url
            ));
        }

        return [ltrim($action[0], '\\'), $action[1]];
    }

    /**
     * Join a group prefix and route URI into a normalized path.
     *
     * @param string $prefix The group URL prefix
     * @param string $url The route URL
     * @return string The normalized path
     */
    private static function joinUri(string $prefix, string $url): string
    {
        $prefix = trim($prefix, '/');
        $url = trim($url, '/');

        $path = implode('/', array_filter([$prefix, $url], fn (string $part): bool => $part !== ''));

        return $path === '' ? '/' : '/' . $path;
    }

}
