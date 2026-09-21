<?php

use SfphpProject\src\Container;
use SfphpProject\src\Database;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Middleware\SecurityHeaders;
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
 * Nothing is trusted until the deployment says what to trust. Behind a
 * TLS-terminating load balancer this is not optional: without it the PHP
 * process sees plain HTTP, the session cookie loses its "secure" flag, and
 * every request appears to come from the balancer's address.
 *
 *   Request::setTrustedProxies(['10.0.0.0/8']);
 */
Request::setTrustedProxies(array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? ''))
))));

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
    /*
     * Content-Security-Policy is left unset: a policy that does not match the
     * application's own assets breaks the page silently, and only the
     * application knows them. See the documentation for a value to start from.
     */
    new SecurityHeaders(),
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
