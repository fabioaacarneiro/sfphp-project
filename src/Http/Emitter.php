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

        foreach ($response->headers() as $name => $value) {
            header($name . ': ' . $value, true);
        }

        /*
         * A HEAD response carries the headers a GET would, including the
         * length, but no body.
         */
        if (strtoupper($method) === HEAD) {
            header('Content-Length: ' . strlen($response->body()), true);

            return;
        }

        echo $response->body();
    }
}
