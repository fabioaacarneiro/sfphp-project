<?php

use SfphpProject\src\Container;
use SfphpProject\src\Database;
use SfphpProject\src\Router;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../src/routes.php";

$container = new Container();
$container->set("pdo", Database::connect());

$router = new Router($container);
$router->dispatch();
