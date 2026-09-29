<?php

/**
 * A minimal application behind php -S, with the session and CSRF middleware
 * the front controller registers, for the tests that need a real cookie.
 *
 *   GET  /plain    a page that never touches the session
 *   GET  /read     reads a value, writes nothing
 *   GET  /write    writes a value
 *   GET  /form     a page with a CSRF token, as a form has
 *   POST /submit   checked by VerifyCsrfToken
 */

use SfphpProject\src\Container;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Middleware\StartSession;
use SfphpProject\src\Http\Middleware\VerifyCsrfToken;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Router;
use SfphpProject\src\Session\Session;

require __DIR__ . '/../../vendor/autoload.php';

SfphpProject\src\Bootstrap::load(dirname(__DIR__, 2), ['env' => null]);

// What fixtureServer() looks for before it hands the server back.
header('X-Served-By: sfphp-test');

final class SessionFixtureController
{
    public function plain(): Response
    {
        return Response::html('plain');
    }

    public function read(): Response
    {
        return Response::html('read:' . (string) Session::get('value', 'none'));
    }

    public function write(): Response
    {
        Session::put('value', 'written');

        return Response::html('wrote');
    }

    public function form(): Response
    {
        return Response::html('token:' . csrf_token());
    }

    public function submit(): Response
    {
        return Response::html('accepted');
    }
}

Router::reset();
Router::get('/plain', [SessionFixtureController::class, 'plain']);
Router::get('/read', [SessionFixtureController::class, 'read']);
Router::get('/write', [SessionFixtureController::class, 'write']);
Router::get('/form', [SessionFixtureController::class, 'form']);
Router::post('/submit', [SessionFixtureController::class, 'submit']);

$request = Request::fromGlobals();
$response = (new Router(new Container()))
    ->middleware(StartSession::class, VerifyCsrfToken::class)
    ->dispatch($request);

(new Emitter())->emit($response, $request->method);
