<?php

/*
 * The HTTP client.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\ClientResponse;
use SfphpProject\src\Http\Http;

$tests->run('a response describes what came back without pretending it is an error', function () use ($tests): void {
    /*
     * A 404 and a 500 are answers: the server was reached, understood and said
     * no. They come back to be inspected rather than thrown, because a missing
     * record is routinely an expected result. throw() is how a caller opts in
     * where it is not.
     */
    $ok = new ClientResponse(200, '{"id":7}', ['Content-Type' => 'application/json'], 'https://x.test/a');

    $tests->assertSame(true, $ok->ok());
    $tests->assertSame(false, $ok->failed());
    $tests->assertSame(['id' => 7], $ok->json());
    $tests->assertSame($ok, $ok->throw());

    // Header lookup ignores case, because a server's capitalisation is its own.
    $tests->assertSame('application/json', $ok->header('CONTENT-TYPE'));
    $tests->assertSame(null, $ok->header('X-Absent'));

    $missing = new ClientResponse(404, '{"message":"no"}', [], 'https://x.test/b');
    $tests->assertSame(true, $missing->failed());
    $tests->assertSame(true, $missing->clientError());
    $tests->assertSame(false, $missing->serverError());

    $broken = new ClientResponse(503, 'unavailable', [], 'https://x.test/c');
    $tests->assertSame(true, $broken->serverError());

    // A body that is not JSON answers null rather than throwing, unless asked.
    $html = new ClientResponse(200, '<html></html>', [], 'https://x.test/d');
    $tests->assertSame(null, $html->json());
    $tests->assertThrows(fn () => $html->json(true), ClientException::class);

    // throw() carries what the service said, so nobody has to go read its logs.
    try {
        $missing->throw();
        $tests->assertSame('throw() did not throw', false);
    } catch (ClientException $exception) {
        $tests->assertSame(true, str_contains($exception->getMessage(), '404'));
        $tests->assertSame(true, str_contains($exception->getMessage(), 'no'));
    }
});

$tests->run('a client is a value, so configuring one cannot change another', function () use ($tests): void {
    /*
     * The verbs were static at first, so a configured client calling get()
     * built a fresh default and silently dropped everything — the token and the
     * base URL never left the building and the request looked perfectly fine.
     * That is what this shape is guarding.
     */
    $plain = Http::client();
    $authenticated = $plain->token('secret');
    $based = $authenticated->base('https://api.test/v1');

    $tests->assertSame(true, $plain !== $authenticated);
    $tests->assertSame(true, $authenticated !== $based);

    $read = new ReflectionProperty(SfphpProject\src\Http\Client::class, 'headers');
    $read->setAccessible(true);

    // Configuring the second did not reach back into the first.
    $tests->assertSame([], $read->getValue($plain));
    $tests->assertSame(['Authorization' => 'Bearer secret'], $read->getValue($authenticated));
    $tests->assertSame(['Authorization' => 'Bearer secret'], $read->getValue($based));

    $basic = new ReflectionProperty(SfphpProject\src\Http\Client::class, 'headers');
    $basic->setAccessible(true);
    $credentials = $basic->getValue(Http::withBasic('ana', 'senha'));
    $tests->assertSame('Basic ' . base64_encode('ana:senha'), $credentials['Authorization']);
});

$tests->run('the client talks to a real server', function () use ($tests): void {
    /*
     * A real socket, because what a client does happens on the wire: a mock
     * would confirm that the code calls the functions it calls, which was never
     * the thing in doubt.
     */
    if (!extension_loaded('curl')) {
        return;
    }

    $port = 8000 + (getmypid() % 900);
    $root = dirname(__DIR__) . '/fixtures';
    $command = sprintf(
        'php -S 127.0.0.1:%d -t %s %s/http-server.php > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg($root),
        escapeshellarg($root)
    );

    $pid = (int) trim((string) shell_exec($command));
    $base = 'http://127.0.0.1:' . $port;

    try {
        // Wait for it to accept, rather than sleeping a guessed amount.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if ($probe !== false) {
                fclose($probe);
                break;
            }

            usleep(100_000);
        }

        $response = Http::get($base . '/users', ['page' => 2, 'q' => 'ação']);

        $tests->assertSame(200, $response->status());
        $tests->assertSame(['page' => '2', 'q' => 'ação'], $response->json()['query']);
        $tests->assertSame('sfphp-test', $response->header('X-Served-By'));

        // A body is JSON unless told otherwise, and says so in its type.
        $posted = Http::post($base . '/users', ['name' => 'Ana', 'cidade' => 'São Paulo']);
        $tests->assertSame('{"name":"Ana","cidade":"São Paulo"}', $posted->json()['body']);
        $tests->assertSame('application/json', $posted->json()['headers']['CONTENT_TYPE'] ?? null);

        $form = Http::client()->asForm()->post($base . '/users', ['a' => 1, 'b' => 2]);
        $tests->assertSame('a=1&b=2', $form->json()['body']);

        // Configuration survives the call, which is the bug this guards.
        $api = Http::base($base)->token('t0k');
        $tests->assertSame('/invoices/7', $api->get('/invoices/7')->json()['path']);
        $tests->assertSame('Bearer t0k', $api->get('/me')->json()['headers']['HTTP_AUTHORIZATION'] ?? null);

        $tests->assertSame('PATCH', Http::patch($base . '/x', ['a' => 1])->json()['method']);
        $tests->assertSame('DELETE', Http::delete($base . '/x')->json()['method']);

        // An error is an answer, not an exception.
        $tests->assertSame(404, Http::get($base . '/status/404')->status());
        $tests->assertSame(true, Http::get($base . '/status/500')->serverError());

        /*
         * A timeout is not an answer, so it throws — and it has to actually
         * stop, or one slow service takes the whole application down with it.
         */
        $startedAt = microtime(true);
        $tests->assertThrows(fn () => Http::timeout(1)->get($base . '/slow'), ClientException::class);
        $tests->assertSame(true, microtime(true) - $startedAt < 4.0);

        // So is a connection nobody answered.
        $tests->assertThrows(
            fn () => Http::timeout(2)->get('http://127.0.0.1:' . ($port + 1) . '/'),
            ClientException::class
        );
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});

$tests->run('the client refuses https-to-http redirects and follows http-to-http ones', function () use ($tests): void {
    $protocols = new ReflectionMethod(\SfphpProject\src\Http\Client::class, 'redirectProtocols');
    $protocols->setAccessible(true);

    if (!extension_loaded('curl')) {
        return;
    }

    // A request that starts on https:// may only be redirected to https://.
    $tests->assertSame(CURLPROTO_HTTPS, $protocols->invoke(null, 'https://api.example.com/x'));
    $tests->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $protocols->invoke(null, 'http://127.0.0.1/x'));

    // And on the wire: a plain-http service that redirects is followed.
    $port = 9000 + (getmypid() % 900);
    $root = dirname(__DIR__) . '/fixtures';
    $pid = (int) trim((string) shell_exec(sprintf(
        'php -S 127.0.0.1:%d -t %s %s/http-server.php > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg($root),
        escapeshellarg($root)
    )));

    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if ($probe !== false) {
                fclose($probe);
                break;
            }

            usleep(100_000);
        }

        $response = Http::get('http://127.0.0.1:' . $port . '/redirect');
        $tests->assertSame(200, $response->status());
        $tests->assertSame('Redirected successfully', $response->body());

        $origin = 'http://127.0.0.1:' . $port;

        // A POST answered with 302 is followed with a GET, and only the final response's headers come back.
        $moved = Http::post($origin . '/post-redirect', ['a' => 1]);
        $tests->assertSame('GET', $moved->json()['method']);
        $tests->assertSame('application/json', $moved->header('Content-Type'));
        $tests->assertSame(null, $moved->header('X-From-Redirect'));
        $tests->assertSame('a, b', $moved->header('X-Multi'));
        $tests->assertSame($origin . '/echo-method', $moved->url());

        // HEAD returns at once instead of waiting for a body.
        $started = microtime(true);
        $tests->assertSame(200, Http::timeout(3)->send('HEAD', $origin . '/echo-method')->status());
        $tests->assertTrue(microtime(true) - $started < 2);

        // A line break in a header value would add headers of its own.
        $tests->assertThrows(
            fn () => Http::withHeaders(['X-A' => "v\r\nX-Injected: yes"])->get($origin . '/echo-method'),
            ClientException::class
        );

        // A client made for one origin keeps its token to that origin.
        $client = Http::base($origin)->token('secret');
        $tests->assertSame('Bearer secret', $client->get('/echo-method')->json()['authorization']);
        $tests->assertSame(null, $client->get('http://localhost:' . $port . '/echo-method')->json()['authorization']);
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});
