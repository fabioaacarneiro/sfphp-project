<?php

/**
 * The front controller: every request enters here.
 *
 * Written by `sfphp init`. It is yours now — change it freely.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SfphpProject\src\Bootstrap;
use SfphpProject\src\Container;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Middleware\StartSession;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Router;

/*
 * Loads .env if you have one, defines the settings the framework reads unless
 * you defined them first, and says where your views and message catalogs live.
 */
Bootstrap::load(dirname(__DIR__), [
    'views' => 'resources/views',
]);

/*
 * A safety net for failures that happen before, or outside, the pipeline — a
 * fatal during bootstrap has no request to hand to a middleware.
 */
ErrorHandler::register();

require dirname(__DIR__) . '/routes.php';

/*
 * Nothing is trusted until it is declared. Behind a load balancer that
 * terminates TLS, list it here or every request looks like it came from the
 * balancer and the session cookie loses its "secure" flag.
 */
// Request::setTrustedProxies(['10.0.0.0/8']);

$router = new Router(new Container(), '{NAMESPACE}Controllers\\');
$router->middleware(new StartSession());

(new Emitter())->emit($router->dispatch(Request::fromGlobals()));
