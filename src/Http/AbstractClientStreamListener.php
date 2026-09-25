<?php

namespace SfphpProject\src\Http;

/**
 * Base class for stream listeners with sensible defaults.
 *
 * Extend this instead of implementing ClientStreamListener directly to get
 * default behavior that ignores status and continues receiving all chunks.
 * Override only the methods you need.
 */
abstract class AbstractClientStreamListener implements ClientStreamListener
{
    /**
     * The status code and headers are available.
     *
     * Default: always continue (return true). Override to implement custom logic.
     *
     * @param int $statusCode The HTTP status code
     * @param array<string, string> $headers The response headers
     * @return bool True to continue receiving, false to abort the transfer
     */
    public function onStatus(int $statusCode, array $headers): bool
    {
        return true;
    }

    /**
     * Receive a chunk of the response body.
     *
     * Must be implemented by subclasses.
     *
     * @param string $chunk The data received
     * @return bool True to continue receiving, false to abort the transfer
     */
    abstract public function onChunk(string $chunk): bool;

    /**
     * The transfer completed or was aborted.
     *
     * Default: do nothing. Override to implement cleanup or logging.
     *
     * @param int $statusCode The HTTP status code
     * @param array<string, string> $headers The response headers
     * @return void
     */
    public function onComplete(int $statusCode, array $headers): void
    {
        // Default: no-op
    }
}
