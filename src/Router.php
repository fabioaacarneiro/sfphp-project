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
        $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
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
     * Generate a URL for a named route.
     *
     * @param string $name The route name
     * @param array $parameters Values for the route parameters
     * @param array $query Query string values
     * @return string The generated URL
     * @throws InvalidArgumentException If the route or its parameters are invalid
     */
    /**
     * Get all registered routes.
     *
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return self::$routes;
    }

    /**
     * Generate a URL for a named route.
        string $name,
        array $parameters = [],
        array $query = []
    ): string {
        if (!isset(self::$namedRoutes[$name])) {
            throw new InvalidArgumentException("Route \"$name\" is not registered.");
        }

        $url = self::$namedRoutes[$name]->generateUrl($parameters);
        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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

        echo "<html lang='pt-br'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><link href='https://unpkg.com/tailwindcss@^1.0/dist/tailwind.min.css' rel='stylesheet'><title>$title</title></head><body class='bg-gray-100 flex items-center justify-center h-screen'><div class='text-center'><h1 class='text-6xl font-bold text-gray-900'>$statusCode</h1><p class='text-xl text-gray-600 mt-4'>$message</p><a href='/' class='mt-8 inline-block bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600'>Voltar para a página inicial</a></div></body></html>";
    }
}
