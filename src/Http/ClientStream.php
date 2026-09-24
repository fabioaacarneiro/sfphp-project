<?php

namespace SfphpProject\src\Http;

/**
 * Manages streaming reception of an HTTP response body.
 *
 * Handles UTF-8 multibyte sequences that may be split across chunks,
 * and coordinates with the listener callback.
 *
 * @internal
 */
final class ClientStream
{
    private string $incompleteUtf8 = '';

    public function __construct(private readonly ClientStreamListener $listener) {}

    /**
     * Receive a chunk from curl, handling UTF-8 boundaries.
     *
     * @param string $chunk The raw data from curl
     * @return int Bytes processed (always equals strlen($chunk))
     */
    public function receive(string $chunk): int {
        // Combine with any incomplete UTF-8 from the previous chunk
        $data = $this->incompleteUtf8 . $chunk;
        $this->incompleteUtf8 = '';

        // Find the last complete UTF-8 character
        $len = strlen($data);
        $pos = $len;

        // Walk backwards to find where complete UTF-8 ends
        // A continuation byte starts with 10xxxxxx (0x80-0xBF)
        // We want to find the last position before any incomplete sequence
        while ($pos > 0) {
            $byte = ord($data[$pos - 1]);

            // Single byte ASCII (0xxxxxxx)
            if (($byte & 0x80) === 0) {
                break;
            }

            // Start of multibyte sequence (11xxxxxx)
            if (($byte & 0xC0) === 0xC0) {
                // Determine expected length
                if (($byte & 0xE0) === 0xC0) {
                    $expected = 2; // 110xxxxx
                } elseif (($byte & 0xF0) === 0xE0) {
                    $expected = 3; // 1110xxxx
                } elseif (($byte & 0xF8) === 0xF0) {
                    $expected = 4; // 11110xxx
                } else {
                    break; // Invalid, treat as complete
                }

                // Check if we have all bytes
                if ($pos + $expected - 1 <= $len) {
                    $pos += $expected;
                } else {
                    // Incomplete sequence, save for next chunk
                    $this->incompleteUtf8 = substr($data, $pos - 1);
                    $data = substr($data, 0, $pos - 1);
                }
                break;
            }

            // Continuation byte (10xxxxxx), keep looking backwards
            $pos--;
        }

        // Send complete data to listener
        if ($data !== '') {
            if (!$this->listener->onChunk($data)) {
                return 0; // Signal abort to curl
            }
        }

        return strlen($chunk);
    }

    /**
     * Finalize streaming, reporting any incomplete UTF-8.
     *
     * This should be called after curl completes, to handle any
     * incomplete UTF-8 that was being held.
     *
     * @return string Any unsent UTF-8 remnant (should be empty for valid streams)
     */
    public function finalize(): string
    {
        $remainder = $this->incompleteUtf8;
        $this->incompleteUtf8 = '';

        return $remainder;
    }
}
