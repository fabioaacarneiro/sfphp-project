<?php

/**
 * A server whose only job is to be measured.
 *
 * It boots the framework the way public/index.php does and adds the endpoints
 * the benchmark needs, so the example application stays an example instead of
 * carrying routes nobody asked for.
 *
 *   php benchmarks/origin.php 127.0.0.1:8300 &
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8200 benchmarks/server.php &
 *   ab -n 500 -c 50 http://127.0.0.1:8200/http-parallel
 *
 * The worker count matters and is not a detail: PHP's built-in server answers
 * from a fixed pool, so the requests per second it reaches are as much a fact
 * about that pool as about the framework. Any number taken from here has to
 * say what the pool was.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Container;
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Router;

use function SfphpProject\src\Async\await;

Bootstrap::load($root);

$origin = getenv('SFPHP_BENCH_ORIGIN') ?: 'http://127.0.0.1:8300';
$delay = (int) (getenv('SFPHP_BENCH_DELAY') ?: 100);

// A static file the built-in server should serve itself.
if (php_sapi_name() === 'cli-server' && is_file($root . '/public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

/*
 * PHP autoloads classes, not functions, so a compiled component has to be
 * required. The front controller of a real project does the same.
 */
$compiled = $root . '/app/components/compiled';

if (is_dir($compiled)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($compiled, FilesystemIterator::SKIP_DOTS)) as $component) {
        if ($component->getExtension() === 'php') {
            require_once $component->getPathname();
        }
    }
}

Router::reset();

Router::get('/hello', [BenchController::class, 'hello']);
Router::get('/json', [BenchController::class, 'json']);
Router::get('/phpx', [BenchController::class, 'phpx']);
Router::get('/http', [BenchController::class, 'http']);
Router::get('/http-parallel', [BenchController::class, 'httpParallel']);

/**
 * The endpoints under measurement.
 */
final class BenchController
{
    /**
     * The cheapest possible answer: routing and a response, nothing else.
     *
     * @param Request $request The request
     * @return Response The response
     */
    public function hello(Request $request): Response
    {
        return Response::text('hello');
    }

    /**
     * The same, encoded as JSON.
     *
     * @param Request $request The request
     * @return Response The response
     */
    public function json(Request $request): Response
    {
        return Response::json(['hello' => true, 'at' => time()]);
    }

    /**
     * A page built from .phpx components, to price the component runtime.
     *
     * @param Request $request The request
     * @return Response The response
     */
    public function phpx(Request $request): Response
    {
        $items = ['alpha', 'beta', 'gamma', 'delta'];

        return Response::html(
            (string) SfphpProject\app\components\Card('Benchmark', 'A component rendering under load')
            . (string) SfphpProject\app\components\BulletList($items)
        );
    }

    /**
     * One outbound call, awaited.
     *
     * @param Request $request The request
     * @return Response The response
     */
    public function http(Request $request): Response
    {
        global $origin, $delay;

        $response = await(Http::getAsync($origin . '/delay?ms=' . $delay));

        return Response::json(['status' => $response->status()]);
    }

    /**
     * Three outbound calls that overlap.
     *
     * Sequentially this endpoint would cost three delays. It costs one, which
     * is the whole argument for the runtime being in a web framework at all:
     * a page that needs three services should not take three times as long.
     *
     * @param Request $request The request
     * @return Response The response
     */
    public function httpParallel(Request $request): Response
    {
        global $origin, $delay;

        $url = $origin . '/delay?ms=' . $delay;

        $responses = await(CompositeFuture::all(
            Http::getAsync($url),
            Http::getAsync($url),
            Http::getAsync($url)
        ));

        return Response::json(['statuses' => array_map(static fn ($one) => $one->status(), $responses)]);
    }
}

$container = new Container();

/*
 * No middleware. The endpoints below are measured for what the runtime costs,
 * and a session, a CSRF check and a locale negotiation would be measured too —
 * each worth knowing about, none of them what this benchmark is asking.
 */
$router = new Router($container);

$request = Request::fromGlobals();
$response = $router->dispatch($request);

(new Emitter())->emit($response, $request->method);
