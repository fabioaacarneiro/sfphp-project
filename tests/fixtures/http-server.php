<?php

/**
 * The server the HTTP client tests talk to.
 *
 * A real one, on a real socket, because the whole point of a client is what
 * happens on the wire: a mock would confirm that the code calls the functions
 * it calls, which is the one thing that was never in doubt.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header('X-Served-By: sfphp-test');

if ($path === '/status/404') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"message":"no such thing"}';

    return;
}

if ($path === '/status/500') {
    http_response_code(500);
    echo 'boom';

    return;
}

if ($path === '/slow') {
    sleep(5);
    echo 'late';

    return;
}

if ($path === '/not-json') {
    echo '<html>nope</html>';

    return;
}

// Bug #4: Test redirect (301 -> 200)
if ($path === '/redirect') {
    http_response_code(301);
    header('Location: /redirect-target');
    echo 'Redirecting...';

    return;
}

if ($path === '/redirect-target') {
    http_response_code(200);
    echo 'Redirected successfully';

    return;
}

// Bug #5: Test chunked streaming (for testing abort in onStatus)
if ($path === '/stream-chunked') {
    header('Content-Type: text/plain');
    header('Transfer-Encoding: chunked');

    for ($i = 1; $i <= 10; $i++) {
        echo "Chunk $i\n";
        flush();
        usleep(100000); // 100ms between chunks
    }

    return;
}

// Bug #6: Test timeout (slow response)
if ($path === '/slow-stream') {
    header('Content-Type: text/plain');

    // Send first chunk immediately
    echo "Starting...\n";
    flush();

    // Then delay before next chunk
    sleep(8);
    echo "Done (after 8s delay)\n";
    flush();

    return;
}

header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_GET,
    'headers' => array_filter(
        $_SERVER,
        static fn (string $key): bool => str_starts_with($key, 'HTTP_') || $key === 'CONTENT_TYPE',
        ARRAY_FILTER_USE_KEY
    ),
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
