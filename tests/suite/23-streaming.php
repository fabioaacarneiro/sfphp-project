<?php

/*
 * Streaming: Response::stream(), Server-Sent Events, and the client reading a
 * response as it arrives.
 *
 * These lived in tests/StreamingTest.php and tests/StreamingBugsTest.php,
 * which nothing ran — and which checked with PHP's assert(), a no-op wherever
 * zend.assertions is -1, as production php.ini sets it. So they passed
 * whatever the code did. Here they use the runner's assertions, and the ones
 * that need a server start the fixture one.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Http\AbstractClientStreamListener;
use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\ClientStream;
use SfphpProject\src\Http\ClientStreamListener;
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\ServerSentEvent;
use SfphpProject\src\Http\StreamWriter;

/** A StreamWriter that keeps what it is given. */
final class StreamingTestWriter implements StreamWriter
{
    /** @var list<string> */
    public array $chunks = [];

    public function write(string $chunk): bool
    {
        $this->chunks[] = $chunk;

        return true;
    }

    public function aborted(): bool
    {
        return false;
    }

    public function flush(): void
    {
    }
}

/**
 * A listener that keeps the chunks, and stops after $limit of them when given one.
 */
$collector = static function (?int $limit = null): ClientStreamListener {
    return new class ($limit) implements ClientStreamListener {
        /** @var list<string> */
        public array $chunks = [];

        public function __construct(private ?int $limit)
        {
        }

        public function onStatus(int $statusCode, array $headers): bool
        {
            return true;
        }

        public function onChunk(string $chunk): bool
        {
            $this->chunks[] = $chunk;

            return $this->limit === null || count($this->chunks) < $this->limit;
        }

        public function onComplete(int $statusCode, array $headers): void
        {
        }
    };
};

$tests->run('a streamed response is a stream, keeps what middleware adds, and has no body to read', function () use ($tests): void {
    $stream = Response::stream(static function (StreamWriter $out): void {
        $out->write('one');
    }, 200, ['Content-Type' => 'text/plain']);

    $tests->assertSame(true, $stream->isStream());
    $tests->assertSame(false, Response::html('page')->isStream());

    // What a middleware does to the response survives, and it is still a stream.
    $changed = $stream->withStatus(201)->withHeader('X-Custom', 'value');
    $tests->assertSame(201, $changed->status());
    $tests->assertSame('value', $changed->header('X-Custom'));
    $tests->assertSame(true, $changed->isStream());

    // A body that has not been produced cannot be read.
    try {
        $stream->body();
        $tests->assertSame('an exception', 'none');
    } catch (LogicException $e) {
        $tests->assertSame(true, str_contains($e->getMessage(), 'stream'));
    }
});

$tests->run('a server-sent event carries its name, id and data', function () use ($tests): void {
    $writer = new StreamingTestWriter();

    (new ServerSentEvent($writer))->send('test data', event: 'message', id: '1');

    $sent = implode('', $writer->chunks);
    $tests->assertSame(true, str_contains($sent, 'event: message'));
    $tests->assertSame(true, str_contains($sent, 'id: 1'));
    $tests->assertSame(true, str_contains($sent, 'data: test data'));
});

$tests->run('the client never hands a listener half of a UTF-8 character', function () use ($tests, $collector): void {
    // ☃ is three bytes, E2 98 83: the network can split it between two chunks.
    $listener = $collector();
    $stream = new ClientStream($listener);

    $stream->receive('Test: ' . chr(0xE2) . chr(0x98));
    $tests->assertSame(['Test: '], $listener->chunks);

    $stream->receive(chr(0x83) . "\n");
    $tests->assertSame(['Test: ', "☃\n"], $listener->chunks);

    // Across a longer run, the text comes out whole.
    $listener = $collector();
    $stream = new ClientStream($listener);
    $stream->receive('Hello ☃ is ' . chr(0xE2) . chr(0x98));
    $stream->receive(chr(0x83) . " nice!\n");
    $tests->assertSame("Hello ☃ is ☃ nice!\n", implode('', $listener->chunks));
});

$tests->run('a listener that answers false stops the stream', function () use ($tests, $collector): void {
    /*
     * The listener has the chunk it answered false to, and curl is told to
     * stop by the 0 returned for that same chunk — so nothing after it is
     * delivered. The old test expected the second chunk to be "accepted",
     * which was never what the code did; its assert() was switched off.
     */
    $listener = $collector(2);
    $stream = new ClientStream($listener);

    $tests->assertSame(6, $stream->receive('chunk1'));
    $tests->assertSame(0, $stream->receive('chunk2'));
    $tests->assertSame(['chunk1', 'chunk2'], $listener->chunks);
});

$tests->run('a stream over the wire: one final status after redirects, a quiet abort, and a timeout that holds', function () use ($tests): void {
    if (!extension_loaded('curl')) {
        return;
    }

    $server = fixtureServer();

    if ($server === null) {
        return;
    }

    [$base, $stop] = $server;

    try {
        // A redirect is followed inside the client: onStatus hears the final 200, once.
        $statuses = [];
        $listener = new class ($statuses) extends AbstractClientStreamListener {
            public function __construct(private array &$statuses)
            {
            }

            public function onStatus(int $code, array $headers): bool
            {
                $this->statuses[] = $code;

                return true;
            }

            public function onChunk(string $chunk): bool
            {
                return true;
            }
        };

        Http::client()->stream($base . '/redirect', $listener);
        $tests->assertSame([200], $statuses);

        // Declining in onStatus ends the transfer, and is not an error.
        $declining = new class extends AbstractClientStreamListener {
            public int $chunks = 0;

            public function onStatus(int $code, array $headers): bool
            {
                return false;
            }

            public function onChunk(string $chunk): bool
            {
                $this->chunks++;

                return true;
            }
        };

        Http::client()->stream($base . '/stream-chunked', $declining);
        $tests->assertSame(0, $declining->chunks);

        /*
         * An explicit timeout holds for a stream: the server waits eight
         * seconds before its second line, and a one-second timeout ends it in
         * about one. Last on purpose — the fixture server serves one request
         * at a time, and this one keeps it busy until it is stopped.
         */
        $startedAt = microtime(true);

        try {
            Http::timeout(1)->stream($base . '/slow-stream', new class extends AbstractClientStreamListener {
                public function onChunk(string $chunk): bool
                {
                    return true;
                }
            });
            $tests->assertSame('a timeout', 'none');
        } catch (ClientException $e) {
            $elapsed = microtime(true) - $startedAt;
            $tests->assertSame(true, $elapsed >= 0.9 && $elapsed < 4);
        }
    } finally {
        $stop();
    }
});
