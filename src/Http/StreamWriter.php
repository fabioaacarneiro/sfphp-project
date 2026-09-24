<?php

namespace SfphpProject\src\Http;

/**
 * Writes response body data to the client in chunks.
 *
 * Producers call write() for each chunk, and can check aborted() to stop
 * if the client disconnected. The writer handles flushing at the SAPI level.
 */
interface StreamWriter
{
    /**
     * Write a chunk to the response body.
     *
     * @param string $chunk The data to write
     * @return bool True if written, false if the client aborted
     */
    public function write(string $chunk): bool;

    /**
     * Check if the client has disconnected.
     *
     * @return bool True if the client aborted or is no longer connected
     */
    public function aborted(): bool;

    /**
     * Flush the output buffer at all levels.
     *
     * @return void
     */
    public function flush(): void;
}
