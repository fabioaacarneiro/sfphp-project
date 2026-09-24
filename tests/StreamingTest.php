<?php

namespace Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;
use SfphpProject\src\Http\ServerSentEvent;
use SfphpProject\src\Http\ClientStream;
use SfphpProject\src\Http\ClientStreamListener;

/**
 * Tests for streaming functionality
 */
class StreamingTest
{
    /**
     * Test that Response::stream() returns a streaming response
     */
    public function testResponseStream(): bool
    {
        $chunks = [];
        $response = Response::stream(function(StreamWriter $out) use (&$chunks) {
            $out->write('chunk1');
            $out->write('chunk2');
            $out->write('chunk3');
        });

        // Response should be marked as streaming
        assert($response->isStream() === true, 'Response should be streaming');

        // Non-streaming response should not be streaming
        $normal = Response::html('test');
        assert($normal->isStream() === false, 'Normal response should not be streaming');

        return true;
    }

    /**
     * Test that body() throws on streaming response
     */
    public function testStreamBodyThrows(): bool
    {
        $response = Response::stream(fn() => null);

        try {
            $response->body();
            assert(false, 'Should throw LogicException');
        } catch (\LogicException $e) {
            assert(str_contains($e->getMessage(), 'streaming'), 'Error message should mention streaming');
            return true;
        }
    }

    /**
     * Test that streaming response preserves middleware modifications
     */
    public function testStreamingMiddleware(): bool
    {
        $response = Response::stream(fn() => null, 200, ['Content-Type' => 'text/plain']);

        $modified = $response
            ->withStatus(201)
            ->withHeader('X-Custom', 'value');

        assert($modified->status() === 201, 'Status should be 201');
        assert($modified->header('X-Custom') === 'value', 'Header should be set');
        assert($modified->isStream() === true, 'Should still be streaming');

        return true;
    }

    /**
     * Test ServerSentEvent formatting
     */
    public function testServerSentEventFormatting(): bool
    {
        $chunks = [];

        $listener = new class($chunks) implements ClientStreamListener {
            public function __construct(private array &$chunks) {}
            public function onStatus(int $statusCode, array $headers): void {}
            public function onChunk(string $chunk): bool {
                $this->chunks[] = $chunk;
                return true;
            }
            public function onComplete(int $statusCode, array $headers): void {}
        };

        $writer = new TestStreamWriter($chunks);
        $sse = new ServerSentEvent($writer);

        // Test event formatting
        $sse->send('test data', event: 'message', id: '1');

        $formatted = implode('', $chunks);
        assert(str_contains($formatted, 'event: message'), 'Event name should be included');
        assert(str_contains($formatted, 'id: 1'), 'ID should be included');
        assert(str_contains($formatted, 'data: test data'), 'Data should be included');

        return true;
    }

    /**
     * Test UTF-8 multibyte handling in ClientStream
     */
    public function testUtf8Boundaries(): bool
    {
        $chunks = [];
        $listener = new class($chunks) implements ClientStreamListener {
            public function __construct(private array &$chunks) {}
            public function onStatus(int $statusCode, array $headers): void {}
            public function onChunk(string $chunk): bool {
                $this->chunks[] = $chunk;
                return true;
            }
            public function onComplete(int $statusCode, array $headers): void {}
        };

        $stream = new ClientStream($listener);

        // Snowman: ☃ = 0xE2 0x98 0x83 (3 bytes)
        // Send incomplete sequence at end of first chunk
        $stream->receive("Hello ☃ is " . chr(0xE2) . chr(0x98)); // Last 2 bytes split
        $stream->receive(chr(0x83) . " nice!\n"); // Complete the sequence

        $result = implode('', $chunks);
        assert(str_contains($result, 'Hello ☃ is ☃ nice!'), 'UTF-8 should be correctly assembled');

        return true;
    }

    /**
     * Test UTF-8 partial sequence at chunk end
     */
    public function testUtf8PartialAtEnd(): bool
    {
        $chunks = [];
        $listener = new class($chunks) implements ClientStreamListener {
            public function __construct(private array &$chunks) {}
            public function onStatus(int $statusCode, array $headers): void {}
            public function onChunk(string $chunk): bool {
                $this->chunks[] = $chunk;
                return true;
            }
            public function onComplete(int $statusCode, array $headers): void {}
        };

        $stream = new ClientStream($listener);

        // Send text with incomplete UTF-8 at the end
        $stream->receive("Test: " . chr(0xE2) . chr(0x98)); // Incomplete snowman

        // First onChunk should only have "Test: " (complete)
        assert(count($chunks) === 1, 'Should have received 1 chunk');
        assert($chunks[0] === 'Test: ', 'Should contain only complete characters');

        // Send the rest
        $stream->receive(chr(0x83) . "\n"); // Complete the snowman

        // Now should have the complete snowman
        assert(count($chunks) === 2, 'Should have received 2 chunks');
        assert($chunks[1] === "☃\n", 'Should contain complete snowman');

        return true;
    }

    /**
     * Test ClientStream abort mechanism
     */
    public function testClientStreamAbort(): bool
    {
        $chunks = [];
        $listener = new class($chunks) implements ClientStreamListener {
            public function __construct(private array &$chunks) {}
            public function onStatus(int $statusCode, array $headers): void {}
            public function onChunk(string $chunk): bool {
                $this->chunks[] = $chunk;
                // Abort after 2 chunks
                return count($this->chunks) < 2;
            }
            public function onComplete(int $statusCode, array $headers): void {}
        };

        $stream = new ClientStream($listener);

        // First chunk should be accepted
        $bytes1 = $stream->receive("chunk1");
        assert($bytes1 > 0, 'First chunk should be accepted');

        // Second chunk should be accepted
        $bytes2 = $stream->receive("chunk2");
        assert($bytes2 > 0, 'Second chunk should be accepted');

        // Third chunk should be rejected (listener returned false)
        $bytes3 = $stream->receive("chunk3");
        assert($bytes3 === 0, 'Third chunk should be rejected (abort)');

        assert(count($chunks) === 2, 'Should have received exactly 2 chunks');

        return true;
    }

    /**
     * Run all tests
     */
    public function run(): void
    {
        $tests = [
            'testResponseStream',
            'testStreamBodyThrows',
            'testStreamingMiddleware',
            'testServerSentEventFormatting',
            'testUtf8Boundaries',
            'testUtf8PartialAtEnd',
            'testClientStreamAbort',
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $test) {
            try {
                if ($this->$test()) {
                    echo "✓ $test\n";
                    $passed++;
                }
            } catch (\Throwable $e) {
                echo "✗ $test: " . $e->getMessage() . "\n";
                $failed++;
            }
        }

        echo "\n" . ($passed + $failed) . " tests, $passed passed, $failed failed\n";

        if ($failed > 0) {
            exit(1);
        }
    }
}

/**
 * Test StreamWriter implementation for testing
 * @internal
 */
class TestStreamWriter implements StreamWriter
{
    private bool $aborted = false;

    public function __construct(private array &$chunks) {}

    public function write(string $chunk): bool
    {
        if ($this->aborted) {
            return false;
        }

        $this->chunks[] = $chunk;
        return true;
    }

    public function aborted(): bool
    {
        return $this->aborted;
    }

    public function abort(): void
    {
        $this->aborted = true;
    }

    public function flush(): void
    {
        // No-op in tests
    }
}

// Run tests
if (php_sapi_name() === 'cli') {
    (new StreamingTest())->run();
}
