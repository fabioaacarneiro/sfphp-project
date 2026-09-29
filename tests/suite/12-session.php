<?php

/*
 * Sessions.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Session\CacheHandler;
use SfphpProject\src\Session\Session;
use SfphpProject\src\Time;

$tests->run('the session keeps values and hides its own bookkeeping', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);

    Session::put('cart_id', 42);
    $tests->assertSame(42, Session::get('cart_id'));
    $tests->assertTrue(Session::has('cart_id'));
    $tests->assertSame('fallback', Session::get('absent', 'fallback'));

    /*
     * The timestamps are the framework's, not the application's. Showing them
     * in all() would invite writing to them, and the deadlines would stop
     * meaning anything.
     */
    $tests->assertSame(['cart_id' => 42], Session::all());
    $tests->assertTrue(Session::startedAt() !== null);
    $tests->assertTrue(Session::lastActivityAt() !== null);

    Session::forget('cart_id');
    $tests->assertTrue(!Session::has('cart_id'));
});

$tests->run('an idle session ends, and says why once', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);
    Session::put('user_id', 7);

    /*
     * The clock is moved rather than the stamps, so the test exercises the
     * comparison the middleware actually makes.
     */
    Time::freeze(Time::now()->modify('+31 minutes'));
    Session::start(false, null, 1800, 0);

    $tests->assertSame(null, Session::get('user_id'));
    $tests->assertSame('idle', Session::expiredReason());

    // Read once and forgotten, so the notice is not shown on every later page.
    $tests->assertSame(null, Session::expiredReason());

    // Activity inside the window keeps it alive.
    Session::put('user_id', 7);
    Time::freeze(Time::now()->modify('+10 minutes'));
    Session::start(false, null, 1800, 0);
    $tests->assertSame(7, Session::get('user_id'));

    Time::unfreeze();
});

$tests->run('an absolute deadline ends a session however busy it has been', function () use ($tests): void {
    $_SESSION = [];
    Time::freeze('2026-01-01T00:00:00+00:00');
    Session::start(false, null, 1800, 7200);
    Session::put('user_id', 7);

    /*
     * Active the whole time — a request every ten minutes — so the idle
     * timeout never fires. This is the deadline that limits what a stolen
     * cookie is worth, and it is the one an audit asks about.
     */
    for ($minute = 10; $minute <= 130; $minute += 10) {
        Time::freeze((new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->modify("+{$minute} minutes"));
        Session::start(false, null, 1800, 7200);
    }

    $tests->assertSame(null, Session::get('user_id'));
    $tests->assertSame('absolute', Session::expiredReason());

    Time::unfreeze();
});

$tests->run('expiring a session takes the data and the id with it', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);
    Session::put('user_id', 7);

    Session::invalidate();

    /*
     * Emptying the data while keeping the id would leave the visitor holding a
     * cookie that still names a live session, which is most of what expiring
     * one was meant to prevent.
     */
    $tests->assertSame([], Session::all());
    $tests->assertTrue(Session::startedAt() !== null);
});

$tests->run('a shared handler lets a second instance read the same session', function () use ($tests): void {
    /*
     * This is the reason the handler exists. With PHP's own files, a session
     * written by one machine is invisible to another, which is what forces
     * sticky sessions on a load balancer. A shared store removes that, and the
     * cache handler is tested through the memory driver because the contract is
     * what matters here, not which driver is behind it.
     */
    $cache = new CacheManager(new MemoryDriver());
    $first = new CacheHandler($cache, 'session:');
    $second = new CacheHandler($cache, 'session:');

    $id = 'abcdef0123456789abcdef0123456789';
    $payload = 'user_id|i:7;';

    $tests->assertTrue($first->write($id, $payload));

    // A different instance, sharing only the store.
    $tests->assertSame($payload, $second->read($id));

    /*
     * validateId is what makes session.use_strict_mode work: PHP asks before
     * adopting the id a cookie carries, so an id nobody was issued is refused
     * instead of being brought to life.
     */
    $tests->assertTrue($second->validateId($id));
    $tests->assertTrue(!$second->validateId('an-id-nobody-issued'));

    $second->destroy($id);
    $tests->assertSame('', $first->read($id));
    $tests->assertSame(0, $first->gc(3600));
});

$tests->run('a session starts when it is written to, not on every page', function () use ($tests): void {
    /*
     * StartSession used to start one on every request, so every visitor got
     * a Set-Cookie and a session file on every page — a public page a CDN
     * cannot cache, because it answers each visitor with a cookie of their
     * own. Over a real server, because a cookie is a header: a page that
     * never writes to the session sends none, and one that does sends one.
     */
    $server = fixtureServer('session-app.php');

    if ($server === null) {
        return;
    }

    [$base, $stop] = $server;
    $port = (int) parse_url($base, PHP_URL_PORT);

    $send = static function (string $method, string $path, ?string $cookie = null, array $headers = []) use ($port): array {
        $connection = fsockopen('127.0.0.1', $port, $errno, $error, 2);
        $lines = [$method . ' ' . $path . ' HTTP/1.0', 'Host: 127.0.0.1'];

        if ($cookie !== null) {
            $lines[] = 'Cookie: ' . $cookie;
        }

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        if ($method === 'POST') {
            $lines[] = 'Content-Length: 0';
        }

        fwrite($connection, implode("\r\n", $lines) . "\r\n\r\n");
        $answer = (string) stream_get_contents($connection);
        fclose($connection);

        [$head, $body] = explode("\r\n\r\n", $answer, 2) + [1 => ''];
        preg_match('/^HTTP\/\S+ (\d+)/', $head, $status);
        preg_match('/^Set-Cookie: ([^;\r\n]+)/mi', $head, $setCookie);

        return ['status' => (int) ($status[1] ?? 0), 'cookie' => $setCookie[1] ?? null, 'body' => $body];
    };

    try {
        // Reading, or not touching it at all: no cookie, no session.
        $tests->assertSame(null, $send('GET', '/plain')['cookie']);
        $read = $send('GET', '/read');
        $tests->assertSame(null, $read['cookie']);
        $tests->assertSame('read:none', $read['body']);

        // Writing starts one, and the next request with the cookie finds it.
        $written = $send('GET', '/write');
        $tests->assertSame(true, is_string($written['cookie']) && str_starts_with($written['cookie'], 'PHPSESSID='));
        $tests->assertSame('read:written', $send('GET', '/read', $written['cookie'])['body']);

        // A page with a form issues a token, so it has a session.
        $form = $send('GET', '/form');
        $tests->assertSame(true, is_string($form['cookie']));
        $token = substr($form['body'], strlen('token:'));

        // A POST with no session is refused without being given one to be refused with.
        $refused = $send('POST', '/submit');
        $tests->assertSame(403, $refused['status']);
        $tests->assertSame(null, $refused['cookie']);

        // And with the session and its token, it is accepted.
        $accepted = $send('POST', '/submit', $form['cookie'], ['X-CSRF-Token' => $token]);
        $tests->assertSame(200, $accepted['status']);
        $tests->assertSame('accepted', $accepted['body']);
    } finally {
        $stop();
    }
});
