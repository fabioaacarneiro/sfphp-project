<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SfphpProject\src\Assets;
use SfphpProject\src\Http\Pipeline;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\I18n\Translator;
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
     * The controller namespace is a parameter rather than a constant so that
     * an application can live under its own namespace, and so tests can point
     * the router at their own controllers. It was hardcoded before, which tied
     * the framework to this one application layout.
     *
     * @param Container $container The dependency injection container
     * @param string $controllerNamespace The namespace route controllers live in
     */
    public function __construct(
        private Container $container,
        private string $controllerNamespace = 'SfphpProject\\app\\controllers\\'
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
            logger()->exception($throwable);

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
            if ($route->getMethod() !== $request->method) {
                continue;
            }

            return $pipeline->run(
                $request->withAttributes($parameters),
                $route->getMiddleware(),
                fn (Request $passed): Response => $this->call($route, $passed, $parameters)
            );
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        if ($allowedMethods === []) {
            return self::errorResponse(
                HTTP_NOT_FOUND,
                __('http.not_found_title'),
                __('http.not_found_message')
            );
        }

        /*
         * Allow has to be attached to both branches. It used to be emitted
         * once with header() before the split, so both inherited it; a
         * returned response carries only what it was given.
         */
        $allow = implode(', ', $allowedMethods);

        if ($request->isMethod(OPTIONS)) {
            return Response::noContent()->withHeader('Allow', $allow);
        }

        return self::errorResponse(
            HTTP_METHOD_NOT_ALLOWED,
            __('http.method_not_allowed_title'),
            __('http.method_not_allowed_message')
        )->withHeader('Allow', $allow);
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
        $controllerClass = $this->controllerNamespace . $route->getController();

        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller $controllerClass not found.");
        }

        $controller = $this->container->get($controllerClass);
        if (!method_exists($controller, $route->getAction())) {
            throw new RuntimeException(
                "Action {$route->getAction()} not found in $controllerClass."
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
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function get(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(GET, $url, $controller, $action);
    }

    /**
     * Define a POST route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function post(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(POST, $url, $controller, $action);
    }

    /**
     * Define a PUT route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function put(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(PUT, $url, $controller, $action);
    }

    /**
     * Define a DELETE route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function delete(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(DELETE, $url, $controller, $action);
    }

    /**
     * Define a PATCH route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function patch(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(PATCH, $url, $controller, $action);
    }

    /**
     * Define a HEAD route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function head(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(HEAD, $url, $controller, $action);
    }

    /**
     * Define an OPTIONS route.
     *
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    public static function options(
        string $url,
        string $controller,
        string $action
    ): Route {
        return self::addRoute(OPTIONS, $url, $controller, $action);
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
     * @param string $method The HTTP method
     * @param string $url The route URL
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @return Route The registered route
     */
    private static function addRoute(
        string $method,
        string $url,
        string $controller,
        string $action
    ): Route {
        $group = end(self::$groups)
            ?: ['prefix' => '', 'namePrefix' => '', 'middleware' => []];

        $route = new Route(
            $method,
            self::joinUri($group['prefix'], $url),
            $controller,
            $action,
            $group['namePrefix']
        );

        $route->middleware($group['middleware']);

        self::$routes[] = $route;

        return $route;
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

    /**
     * Build an HTTP error response.
     *
     * @param int $statusCode The HTTP response status
     * @param string $title The error page title
     * @param string $message The error page message
     * @return Response The error response
     */
    private static function errorResponse(
        int $statusCode,
        string $title,
        string $message
    ): Response {

        /*
         * SFCSS, inlined. Two rules meet here and both matter.
         *
         * The stylesheet is the framework's own, because a screen the framework
         * renders should not be a second visual language living beside the one
         * an application writes its pages with.
         *
         * It is inlined rather than linked because an earlier version pulled
         * Tailwind from a public CDN: that made the error page depend on a
         * third-party request, so it broke offline and behind a restrictive
         * Content-Security-Policy, added a round trip on the slowest path of
         * the request, and leaked visitor IPs to another origin. A <link> to
         * the application's own asset route would be better than that and still
         * wrong — the error page is what renders when the application is what
         * is broken.
         */
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $home = htmlspecialchars(__('http.back_home'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $language = str_replace('_', '-', Translator::locale());
        $stylesheet = Assets::css();

        $html = <<<HTML
        <!doctype html>
        <html lang="{$language}">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>$title</title>
        <style>{$stylesheet}</style>
        <style>
        body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
        .sf-error h1{font-size:clamp(3.5rem,15vw,5rem);line-height:1;letter-spacing:-.02em}
        </style>
        </head>
        <body>
        <main class="sf-error text-center max-w-lg">
        <h1 class="font-bold m-0">$statusCode</h1>
        <p class="text-lg text-muted mt-4 mb-6">$message</p>
        <a class="btn btn-primary" href="/">{$home}</a>
        </main>
        </body>
        </html>
        HTML;

        return Response::html($html, $statusCode);
    }
}
