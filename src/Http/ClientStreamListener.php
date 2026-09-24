<?php

namespace SfphpProject\src\Http;

/**
 * Receives chunks of a streaming HTTP response.
 *
 * Called once per chunk as data arrives from the remote server.
 * Return false to abort the transfer early (e.g., if the client disconnected).
 */
interface ClientStreamListener
{
    /**
     * The status code and headers are available.
     *
     * Called BEFORE the first onChunk(), allowing the listener to make decisions
     * (e.g., abort if status is 401) before receiving body data.
     *
     * @param int $statusCode The HTTP status code
     * @param array<string, string> $headers The response headers
     * @return void
     */
    public function onStatus(int $statusCode, array $headers): void;

    /**
     * Receive a chunk of the response body.
     *
     * Called after onStatus() has been called and data arrives.
     * The chunk may contain partial UTF-8 multibyte sequences that will be
     * completed in the next chunk.
     *
     * @param string $chunk The data received
     * @return bool True to continue receiving, false to abort the transfer
     */
    public function onChunk(string $chunk): bool;

    /**
     * The transfer completed or was aborted.
     *
     * Called after the last onChunk() or if an error occurs.
     *
     * @param int $statusCode The HTTP status code (same as onStatus)
     * @param array<string, string> $headers The response headers (same as onStatus)
     * @return void
     */
    public function onComplete(int $statusCode, array $headers): void;
}
