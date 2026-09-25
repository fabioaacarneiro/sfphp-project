<?php

namespace SfphpProject\src\Http;

use RuntimeException;

/**
 * Writes a Response to the PHP SAPI.
 *
 * This is the only class in the framework that calls header(),
 * http_response_code() or echoes a response body. Everything upstream deals in
 * Response objects, which is what makes the dispatch path assertable in a test
 * without output buffering.
 *
 * It is also the seam for a persistent runtime: running SFPHP under Swoole or
 * FrankenPHP means writing a sibling emitter that hands the response to that
 * server's own API, and changing nothing else.
 *
 * For streaming responses, the emitter handles session cleanup, zlib buffering,
 * and proper flushing to ensure chunks reach the client immediately.
 */
final class Emitter
{
    /**
     * Send a response to the client.
     *
     * @param Response $response The response to send
     * @param string $method The request method, so HEAD omits the body
     * @return void
     * @throws RuntimeException If output has already started
     */
    public function emit(Response $response, string $method = GET): void
    {
        if (headers_sent($file, $line)) {
            /*
             * Failing loudly beats sending a broken response. Output before
             * this point means a stray echo or a byte outside a PHP tag, and
             * the status and headers below would be silently discarded.
             */
            throw new RuntimeException(
                "Cannot emit the response: output already started at $file:$line."
            );
        }

        http_response_code($response->status());

        if ($response->isStream()) {
            $this->emitStream($response, $method);
        } else {
            $this->emitNormal($response, $method);
        }
    }

    /**
     * Send a normal (non-streaming) response.
     *
     * @param Response $response The response to send
     * @param string $method The request method
     * @return void
     */
    private function emitNormal(Response $response, string $method): void
    {
        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value, true);
        }

        /*
         * A HEAD response carries the headers a GET would, including the
         * length, but no body.
         */
        $body = $response->body();
        $dumps = \SfphpProject\src\Debug\PendingDumps::take();

        /*
         * dump() output made while the action ran. An HTML page shows it; any
         * other body — JSON, a file — would be corrupted by it, so it is
         * logged instead, where it is still found.
         */
        if ($dumps !== []) {
            if (str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
                $body = \SfphpProject\src\Debug\PendingDumps::inject($body, $dumps);
            } else {
                logger()->debug('dump() output from a response that is not HTML', [
                    'dump' => strip_tags(implode("\n", $dumps)),
                ]);
            }
        }

        if (strtoupper($method) === HEAD) {
            header('Content-Length: ' . strlen($body), true);

            return;
        }

        echo $body;
    }

    /**
     * Send a streaming response.
     *
     * @param Response $response The response to send
     * @param string $method The request method
     * @return void
     */
    private function emitStream(Response $response, string $method): void
    {
        // Don't send Content-Length for streaming responses
        foreach ($response->headers() as $name => $value) {
            if (strtolower($name) !== 'content-length') {
                header($name . ': ' . $value, true);
            }
        }

        // Add headers to prevent proxy buffering
        header('Cache-Control: no-cache', true);
        header('X-Accel-Buffering: no', true);

        // For HEAD requests, send headers but don't execute producer
        if (strtoupper($method) === HEAD) {
            return;
        }

        // Close session before streaming to prevent locking
        $this->closeSession();

        // Allow producer to detect client abort, but continue running
        ignore_user_abort(true);

        // Disable output buffering and compression
        $this->disableOutputBuffering();

        // Execute the producer
        $stream = new ResponseStream($response->producer());
        $stream->execute();
    }

    /**
     * Close the current session to prevent file locking during streaming.
     *
     * @return void
     */
    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Disable output buffering and zlib compression.
     *
     * Streaming requires flushing output at the SAPI level without buffering.
     *
     * @return void
     */
    private function disableOutputBuffering(): void
    {
        // Clear all output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Disable implicit flushing and gzip compression
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', 1);
        }

        ini_set('zlib.output_compression', '0');
    }
}
