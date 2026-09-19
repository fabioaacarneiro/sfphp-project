<?php

use SfphpProject\src\Container;
use SfphpProject\src\Csrf;
use SfphpProject\src\Database;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Router;

require_once __DIR__ . "/../vendor/autoload.php";
Csrf::startSession();
ErrorHandler::register();
require_once __DIR__ . "/../src/routes.php";

$container = new Container();

/*
 * Bound under PDO::class because the autowiring resolver looks services up by
 * the fully qualified class name of the constructor parameter it is filling.
 *
 * Bound as a closure so the connection is only opened when a controller or
 * model actually asks for it, instead of on every request.
 */
$container->set(PDO::class, fn (): PDO => Database::connect());

$router = new Router($container);
$router->dispatch();
