<?php

/**
 * The origin the async benchmarks measure against.
 *
 * A single process that never blocks: a connection is accepted, parked with a
 * deadline, and answered when its moment arrives. That is deliberate. PHP's
 * built-in server answers from a fixed pool of worker processes, so measuring
 * concurrency against it measures the size of that pool — with its workers,
 * three requests that each sleep 300 ms took 600 ms, and the number said
 * nothing about the client being tested.
 *
 * Here a hundred connections cost a hundred entries in an array, so what the
 * benchmark measures is the client.
 *
 *   php benchmarks/origin.php 127.0.0.1:8300
 *
 *   GET /delay?ms=300     answers after 300 ms
 *   GET /status?code=500  answers with that status
 *   GET /                 answers at once
 */

$address = $argv[1] ?? '127.0.0.1:8300';
$server = stream_socket_server('tcp://' . $address, $errno, $error);

if ($server === false) {
    fwrite(STDERR, "Could not listen on {$address}: {$error}" . PHP_EOL);

    exit(1);
}

stream_set_blocking($server, false);

$reading = [];
$pending = [];
$nextId = 1;

echo "origin listening on {$address}" . PHP_EOL;

while (true) {
    /*
     * One select over the listening socket and every half-read request. The
     * first version read each request in a loop right after accepting it,
     * which blocked the whole server for as long as that one client took to
     * send its headers — and turned three concurrent requests into 1.3
     * seconds. An instrument that stalls measures itself.
     */
    $timeout = 0.02;

    foreach ($pending as $entry) {
        $timeout = min($timeout, max(0.0, $entry['at'] - microtime(true)));
    }

    $read = ['server' => $server];

    foreach ($reading as $id => $client) {
        $read['c' . $id] = $client['stream'];
    }

    $write = [];
    $except = null;
    @stream_select($read, $write, $except, 0, (int) ($timeout * 1_000_000));

    foreach ($read as $key => $stream) {
        if ($key === 'server') {
            while (($client = @stream_socket_accept($server, 0)) !== false) {
                stream_set_blocking($client, false);
                $reading[$nextId++] = ['stream' => $client, 'buffer' => ''];
            }

            continue;
        }

        $id = (int) substr((string) $key, 1);

        if (!isset($reading[$id])) {
            continue;
        }

        $chunk = fread($reading[$id]['stream'], 8192);

        if ($chunk === false || $chunk === '') {
            fclose($reading[$id]['stream']);
            unset($reading[$id]);

            continue;
        }

        $reading[$id]['buffer'] .= $chunk;

        if (!str_contains($reading[$id]['buffer'], "\r\n\r\n")) {
            continue;
        }

        $request = $reading[$id]['buffer'];
        $client = $reading[$id]['stream'];
        unset($reading[$id]);

        $target = '/';

        if (preg_match('#^[A-Z]+ (\S+)#', $request, $matches) === 1) {
            $target = $matches[1];
        }

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $ms = 0;
        $status = 200;
        $body = (string) json_encode(['ok' => true]);

        if ($path === '/delay') {
            $ms = max(0, min(10000, (int) ($query['ms'] ?? 100)));
            $body = (string) json_encode(['slept_ms' => $ms]);
        }

        if ($path === '/status') {
            $status = (int) ($query['code'] ?? 200);
            $body = (string) json_encode(['status' => $status]);
        }

        $pending[$nextId++] = [
            'stream' => $client,
            'at' => microtime(true) + $ms / 1000,
            'body' => $body,
            'status' => $status,
        ];
    }

    $now = microtime(true);

    foreach ($pending as $id => $entry) {
        if ($entry['at'] > $now) {
            continue;
        }

        unset($pending[$id]);

        $response = sprintf(
            "HTTP/1.1 %d OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $entry['status'],
            strlen($entry['body']),
            $entry['body']
        );

        @fwrite($entry['stream'], $response);
        @fclose($entry['stream']);
    }
}
