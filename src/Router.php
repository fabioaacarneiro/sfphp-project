<?php

namespace SfphpProject\src;

class Router
{
    /**
     * @var array
     */
    private static $routes = [];

    /**
     * Dispatch the request to the correct controller
     *
     * @return void
     */
    public static function dispatch()
    {
        $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        $found = false;
        foreach (self::$routes as $pattern => $methods) {
            $pattern = preg_replace('/\/[^\/:]+:/', '/:', $pattern);

            $pattern = str_replace('/:number', '/([0-9]+)', $pattern);
            $pattern = str_replace('/:alphanum', '/([a-zA-Z0-9]+)', $pattern);
            $pattern = str_replace('/:alpha', '/([a-zA-Z]+)', $pattern);
            $pattern = str_replace('/', '\/', $pattern);
            $pattern = "/^$pattern$/";

            if (preg_match($pattern, $url, $matches) && isset($methods[$method])) {
                $controllerClass = "SfphpProject\\app\\controllers\\" . $methods[$method]['controller'];
                if (class_exists($controllerClass)) {
                    $controller = new $controllerClass();
                    if (method_exists($controller, $methods[$method]['action'])) {
                        if (isset($matches[1])) {
                            $controller->{$methods[$method]['action']}($matches[1]);
                        } else {
                            $controller->{$methods[$method]['action']}();
                        }

                        $found = true;
                        break;
                    }
                }
            }
        }

        if (!$found) {
            http_response_code(404);
            echo "<html lang='pt-br'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><link href='https://unpkg.com/tailwindcss@^1.0/dist/tailwind.min.css' rel='stylesheet'><title>404 - Página Não Encontrada</title></head><body class='bg-gray-100 flex items-center justify-center h-screen'><div class='text-center'><h1 class='text-6xl font-bold text-gray-900'>404</h1><p class='text-xl text-gray-600 mt-4'>Desculpe, a página que você está procurando não foi encontrada.</p><a href='/' class='mt-8 inline-block bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600'>Voltar para a página inicial</a></div></body></html>";
        }
    }

    /**
     * Define a GET route
     *
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    public static function get(
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::addRoute(
            GET, 
            $url, 
            $controller, 
            $action
        );
    }

    /**
     * Define a POST route
     *
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    public static function post(
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::addRoute(
            POST, 
            $url, 
            $controller, 
            $action
        );
    }

    /**
     * Define a PUT route
     *
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    public static function put(
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::addRoute(
            PUT, 
            $url, 
            $controller, 
            $action
        );
    }

    /**
     * Define a DELETE route
     *
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    public static function delete(
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::addRoute(
            DELETE, 
            $url, 
            $controller, 
            $action
        );
    }

    /**
     * Define a PATCH route
     *
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    public static function patch(
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::addRoute(
            PATCH, 
            $url, 
            $controller, 
            $action
        );
    }

    /**
     * Add a route to the routes array
     *
     * @param string $method
     * @param string $url
     * @param string $controller
     * @param string $action
     *
     * @return void
     */
    private static function addRoute(
        string $method, 
        string $url, 
        string $controller, 
        string $action
    ): void {
        self::$routes[$url][$method] = [
            'controller' => $controller, 
            'action' => $action
        ];
    }
}
