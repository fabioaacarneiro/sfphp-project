<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use RuntimeException;

/**
 * Registers routes, dispatches requests, and generates named URLs.
 */
class Router
{
    private static array $routes = [];
    private static array $namedRoutes = [];
    private static array $groups = [];

    /**
     * Create a router.
     *
     * @param Container $container The dependency injection container
     */
    public function __construct(private Container $container) {}

    /**
     * Dispatch the current request to the matching controller action.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $url = self::decodePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
        $method = $_SERVER['REQUEST_METHOD'];
        $allowedMethods = [];

        foreach (self::$routes as $route) {
            $parameters = $route->match($url);
            if ($parameters === null) {
                continue;
            }

            $allowedMethods[] = $route->getMethod();
            if ($route->getMethod() !== $method) {
                continue;
            }

            $controllerClass = "SfphpProject\\app\\controllers\\"
                . $route->getController();

            if (!class_exists($controllerClass)) {
                throw new RuntimeException("Controller $controllerClass not found.");
            }

            $controller = $this->container->get($controllerClass);
            if (!method_exists($controller, $route->getAction())) {
                throw new RuntimeException(
                    "Action {$route->getAction()} not found in $controllerClass."
                );
            }

            $controller->{$route->getAction()}(...array_values($parameters));

            return;
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', $allowedMethods));

            if ($method === OPTIONS) {
                http_response_code(HTTP_NO_CONTENT);

                return;
            }

            self::renderErrorPage(
                HTTP_METHOD_NOT_ALLOWED,
                '405 - Método Não Permitido',
                'O método HTTP usado não é permitido para esta página.'
            );

            return;
        }

        self::renderErrorPage(
            HTTP_NOT_FOUND,
            '404 - Página Não Encontrada',
            'Desculpe, a página que você está procurando não foi encontrada.'
        );
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
        string $namePrefix = ''
    ): void {
        $parentGroup = end(self::$groups) ?: ['prefix' => '', 'namePrefix' => ''];
        self::$groups[] = [
            'prefix' => self::joinUri($parentGroup['prefix'], $prefix),
            'namePrefix' => $parentGroup['namePrefix'] . $namePrefix,
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
        $group = end(self::$groups) ?: ['prefix' => '', 'namePrefix' => ''];
        $route = new Route(
            $method,
            self::joinUri($group['prefix'], $url),
            $controller,
            $action,
            $group['namePrefix']
        );

        self::$routes[] = $route;

        return $route;
    }

    /**
     * Decode a percent-encoded request path without inventing new segments.
     *
     * Route parameters are matched against the decoded path so that non-ASCII
     * URLs work: a browser sends /produtos/caf%C3%A9, and a route declaring
     * "name:alpha" can only match it once it reads /produtos/café.
     *
     * Decoding is done segment by segment, and any separator produced by the
     * decoding is encoded straight back. Otherwise "/a%2Fb" would decode to
     * "/a/b" and reach a route registered as "/a/b", giving the client a way
     * to address a route through a path it never actually requested. A
     * segment that arrives with an encoded separator keeps it encoded, so it
     * matches a literal route segment or nothing at all.
     *
     * @param string $path The raw request path
     * @return string The decoded path
     */
    private static function decodePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => str_replace(
                ['/', '\\'],
                ['%2F', '%5C'],
                rawurldecode($segment)
            ),
            explode('/', $path)
        );

        return implode('/', $segments);
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
     * Render an HTTP error page.
     *
     * @param int $statusCode The HTTP response status
     * @param string $title The error page title
     * @param string $message The error page message
     * @return void
     */
    private static function renderErrorPage(
        int $statusCode,
        string $title,
        string $message
    ): void {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');

        /*
         * Styled with an inline stylesheet on purpose. An earlier version
         * pulled Tailwind from a public CDN, which made the framework's own
         * error page depend on a third-party network request: it broke
         * offline and behind restrictive Content-Security-Policy headers,
         * added a round trip on the slowest path of the request, and leaked
         * visitor IPs to another origin. A framework that ships zero
         * dependencies cannot make its error path depend on one.
         */
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>$title</title>
        <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
        padding:1.5rem;background:#f8fafc;color:#0f172a;
        font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
        main{max-width:32rem;text-align:center}
        h1{margin:0;font-size:clamp(3.5rem,15vw,5rem);font-weight:700;line-height:1;letter-spacing:-.02em}
        p{margin:1rem 0 2rem;font-size:1.125rem;color:#475569}
        a{display:inline-block;padding:.625rem 1.25rem;border-radius:.5rem;
        background:#2563eb;color:#fff;text-decoration:none;font-weight:600}
        a:hover{background:#1d4ed8}
        a:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}
        @media(prefers-color-scheme:dark){
        body{background:#0f172a;color:#f1f5f9}
        p{color:#94a3b8}
        }
        </style>
        </head>
        <body>
        <main>
        <h1>$statusCode</h1>
        <p>$message</p>
        <a href="/">Voltar para a p&aacute;gina inicial</a>
        </main>
        </body>
        </html>
        HTML;
    }
}
