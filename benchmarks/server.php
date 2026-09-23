<?php

/**
 * The origin server the async benchmarks measure against.
 *
 * Run with several workers, or the server itself serialises the requests and
 * every measurement below becomes a measurement of this file:
 *
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8300 benchmarks/server.php
 *
 * It sleeps on purpose: a benchmark of concurrent I/O needs I/O that takes
 * long enough to overlap, and a real socket carrying a real delay is the
 * honest way to get one. The sleep happens in another process, which is the
 * difference between measuring concurrency and faking it.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);

if ($path === '/delay') {
    $ms = max(0, min(5000, (int) ($query['ms'] ?? 100)));
    usleep($ms * 1000);

    header('Content-Type: application/json');
    echo json_encode(['slept_ms' => $ms, 'pid' => getmypid()]);

    return;
}

if ($path === '/status') {
    http_response_code((int) ($query['code'] ?? 200));
    echo 'status';

    return;
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
