<?php

/*
 * HTTP: requests, responses, routing, middleware, CSRF, rate limits, errors.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Csrf;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Config;
use SfphpProject\src\Container;
use SfphpProject\src\JWT;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\FileDriver;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Http\Middleware\RateLimit;
use SfphpProject\src\Http\Middleware\SecurityHeaders;
use SfphpProject\src\Http\Middleware\VerifyCsrfToken;
use SfphpProject\src\Http\Pipeline;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Route;
use SfphpProject\src\Router;
use SfphpProject\src\Session\CacheHandler;

$tests->run('csrf tokens persist and validate requests', function () use ($tests): void {
    $savedServer = $_SERVER;
    $savedPost = $_POST;
    $savedSession = $_SESSION ?? [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_id('csrf-test');
    $_SESSION = [];
    $_POST = [];
    $_SERVER = [];

    $token = csrf_token();
    $tests->assertSame($token, csrf_token());
    $tests->assertTrue(str_contains(csrf_field(), $token));
    $tests->assertTrue(str_contains(csrf_meta(), $token));

    $_POST['_token'] = $token;
    $tests->assertTrue(Csrf::validateRequest());

    $_POST['_token'] = 'invalid';
    $tests->assertSame(false, csrf_verify());

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $_SERVER = $savedServer;
    $_POST = $savedPost;
    $_SESSION = $savedSession;
});

$tests->run('named routes generate validated URLs', function () use ($tests): void {
    Router::get('/tests/id:number', [TestController::class, 'show'])->name('tests.show');
    $tests->assertSame('/tests/7?page=2', Router::url(
        'tests.show',
        ['id' => 7],
        ['page' => 2]
    ));
    $tests->assertThrows(
        fn () => Router::url('tests.show', ['id' => 'invalid']),
        InvalidArgumentException::class
    );
});

$tests->run('container resolves defaults and rejects cycles', function () use ($tests): void {
    $container = new Container();
    $resolved = $container->get(ContainerDefaultTest::class);
    $tests->assertTrue($resolved->dependency instanceof ContainerDependencyTest);
    $tests->assertSame('default', $resolved->label);
    $tests->assertThrows(
        fn () => $container->get(ContainerCycleATest::class),
        RuntimeException::class
    );
});

$tests->run('routes match non-ascii paths and refuse synthesized separators', function () use ($tests): void {
    $route = new Route('GET', '/produtos/nome:alpha', 'MainController', 'show');

    $tests->assertSame(['nome' => 'café'], $route->match('/produtos/café'));
    $tests->assertSame(['nome' => '北京'], $route->match('/produtos/北京'));
    $tests->assertSame(null, $route->match('/produtos/abc123'));
    $tests->assertSame('/produtos/caf%C3%A9', $route->generateUrl(['nome' => 'café']));

    // Path decoding belongs to the request now, not to the router.
    $tests->assertSame('/produtos/café', Request::create('GET', '/produtos/caf%C3%A9')->path);

    // An encoded separator must not become a real one, or "/a%2Fb" would
    // reach a route registered as "/a/b".
    $tests->assertSame('/a%2Fb', Request::create('GET', '/a%2Fb')->path);
    $tests->assertSame('/a%5Cb', Request::create('GET', '/a%5Cb')->path);
});

$tests->run('the framework error page makes no external requests', function () use ($tests): void {
    // No reflection and no output buffer: the error page is a Response now.
    $response = (new Router(new Container()))->dispatch(Request::create('GET', '/rota-que-nao-existe'));

    $tests->assertSame(HTTP_NOT_FOUND, $response->status());

    /*
     * The inlined stylesheet draws its icons with SVGs in data: URIs, and an
     * SVG must name its XML namespace, which is written as a URL. It is an
     * identifier the browser never fetches — the image is already in the
     * URI — so it is the one address allowed; any other would be a request.
     */
    $tests->assertSame(0, preg_match_all('#https?://(?!www\.w3\.org/2000/svg)#', $response->body()));
    $tests->assertTrue(str_contains($response->body(), '<style>'));
});

$tests->run('request is built from injected arrays, never from globals', function () use ($tests): void {
    /*
     * The constructor taking arrays rather than reading superglobals is what
     * makes a persistent runtime possible later, and what makes the router
     * testable at all.
     */
    $request = Request::create('POST', '/produtos/caf%C3%A9?page=2&sort=name', [
        'body' => ['nome' => 'Ana'],
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer abc123'],
        'rawBody' => '{"extra":"日本語"}',
    ]);

    $tests->assertSame('POST', $request->method);
    $tests->assertSame('/produtos/café', $request->path);
    $tests->assertSame('2', $request->query('page'));
    $tests->assertSame('Ana', $request->body('nome'));
    $tests->assertSame('abc123', $request->bearerToken());
    $tests->assertTrue($request->expectsJson());
    $tests->assertTrue($request->isMethod('post'));

    // Header lookup is case insensitive in both directions.
    $tests->assertSame('application/json', $request->header('CONTENT-TYPE'));

    // input() falls back from body to the JSON payload to the query string.
    $tests->assertSame('日本語', $request->input('extra'));
    $tests->assertSame('name', $request->input('sort'));
    $tests->assertSame('fallback', $request->input('missing', 'fallback'));

    $tests->assertSame(['extra' => '日本語'], $request->json());

    // An encoded separator must not become a real one.
    $tests->assertSame('/a%2Fb', Request::create('GET', '/a%2Fb')->path);
});

$tests->run('request attributes copy on write', function () use ($tests): void {
    $request = Request::create('GET', '/posts/7');

    $withId = $request->withAttribute('id', '7');
    $withMore = $withId->withAttributes(['user' => 'ana']);

    $tests->assertSame(null, $request->attribute('id'));
    $tests->assertSame('7', $withId->attribute('id'));
    $tests->assertSame('7', $request->withRouteParameters(['id' => '7'])->route('id'));
    $tests->assertSame(null, $withId->attribute('user'));
    $tests->assertSame(['id' => '7', 'user' => 'ana'], $withMore->attributes());

    // The HTTP fields survive the clone untouched.
    $tests->assertSame($request->method, $withMore->method);
    $tests->assertSame($request->path, $withMore->path);
});

$tests->run('response is a value object that never emits', function () use ($tests): void {
    $json = Response::json(['cidade' => 'São Paulo'], HTTP_CREATED);

    $tests->assertSame(HTTP_CREATED, $json->status());
    $tests->assertSame('application/json; charset=utf-8', $json->header('Content-Type'));

    // UNESCAPED_UNICODE: "São Paulo" must not ship as "São Paulo".
    $tests->assertSame('{"cidade":"São Paulo"}', $json->body());

    $tests->assertSame(HTTP_NO_CONTENT, Response::noContent()->status());
    $tests->assertSame('/login', Response::redirect('/login')->header('Location'));
    $tests->assertSame(HTTP_FOUND, Response::redirect('/login')->status());

    /*
     * Header names are case insensitive, so withHeader() must replace an
     * existing one whatever its casing, or the response would carry
     * Content-Type twice. The stored key is the one the caller wrote.
     */
    $replaced = Response::html('<p>oi</p>')->withHeader('content-type', 'text/plain');
    $tests->assertSame(1, count($replaced->headers()));
    $tests->assertSame('text/plain', $replaced->header('Content-Type'));

    // Every with* method copies rather than mutating.
    $original = Response::html('a');
    $tests->assertSame('b', $original->withBody('b')->body());
    $tests->assertSame('a', $original->body());
    $tests->assertSame(HTTP_NOT_FOUND, $original->withStatus(HTTP_NOT_FOUND)->status());
    $tests->assertSame(HTTP_OK, $original->status());
});

$tests->run('response coerces action return values and refuses null', function () use ($tests): void {
    $response = Response::html('x');
    $tests->assertTrue(Response::from($response) === $response);

    $tests->assertSame('text/html; charset=utf-8', Response::from('<p>oi</p>')->header('Content-Type'));
    $tests->assertSame('{"a":1}', Response::from(['a' => 1])->body());

    /*
     * Returning nothing has to be an error, not an empty 200: it is how an
     * action that forgot its return statement announces itself, and naming
     * the action turns a blank page into a one-line fix.
     */
    $tests->assertThrows(
        fn () => Response::from(null, 'MainController::index()'),
        LogicException::class
    );

    try {
        Response::from(null, 'MainController::index()');
    } catch (LogicException $exception) {
        $tests->assertTrue(str_contains($exception->getMessage(), 'MainController::index()'));
    }
});

$tests->run('the pipeline runs middleware in order and unwinds in reverse', function () use ($tests): void {
    $trace = [];

    $stage = static function (string $label) use (&$trace): callable {
        return static function (Request $request, callable $next) use ($label, &$trace): Response {
            $trace[] = "entra:$label";
            $response = $next($request);
            $trace[] = "sai:$label";

            return $response;
        };
    };

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$stage('a'), $stage('b')],
        function (Request $request) use (&$trace): Response {
            $trace[] = 'action';

            return Response::text('ok');
        }
    );

    $tests->assertSame('ok', $response->body());
    $tests->assertSame(
        ['entra:a', 'entra:b', 'action', 'sai:b', 'sai:a'],
        $trace
    );
});

$tests->run('middleware can replace the request and short-circuit the pipeline', function () use ($tests): void {
    $reached = false;

    // A stage may hand a modified request down the chain.
    $attach = static fn (Request $request, callable $next): Response
        => $next($request->withAttribute('user', 'ana'));

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$attach],
        static fn (Request $request): Response => Response::text((string) $request->attribute('user'))
    );

    $tests->assertSame('ana', $response->body());

    // A stage that returns without calling $next stops everything after it.
    $deny = static fn (Request $request, callable $next): Response
        => Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED);

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$deny],
        function (Request $request) use (&$reached): Response {
            $reached = true;

            return Response::text('nunca');
        }
    );

    $tests->assertSame(HTTP_UNAUTHORIZED, $response->status());
    $tests->assertSame(false, $reached);
});

$tests->run('the pipeline resolves middleware class names through the container', function () use ($tests): void {
    // Naming a class lets routes declare middleware before any instance
    // exists, and lets the middleware constructor-inject its dependencies.
    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [StampMiddlewareTest::class],
        static fn (Request $request): Response => Response::text('corpo')
    );

    $tests->assertSame('sfphp', $response->header('X-Stamp'));
    $tests->assertSame('corpo', $response->body());

    $tests->assertThrows(
        fn () => (new Pipeline(new Container()))->run(
            Request::create('GET', '/'),
            ['NaoExisteMiddleware'],
            static fn (Request $request): Response => Response::text('x')
        ),
        RuntimeException::class
    );
});

$tests->run('a middleware that forgets to return fails where the mistake is', function () use ($tests): void {
    /*
     * Without the explicit check the null travels several frames before
     * failing as "call to a member function on null", pointing at the
     * pipeline rather than at the middleware that caused it.
     */
    $forgets = static function (Request $request, callable $next) {
        $next($request);
    };

    $tests->assertThrows(
        fn () => (new Pipeline(new Container()))->run(
            Request::create('GET', '/'),
            [$forgets],
            static fn (Request $request): Response => Response::text('x')
        ),
        RuntimeException::class
    );
});

$tests->run('error responses are rendered, negotiated and redacted', function () use ($tests): void {
    /*
     * The first assertions ever written for ErrorHandler. They were impossible
     * before: the class echoed and called exit, so there was nothing to
     * inspect.
     */
    $throwable = new RuntimeException('connection to 10.0.0.5 failed for user root');

    $html = ErrorHandler::toResponse($throwable);
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $html->status());
    $tests->assertSame('text/html; charset=utf-8', $html->header('Content-Type'));

    $json = ErrorHandler::toResponse(
        $throwable,
        Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']])
    );
    $tests->assertSame('application/json; charset=utf-8', $json->header('Content-Type'));

    /*
     * Outside development the driver message must not reach the client: it
     * carries the host, the database and the user. What goes instead is the
     * standard message for the status, in the visitor's language.
     *
     * This branch only runs where APP_ENV is not development — in CI, which
     * has no .env — so a local run with a development .env never saw it. It
     * still expected the "Internal Server Error" that came before the
     * translated error page, and CI failed on every commit while every local
     * run passed.
     */
    if (APP_ENV !== 'development') {
        $tests->assertSame(
            ['message' => \SfphpProject\src\Http\ErrorPage::text(HTTP_INTERNAL_SERVER_ERROR, 'message')],
            json_decode($json->body(), true)
        );
        $tests->assertSame(false, str_contains($json->body(), '10.0.0.5'));
        $tests->assertSame(false, str_contains($html->body(), '10.0.0.5'));
    }
});

/*
 * End-to-end dispatch. These tests come last because Router::reset() throws
 * away the routes the earlier named-route tests registered.
 *
 * The controllers live in the global namespace and the router is pointed at it
 * with an empty prefix, which is the same seam that lets an application choose
 * its own namespace.
 */
Router::reset();

$tests->run('dispatch turns a request into a response through a controller', function () use ($tests): void {
    Router::reset();
    Router::get('/', [DispatchTestController::class, 'home']);
    Router::get('/posts/id:number', [DispatchTestController::class, 'show']);
    Router::post('/posts', [DispatchTestController::class, 'store']);

    $router = new Router(new Container());

    $home = $router->dispatch(Request::create('GET', '/'));
    $tests->assertSame(HTTP_OK, $home->status());
    $tests->assertSame('home', $home->body());

    // Route parameters arrive positionally, after the request.
    $show = $router->dispatch(Request::create('GET', '/posts/42'));
    $tests->assertSame('post:42', $show->body());

    // And they are also readable from the request.
    $tests->assertSame('route:42', $router->dispatch(
        Request::create('GET', '/posts/42', ['query' => ['from' => 'route']])
    )->body());

    $store = $router->dispatch(Request::create('POST', '/posts', ['body' => ['title' => 'Olá']]));
    $tests->assertSame(HTTP_CREATED, $store->status());
    $tests->assertSame('{"title":"Olá"}', $store->body());
});

$tests->run('dispatch answers 404, 405 and OPTIONS with the right headers', function () use ($tests): void {
    Router::reset();
    Router::get('/posts', [DispatchTestController::class, 'home']);
    Router::delete('/posts', [DispatchTestController::class, 'home']);

    $router = new Router(new Container());

    $tests->assertSame(HTTP_NOT_FOUND, $router->dispatch(Request::create('GET', '/nada'))->status());

    /*
     * Allow used to be emitted once with header() before the 405 and OPTIONS
     * branches split, so both inherited it. A returned response carries only
     * what it was handed, so both must set it explicitly.
     */
    $notAllowed = $router->dispatch(Request::create('PUT', '/posts'));
    $tests->assertSame(HTTP_METHOD_NOT_ALLOWED, $notAllowed->status());
    $tests->assertSame('GET, DELETE, HEAD, OPTIONS', $notAllowed->header('Allow'));

    $options = $router->dispatch(Request::create('OPTIONS', '/posts'));
    $tests->assertSame(HTTP_NO_CONTENT, $options->status());
    $tests->assertSame('GET, DELETE, HEAD, OPTIONS', $options->header('Allow'));
    $tests->assertSame('', $options->body());
});

$tests->run('middleware runs global first, then group, then route', function () use ($tests): void {
    Router::reset();

    $stamp = static fn (string $label): callable
        => static fn (Request $request, callable $next): Response
            => $next($request->withAttribute(
                'trail',
                trim(((string) $request->attribute('trail', '')) . ' ' . $label)
            ));

    Router::group('/admin', function () use ($stamp): void {
        Router::get('/panel', [DispatchTestController::class, 'trail'])
            ->middleware($stamp('route'));
    }, 'admin.', [$stamp('group')]);

    $router = (new Router(new Container()))->middleware($stamp('global'));

    $tests->assertSame(
        'global group route',
        $router->dispatch(Request::create('GET', '/admin/panel'))->body()
    );
});

$tests->run('middleware can refuse a request before the controller runs', function () use ($tests): void {
    Router::reset();
    Router::get('/private', [DispatchTestController::class, 'home']);

    $deny = static fn (Request $request, callable $next): Response
        => $request->bearerToken() === null
            ? Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED)
            : $next($request);

    $router = (new Router(new Container()))->middleware($deny);

    $refused = $router->dispatch(Request::create('GET', '/private'));
    $tests->assertSame(HTTP_UNAUTHORIZED, $refused->status());
    $tests->assertSame('{"message":"Unauthorized"}', $refused->body());

    $allowed = $router->dispatch(Request::create('GET', '/private', [
        'headers' => ['Authorization' => 'Bearer token'],
    ]));
    $tests->assertSame(HTTP_OK, $allowed->status());
});

$tests->run('global middleware also wraps requests that match no route', function () use ($tests): void {
    // CORS headers and request logging that skip 404s are a bug.
    Router::reset();

    $router = (new Router(new Container()))->middleware(
        static fn (Request $request, callable $next): Response
            => $next($request)->withHeader('X-Served-By', 'sfphp')
    );

    $response = $router->dispatch(Request::create('GET', '/nada'));

    $tests->assertSame(HTTP_NOT_FOUND, $response->status());
    $tests->assertSame('sfphp', $response->header('X-Served-By'));
});

$tests->run('a failing action becomes a 500 instead of a blank page', function () use ($tests): void {
    Router::reset();
    Router::get('/boom', [DispatchTestController::class, 'boom']);
    Router::get('/silent', [DispatchTestController::class, 'returnsNothing']);
    Router::get('/missing', [DispatchTestController::class, 'naoExiste']);

    $router = new Router(new Container());

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/boom'))->status());

    /*
     * An action that returns nothing is the "forgot the return statement"
     * bug. It has to fail loudly rather than serve an empty 200.
     */
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/silent'))->status());

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/missing'))->status());
});

$tests->run('csrf verification finally applies by default', function () use ($tests): void {
    /*
     * Csrf has had tokens, hash_equals and the form helpers for a long time,
     * but nothing in the framework ever called the verification: every
     * application had to remember to do it in each action, and forgetting
     * produced no error at all. The pipeline is the first place the check can
     * apply by default.
     */
    Router::reset();
    Router::get('/form', [DispatchTestController::class, 'home']);
    Router::post('/form', [DispatchTestController::class, 'home']);

    Csrf::startSession();
    $token = Csrf::token();

    $router = (new Router(new Container()))->middleware(VerifyCsrfToken::class);

    // Safe methods are never blocked.
    $tests->assertSame(HTTP_OK, $router->dispatch(Request::create('GET', '/form'))->status());

    // A state-changing request without a token is refused.
    $tests->assertSame(
        HTTP_FORBIDDEN,
        $router->dispatch(Request::create('POST', '/form'))->status()
    );

    // With the right token in the field, it passes.
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['body' => ['_token' => $token]])
    )->status());

    // And with the token in the header, as an AJAX call sends it.
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['headers' => ['X-CSRF-Token' => $token]])
    )->status());

    // A wrong token is refused, and the refusal is negotiated.
    $refused = $router->dispatch(Request::create('POST', '/form', [
        'body' => ['_token' => 'errado'],
        'headers' => ['Accept' => 'application/json'],
    ]));
    $tests->assertSame(HTTP_FORBIDDEN, $refused->status());
    $tests->assertSame('application/json; charset=utf-8', $refused->header('Content-Type'));

    /*
     * A bearer token is attached by the client on purpose; a browser never
     * sends one by itself, so there is no cross-site request to forge.
     */
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['headers' => ['Authorization' => 'Bearer abc']])
    )->status());

    // Exempt prefixes let a token-authenticated API opt out.
    $exempt = (new Router(new Container()))->middleware(new VerifyCsrfToken(['/form']));
    $tests->assertSame(HTTP_OK, $exempt->dispatch(Request::create('POST', '/form'))->status());
});

$tests->run('forwarding headers are believed only from a trusted proxy', function () use ($tests): void {
    $behindProxy = static fn (): Request => Request::create('GET', '/', [
        'server' => ['REMOTE_ADDR' => '10.0.0.7'],
        'headers' => [
            'X-Forwarded-For' => '203.0.113.9, 10.0.0.7',
            'X-Forwarded-Proto' => 'https',
        ],
    ]);

    $previous = Request::trustedProxies();

    try {
        // Nothing is trusted by default, so the headers are ignored.
        Request::setTrustedProxies([]);
        $tests->assertSame('10.0.0.7', $behindProxy()->ip());
        $tests->assertSame(false, $behindProxy()->isSecure());

        Request::setTrustedProxies(['10.0.0.0/8']);
        $tests->assertSame('203.0.113.9', $behindProxy()->ip());
        $tests->assertTrue($behindProxy()->isSecure());

        /*
         * A visitor outside the trusted range cannot claim an address, which
         * matters the moment anything rate limits or logs by IP.
         */
        $forged = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '198.51.100.4'],
            'headers' => ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'],
        ]);

        $tests->assertSame('198.51.100.4', $forged->ip());
        $tests->assertSame(false, $forged->isSecure());

        // A literal address works alongside a range.
        Request::setTrustedProxies(['10.0.0.7']);
        $tests->assertSame('203.0.113.9', $behindProxy()->ip());
    } finally {
        Request::setTrustedProxies($previous);
    }
});

$tests->run('rate limiting counts a client and refuses past the limit', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());
    $limit = new RateLimit(maxAttempts: 3, decaySeconds: 60, name: 'teste', cache: $cache);

    $request = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.1'],
        'headers' => ['Accept' => 'application/json'],
    ]);

    $destination = static fn (Request $passed): Response => Response::text('ok');

    $first = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_OK, $first->status());
    $tests->assertSame('3', $first->header('X-RateLimit-Limit'));
    $tests->assertSame('2', $first->header('X-RateLimit-Remaining'));

    $limit->handle($request, $destination);
    $third = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_OK, $third->status());
    $tests->assertSame('0', $third->header('X-RateLimit-Remaining'));

    $refused = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_TOO_MANY_REQUESTS, $refused->status());
    $tests->assertTrue((int) $refused->header('Retry-After') > 0);

    // A different client has its own allowance.
    $other = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.2'],
    ]);
    $tests->assertSame(HTTP_OK, $limit->handle($other, $destination)->status());
});

$tests->run('security headers are added, and the risky ones only on request', function () use ($tests): void {
    $destination = static fn (Request $request): Response => Response::text('ok');

    $plain = (new SecurityHeaders())->handle(Request::create('GET', '/'), $destination);

    $tests->assertSame('nosniff', $plain->header('X-Content-Type-Options'));
    $tests->assertSame('DENY', $plain->header('X-Frame-Options'));
    $tests->assertSame('strict-origin-when-cross-origin', $plain->header('Referrer-Policy'));

    /*
     * CSP is off unless asked for: a policy that does not match the
     * application's assets breaks the page with no error the developer sees,
     * and the framework cannot know what those assets are.
     */
    $tests->assertSame(null, $plain->header('Content-Security-Policy'));

    $configured = new SecurityHeaders(
        contentSecurityPolicy: "default-src 'self'",
        frameOptions: 'SAMEORIGIN',
        hstsMaxAge: 31536000
    );

    $overHttp = $configured->handle(Request::create('GET', '/'), $destination);
    $tests->assertSame("default-src 'self'", $overHttp->header('Content-Security-Policy'));
    $tests->assertSame('SAMEORIGIN', $overHttp->header('X-Frame-Options'));

    // HSTS only over HTTPS: a browser ignores it otherwise, so sending it on a
    // plain connection would look like protection without being any.
    $tests->assertSame(null, $overHttp->header('Strict-Transport-Security'));

    $overHttps = $configured->handle(
        Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]),
        $destination
    );
    $tests->assertSame('max-age=31536000', $overHttps->header('Strict-Transport-Security'));
});

$tests->run('the rate limiter counts through the atomic counter', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-ratelimit-' . bin2hex(random_bytes(6));
    $cache = new CacheManager(new FileDriver($directory));
    $limit = new RateLimit(maxAttempts: 2, decaySeconds: 60, name: 'atomic', cache: $cache);

    $request = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.9'],
        'headers' => ['Accept' => 'application/json'],
    ]);

    $destination = static fn (Request $passed): Response => Response::text('ok');

    $tests->assertSame('1', $limit->handle($request, $destination)->header('X-RateLimit-Remaining'));
    $tests->assertSame('0', $limit->handle($request, $destination)->header('X-RateLimit-Remaining'));

    $refused = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_TOO_MANY_REQUESTS, $refused->status());

    /*
     * Retry-After comes from the counter's remaining lifetime, so it counts
     * down towards the window's close instead of restarting at the full decay
     * on every refusal.
     */
    $retryAfter = (int) $refused->header('Retry-After');
    $tests->assertTrue($retryAfter > 0 && $retryAfter <= 60);

    $cache->flush();
    @rmdir($directory);
});

$tests->run('an unbound interface falls back to the default instead of failing', function () use ($tests): void {
    /*
     * This came out of registering StartSession by class name. Its constructor
     * takes `?SessionHandlerInterface $handler = null`, meaning "I will pick
     * one myself unless you bind one" — and the container resolved the type
     * anyway, so every request died with "Class SessionHandlerInterface does
     * not exist" and the middleware could only be registered as an instance.
     *
     * An interface is not instantiable, so there is nothing to autowire; when
     * the parameter already carries an answer, that answer is the right one.
     */
    $resolved = (new Container())->get(ContainerOptionalDependency::class);

    $tests->assertSame(null, $resolved->handler);
    $tests->assertSame(null, $resolved->items);

    // A binding still wins over the default.
    $container = new Container();
    $handler = new CacheHandler(new CacheManager(new MemoryDriver()));
    $container->set(SessionHandlerInterface::class, $handler);

    $tests->assertSame($handler, $container->get(ContainerOptionalDependency::class)->handler);
});

$tests->run('the error handler redacts in production and explains in development', function () use ($tests): void {
    $failure = new RuntimeException('the database password is hunter2');

    Config::set('APP_ENV', 'production');
    $hidden = ErrorHandler::toResponse($failure);
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $hidden->status());

    /*
     * An exception message routinely carries a connection string or a query.
     * Production gets the translated generic message and nothing else.
     */
    $tests->assertSame(false, str_contains($hidden->body(), 'hunter2'));
    $tests->assertSame(true, str_contains($hidden->body(), __('http.server_error_message')));

    Config::set('APP_ENV', 'development');
    $shown = ErrorHandler::toResponse($failure);
    $tests->assertSame(true, str_contains($shown->body(), 'hunter2'));

    // The content type follows what the request asked for.
    $json = ErrorHandler::toResponse($failure, Request::create('GET', '/', [
        'headers' => ['Accept' => 'application/json'],
    ]));
    $tests->assertSame(true, str_contains((string) $json->header('Content-Type'), 'application/json'));
    $tests->assertSame('the database password is hunter2', json_decode($json->body(), true)['message']);

    // The error page carries no external request, so it renders when the
    // network is exactly what is broken.
    $tests->assertSame(0, preg_match('#(src|href)=["\']https?://#', $shown->body()));

    Config::forget('APP_ENV');
});

$tests->run('one action answers a fragment and a whole page', function () use ($tests): void {
    /*
     * The pattern the example application had written by hand: SFJS asks for
     * the piece that changed, a browser with no JavaScript submits the same
     * form and needs the page around it. Written twice, the two answers drift
     * apart — so this is one call with the page as a wrapper.
     */
    $panel = new SfphpProject\src\View\Sfht('<p>inner</p>');
    $page = static fn (SfphpProject\src\View\Sfht $inner): string => '<html>' . $inner . '</html>';

    $xhr = Request::create('GET', '/panel', ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
    $plain = Request::create('GET', '/panel');

    $tests->assertSame(true, $xhr->isFragment());
    $tests->assertSame(false, $plain->isFragment());

    $tests->assertSame('<p>inner</p>', Response::fragment($xhr, $panel, page: $page)->body());
    $tests->assertSame('<html><p>inner</p></html>', Response::fragment($plain, $panel, page: $page)->body());

    // With no page to fall back on, both get the fragment.
    $tests->assertSame('<p>inner</p>', Response::fragment($plain, $panel)->body());
});

$tests->run('a response can be built from anywhere, including back to where you were', function () use ($tests): void {
    /*
     * Response is a factory, which is what makes a base class unnecessary: a
     * controller inherits nothing and still reaches every kind of response.
     * There was a Controller class here offering $this->view() and
     * $this->redirect(); it inherited a whole class to shorten two calls that
     * already existed, so route() and back() moved here and it went away.
     */
    $tests->assertSame(false, class_exists('SfphpProject\\src\\Http\\Controller'));

    // back() follows the referer when it is this site.
    $local = Response::back(Request::create('GET', '/x', [
        'headers' => ['Referer' => 'https://example.test/posts?page=2', 'Host' => 'example.test'],
    ]));
    $tests->assertSame('/posts?page=2', $local->header('Location'));

    // A relative referer has no host to disagree with.
    $relative = Response::back(Request::create('GET', '/x', [
        'headers' => ['Referer' => '/posts', 'Host' => 'example.test'],
    ]));
    $tests->assertSame('/posts', $relative->header('Location'));

    /*
     * The referer is a header, so the visitor chooses it. Following it to
     * another origin is an open redirect — the classic way a phishing link
     * borrows a domain's good name.
     */
    foreach (['https://evil.test/steal', '//evil.test/steal', 'javascript:alert(1)', ''] as $hostile) {
        $refused = Response::back(
            Request::create('GET', '/x', ['headers' => ['Referer' => $hostile, 'Host' => 'example.test']]),
            '/fallback'
        );
        $tests->assertSame('/fallback', $refused->header('Location'));
    }

    // And a named route, which is the other redirect an application writes by hand.
    Router::reset();
    Router::get('/posts/id:number', [PostController::class, 'show'])->name('posts.show');
    $tests->assertSame('/posts/7', Response::route('posts.show', ['id' => 7])->header('Location'));
    Router::reset();
});

$tests->run('a request whose body is not the json it claims is refused before the action', function () use ($tests): void {
    /*
     * This was two methods on a base class every API controller had to extend,
     * so the check ran only where somebody remembered to call it. Refusing a
     * request that cannot be handled is what middleware is for: it happens
     * once, before the action, for everything it is registered on.
     */
    $middleware = new SfphpProject\src\Http\Middleware\RequireJson();
    $reached = false;
    $next = function (Request $request) use (&$reached): Response {
        $reached = true;

        return Response::json(['seen' => $request->attribute('json')]);
    };

    // The good case: decoded, handed on, and readable by the action.
    $ok = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'rawBody' => '{"title":"Olá"}',
    ]), $next);

    $tests->assertSame(true, $reached);
    $tests->assertSame(['title' => 'Olá'], json_decode($ok->body(), true)['seen']);

    // 415 is "I do not speak that".
    $reached = false;
    $wrongType = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'text/xml'],
        'rawBody' => '<post/>',
    ]), $next);

    $tests->assertSame(HTTP_UNSUPPORTED_MEDIA_TYPE, $wrongType->status());
    $tests->assertSame(false, $reached);

    // 400 is "that was not valid JSON" — a different answer, because a client
    // debugging one is looking somewhere else entirely.
    $broken = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'rawBody' => '{"title":',
    ]), $next);

    $tests->assertSame(HTTP_BAD_REQUEST, $broken->status());
    $tests->assertSame(false, $reached);

    /*
     * A GET carries no body. Refusing it would make the middleware unusable on
     * a group that both reads and writes, which is most groups.
     */
    $read = $middleware->handle(Request::create('GET', '/api/posts'), $next);
    $tests->assertSame(true, $reached);
    $tests->assertSame(HTTP_OK, $read->status());

    // A write with no Content-Type passes unless the endpoint insists.
    $reached = false;
    $silent = $middleware->handle(Request::create('POST', '/api/posts'), $next);
    $tests->assertSame(true, $reached);
    $tests->assertSame(HTTP_OK, $silent->status());

    $strict = (new SfphpProject\src\Http\Middleware\RequireJson(required: true))
        ->handle(Request::create('POST', '/api/posts'), $next);
    $tests->assertSame(HTTP_UNSUPPORTED_MEDIA_TYPE, $strict->status());
});

$tests->run('a route names its action as [Controller::class, method], from any namespace', function () use ($tests): void {
    /*
     * The router used to prepend app\controllers\ to a controller given as a
     * string, so PostController::class — the spelling an editor can follow —
     * became app\controllers\app\controllers\PostController and was not found.
     */
    if (!class_exists('Acme\\Web\\PingController', false)) {
        eval('namespace Acme\\Web; final class PingController { public function ping($request, string $id): string { return "pong " . $id; } }');
    }

    Router::reset();
    Router::get('/ping/id:number', [\Acme\Web\PingController::class, 'ping'])->name('ping');

    $response = (new Router(new Container()))->dispatch(Request::create('GET', '/ping/7'));
    $tests->assertSame(200, $response->status());
    $tests->assertSame('pong 7', $response->body());

    // The old spelling is refused at boot, with the new one in the message.
    try {
        Router::get('/posts', 'PostController', 'index');
        $tests->assertTrue(false, 'the string form should have been refused');
    } catch (InvalidArgumentException $e) {
        $tests->assertTrue(str_contains($e->getMessage(), "[PostController::class, 'index']"));
    }

    // Anything that is not a [class, method] pair is refused too.
    foreach ([[\Acme\Web\PingController::class], ['', 'ping'], ['class' => 'X', 'method' => 'y']] as $bad) {
        $tests->assertThrows(fn () => Router::get('/bad', $bad), InvalidArgumentException::class);
    }

    Router::reset();
});

$tests->run('rate limiting uses the application cache unless given one', function () use ($tests): void {
    /*
     * It used to build its own file cache, so CACHE_DRIVER=redis still left
     * one counter per instance. The store is whatever cache() is.
     */
    $limit = new RateLimit(maxAttempts: 100, decaySeconds: 60, name: 'shared-' . bin2hex(random_bytes(4)));
    $request = Request::create('GET', '/', ['server' => ['REMOTE_ADDR' => '203.0.113.77']]);
    $limit->handle($request, static fn (Request $passed): Response => Response::text('ok'));

    $property = new ReflectionProperty(RateLimit::class, 'cache');
    $property->setAccessible(true);
    $tests->assertTrue($property->getValue($limit) === cache());
});

$tests->run('an exception that names a status is answered with it, not with 500', function () use ($tests): void {
    Router::reset();

    eval('namespace SfphpTest\Status; final class StatusController {
        public function missing(\SfphpProject\src\Http\Request $r): never { throw new \SfphpProject\src\Database\ModelNotFoundException("No Post 5"); }
        public function refused(\SfphpProject\src\Http\Request $r): never { throw new \SfphpProject\src\Auth\AuthorizationException("no"); }
        public function body(\SfphpProject\src\Http\Request $r): \SfphpProject\src\Http\Response { return \SfphpProject\src\Http\Response::json($r->json()); }
        public function conflict(\SfphpProject\src\Http\Request $r): never { throw new \SfphpProject\src\Http\HttpException(409, "That slug is taken."); }
        public function user(\SfphpProject\src\Http\Request $r, string $user): \SfphpProject\src\Http\Response { return \SfphpProject\src\Http\Response::text(var_export($r->user(), true) . "|" . $r->route("user")); }
        public function page(\SfphpProject\src\Http\Request $r): \SfphpProject\src\Http\Response { return \SfphpProject\src\Http\Response::text("page"); }
    }');

    $c = \SfphpTest\Status\StatusController::class;
    Router::get('/missing', [$c, 'missing']);
    Router::get('/refused', [$c, 'refused']);
    Router::post('/body', [$c, 'body']);
    Router::get('/conflict', [$c, 'conflict']);
    Router::get('/profile/user:alpha', [$c, 'user']);
    Router::get('/page', [$c, 'page']);

    $router = new Router(new Container());
    $json = ['headers' => ['Accept' => 'application/json']];

    $tests->assertSame(404, $router->dispatch(Request::create('GET', '/missing'))->status());
    $tests->assertSame(403, $router->dispatch(Request::create('GET', '/refused'))->status());
    $tests->assertSame(400, $router->dispatch(Request::create('POST', '/body', ['rawBody' => '{nope', 'headers' => ['Content-Type' => 'application/json']]))->status());

    $conflict = $router->dispatch(Request::create('GET', '/conflict', $json));
    $tests->assertSame(409, $conflict->status());
    $tests->assertSame('{"message":"That slug is taken."}', $conflict->body());

    // 404 and 405 answer JSON to a client that asks for it, like every other error.
    $notFound = $router->dispatch(Request::create('GET', '/nowhere', $json));
    $tests->assertSame(404, $notFound->status());
    $tests->assertSame('application/json', explode(';', (string) $notFound->header('Content-Type'))[0]);
    $tests->assertSame(405, $router->dispatch(Request::create('DELETE', '/page', $json))->status());

    // HEAD is answered by a GET route; Allow names HEAD and OPTIONS.
    $tests->assertSame(200, $router->dispatch(Request::create('HEAD', '/page'))->status());
    $tests->assertSame('GET, HEAD, OPTIONS', $router->dispatch(Request::create('DELETE', '/page'))->header('Allow'));

    // A route parameter named "user" no longer replaces the authenticated user.
    $withUser = Request::create('GET', '/profile/admin')->withAttribute('user', 'ana');
    $tests->assertSame("'ana'|admin", $router->dispatch($withUser)->body());

    // An error page inlines only the part of SFCSS it uses.
    $page = $router->dispatch(Request::create('GET', '/nowhere'));
    $tests->assertTrue(strlen($page->body()) < 20000);
    $tests->assertTrue(str_contains($page->body(), '.btn-primary'));

    Router::reset();
});

$tests->run('the CSRF check reads a JSON body, exempts by path segment and does not trust a bearer header next to a session', function () use ($tests): void {
    $_SESSION = [];
    $token = Csrf::token();
    $middleware = new VerifyCsrfToken(['/api']);
    $ok = static fn (Request $r): Response => Response::text('ok');

    // SFJS sends a form as JSON; the token inside it used to be ignored.
    $json = Request::create('POST', '/form', [
        'rawBody' => json_encode(['_token' => $token, 'name' => 'Ana']),
        'headers' => ['Content-Type' => 'application/json'],
    ]);
    $tests->assertSame(200, $middleware->handle($json, $ok)->status());

    $tests->assertSame(200, $middleware->handle(Request::create('POST', '/api/posts'), $ok)->status());
    $tests->assertSame(403, $middleware->handle(Request::create('POST', '/apikeys'), $ok)->status());

    $bearerOnly = Request::create('POST', '/form', ['headers' => ['Authorization' => 'Bearer x']]);
    $tests->assertSame(200, $middleware->handle($bearerOnly, $ok)->status());

    $bearerAndSession = Request::create('POST', '/form', [
        'headers' => ['Authorization' => 'Bearer x'],
        'cookies' => [session_name() ?: 'PHPSESSID' => 'abc'],
    ]);
    $tests->assertSame(403, $middleware->handle($bearerAndSession, $ok)->status());

    // The refusal is the shared error page, styled, with a way home.
    $refused = $middleware->handle(Request::create('POST', '/form'), $ok);
    $tests->assertTrue(str_contains($refused->body(), 'btn btn-primary'));
});

$tests->run('a rate limit counts a route, not each path that reaches it', function () use ($tests): void {
    $limit = new RateLimit(maxAttempts: 2, decaySeconds: 60, name: 'pattern', cache: new CacheManager(new MemoryDriver()));
    $ok = static fn (Request $r): Response => Response::text('ok');
    $statuses = [];

    foreach (['a', 'b', 'c'] as $code) {
        $request = Request::create('POST', '/reset/' . $code, ['server' => ['REMOTE_ADDR' => '203.0.113.5']])
            ->withRouteParameters(['code' => $code], '/reset/code:alphanum');
        $statuses[] = $limit->handle($request, $ok)->status();
    }

    $tests->assertSame([200, 200, 429], $statuses);
});

$tests->run('back() stays on this site, port included, and the client address cannot be forged', function () use ($tests): void {
    $back = fn (string $referer, string $host = 'app.test'): ?string => Response::back(
        Request::create('GET', '/', ['headers' => ['Referer' => $referer, 'Host' => $host]])
    )->header('Location');

    $tests->assertSame('/', $back('http://app.test//evil.example/x'));
    $tests->assertSame('/', $back('/\\evil.example/x'));
    $tests->assertSame('/', $back("http://app.test/a\x01b"));
    $tests->assertSame('/', $back('https://evil.example/form'));
    $tests->assertSame('/form?x=1', $back('http://app.test/form?x=1'));
    $tests->assertSame('/form', $back('http://127.0.0.1:8000/form', '127.0.0.1:8000'));

    Request::setTrustedProxies(['10.0.0.1']);

    try {
        $forged = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'headers' => ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9', 'X-Forwarded-Proto' => 'https, http'],
        ]);
        // The proxy appended 203.0.113.9; "1.2.3.4" is what the client wrote.
        $tests->assertSame('203.0.113.9', $forged->ip());
        $tests->assertSame(false, $forged->isSecure());

        $untrusted = Request::create('GET', '/', ['server' => ['REMOTE_ADDR' => '198.51.100.7'], 'headers' => ['X-Forwarded-For' => '1.2.3.4']]);
        $tests->assertSame('198.51.100.7', $untrusted->ip());
    } finally {
        Request::setTrustedProxies([]);
    }

    // "//admin/panel" is the path /admin/panel, not the host "admin".
    $tests->assertSame('/admin/panel', Request::create('GET', '//admin/panel')->path);
    $tests->assertSame('/x', Request::create('GET', '///x?y=1')->path);
    $tests->assertSame('/', Request::create('GET', '?a=1')->path);
});

$tests->run('an id too large to be an int is a 404, a deprecation is not an exception, and a JWT needs no e-mail', function () use ($tests): void {
    $route = new Route(GET, '/posts/id:number', 'X', 'show');
    $tests->assertSame(null, $route->match('/posts/99999999999999999999'));
    $tests->assertSame(['id' => '42'], $route->match('/posts/42'));

    $tests->assertSame(true, ErrorHandler::handlePhpError(E_USER_DEPRECATED, 'old api', __FILE__, __LINE__));

    // Its own key: it used to borrow the one an earlier JWT test left behind.
    $key = $_ENV['JWT_KEY'] ?? null;
    $_ENV['JWT_KEY'] = bin2hex(random_bytes(32));

    try {
        $token = JWT::generate(['id' => 7, 'role' => 'editor']);
        $claims = JWT::claims($token);
        $tests->assertSame(7, $claims['id']);
        $tests->assertSame('editor', $claims['role']);
        $tests->assertSame(false, array_key_exists('email', $claims));
    } finally {
        if ($key === null) {
            unset($_ENV['JWT_KEY']);
        } else {
            $_ENV['JWT_KEY'] = $key;
        }
    }

    $tests->assertThrows(fn () => new \SfphpProject\src\Session\DatabaseHandler('sessions; DROP TABLE users'), InvalidArgumentException::class);
});
