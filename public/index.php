<?php

use SfphpProject\src\Container;
use SfphpProject\src\Database;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Middleware\SetLocale;
use SfphpProject\src\Http\Middleware\StartSession;
use SfphpProject\src\Http\Middleware\VerifyCsrfToken;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Router;

require_once __DIR__ . "/../vendor/autoload.php";

/*
 * Registered even though the router catches failures inside the pipeline.
 * These handlers cover what a try/catch cannot see: a warning raised during
 * bootstrap, and a fatal reported at shutdown such as running out of memory or
 * exceeding the execution time.
 */
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

/*
 * The request is built once, dispatched to a response, and emitted. Nothing
 * between those three lines touches a superglobal or writes output, which is
 * what makes the whole path testable and what a persistent runtime would
 * replace by swapping the first and last line.
 */
$router = (new Router($container))->middleware(
    /*
     * Resolved first, so every message downstream — including a 404, which
     * never reaches a controller — is rendered in the visitor's language.
     */
    new SetLocale(APP_LOCALES, APP_LOCALE),
    StartSession::class,
    /*
     * Applies to every state-changing request. Add path prefixes here to
     * exempt an API that authenticates some other way:
     *   new VerifyCsrfToken(['/api'])
     */
    VerifyCsrfToken::class
);

$request = Request::fromGlobals();
$response = $router->dispatch($request);

(new Emitter())->emit($response, $request->method);
