<?php

namespace SfphpProject\src\Async\Adapters;

use CurlHandle;
use SfphpProject\src\Async\Cancellable;
use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\EventLoop;
use SfphpProject\src\Async\Pending;
use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\ClientResponse;
use Throwable;

/**
 * An HTTP request that is already on its way.
 *
 * The transfer is handed to the event loop's multi handle as soon as this is
 * created, so three of these are three requests in flight — not three requests
 * queued behind whichever is awaited first. libcurl drives them all from the
 * same wait, and each one settles as its own response arrives.
 *
 *     $a = Http::getAsync($first);
 *     $b = Http::getAsync($second);
 *     $c = Http::getAsync($third);
 *
 *     [$x, $y, $z] = [await($a), await($b), await($c)];
 *
 * That takes about as long as the slowest of the three. The previous version
 * called `curl_exec()` from inside `getValue()`: nothing happened until the
 * await, and then it blocked until the response came back, so the same code
 * took as long as all three added together. Measured against a local server
 * delaying 300 ms per request, it was 905 ms.
 *
 * Resolves to a {@see ClientResponse}, the same object the synchronous client
 * returns, so `status()`, `json()`, `ok()` and `throw()` mean the same thing
 * whichever way the request was made.
 */
final class HttpFuture extends Pending implements Cancellable
{
    /** Seconds allowed for the whole exchange, when the caller sets none. */
    private const TIMEOUT = 30;

    /** Seconds allowed to establish the connection. */
    private const CONNECT_TIMEOUT = 10;

    /** How many redirects to follow. */
    private const MAX_REDIRECTS = 5;

    private EventLoop $loop;

    private ?CurlHandle $handle;

    private string $url;

    /** @var array<string, string> */
    private array $responseHeaders = [];

    /**
     * Start a request.
     *
     * @param string $method The HTTP method
     * @param string $url The URL
     * @param array<string, string> $headers Request headers
     * @param mixed $body The body, encoded as JSON unless it is already a string
     * @param array<int, mixed> $options Extra cURL options, which win over the defaults
     * @param EventLoop|null $loop The loop to run on, or null for the current one
     */
    public function __construct(
        string $method,
        string $url,
        array $headers = [],
        mixed $body = null,
        array $options = [],
        ?EventLoop $loop = null
    ) {
        $this->loop = $loop ?? Context::scheduler()->loop();
        $this->url = $url;

        if (!extension_loaded('curl')) {
            /*
             * Refused rather than degraded, for the same reason the synchronous
             * client refuses: a fallback that cannot set a connection timeout
             * and cannot be watched by the loop would be a blocking call
             * wearing an async name.
             */
            $this->handle = null;
            $this->rejectWith(new ClientException('The curl extension is required to make HTTP requests. Install ext-curl.'));

            return;
        }

        $this->handle = $this->build(strtoupper($method), $url, $headers, $body, $options);
        $this->state = self::RUNNING;

        $this->loop->addTransfer($this->handle, function (CurlHandle $handle, int $errno, string $error): void {
            $this->complete($handle, $errno, $error);
        });
    }

    /**
     * Give up on the request, releasing the connection.
     *
     * The transfer is removed from the multi handle, so the socket is closed
     * rather than left to finish into a result nobody is going to read.
     *
     * @param Throwable|null $reason Why
     * @return void
     */
    public function cancel(?Throwable $reason = null): void
    {
        if ($this->handle !== null) {
            $this->loop->cancelTransfer($this->handle);
            $this->handle = null;
        }

        $this->cancelWith($reason);
    }

    /**
     * Configure the handle for this request.
     *
     * @param string $method The HTTP method
     * @param string $url The URL
     * @param array<string, string> $headers Request headers
     * @param mixed $body The body
     * @param array<int, mixed> $options Extra cURL options
     * @return CurlHandle The handle
     */
    private function build(string $method, string $url, array $headers, mixed $body, array $options): CurlHandle
    {
        $handle = curl_init();

        $defaults = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            /*
             * A redirect from https:// to http:// is a downgrade a server can
             * ask for and a client should refuse: everything after it travels
             * in the clear, including any Authorization header.
             */
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $this->responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($body !== null) {
            $payload = is_string($body)
                ? $body
                : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $defaults[CURLOPT_POSTFIELDS] = $payload;

            if (!is_string($body)) {
                $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            }
        }

        if ($headers !== []) {
            $lines = [];

            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }

            $defaults[CURLOPT_HTTPHEADER] = $lines;
        }

        // The caller's options win: an explicit timeout is a decision.
        curl_setopt_array($handle, $options + $defaults);

        return $handle;
    }

    /**
     * Turn a finished transfer into a response, or into a failure.
     *
     * @param CurlHandle $handle The finished handle
     * @param int $errno The cURL error number, or CURLE_OK
     * @param string $error The cURL error message
     * @return void
     */
    private function complete(CurlHandle $handle, int $errno, string $error): void
    {
        $this->handle = null;

        if ($errno !== CURLE_OK) {
            /*
             * No response at all — a name that did not resolve, a refused
             * connection, a timeout, a certificate that did not verify. There
             * is nothing to return, so this rejects rather than resolving with
             * an empty answer.
             */
            $this->rejectWith(new ClientException(
                sprintf('Request to %s failed: %s', $this->url, $error !== '' ? $error : 'unknown error'),
                $errno
            ));

            return;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = (string) curl_multi_getcontent($handle);

        $this->resolveWith(new ClientResponse($status, $body, $this->responseHeaders, $this->url));
    }

    /**
     * Start a GET request.
     *
     * @param string $url The URL
     * @param array<string, string> $headers Request headers
     * @param array<int, mixed> $options Extra cURL options
     * @return self The future
     */
    public static function get(string $url, array $headers = [], array $options = []): self
    {
        return new self('GET', $url, $headers, null, $options);
    }

    /**
     * Start a POST request.
     *
     * @param string $url The URL
     * @param mixed $body The body
     * @param array<string, string> $headers Request headers
     * @param array<int, mixed> $options Extra cURL options
     * @return self The future
     */
    public static function post(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('POST', $url, $headers, $body, $options);
    }

    /**
     * Start a PUT request.
     *
     * @param string $url The URL
     * @param mixed $body The body
     * @param array<string, string> $headers Request headers
     * @param array<int, mixed> $options Extra cURL options
     * @return self The future
     */
    public static function put(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('PUT', $url, $headers, $body, $options);
    }

    /**
     * Start a PATCH request.
     *
     * @param string $url The URL
     * @param mixed $body The body
     * @param array<string, string> $headers Request headers
     * @param array<int, mixed> $options Extra cURL options
     * @return self The future
     */
    public static function patch(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('PATCH', $url, $headers, $body, $options);
    }

    /**
     * Start a DELETE request.
     *
     * @param string $url The URL
     * @param array<string, string> $headers Request headers
     * @param array<int, mixed> $options Extra cURL options
     * @return self The future
     */
    public static function delete(string $url, array $headers = [], array $options = []): self
    {
        return new self('DELETE', $url, $headers, null, $options);
    }
}
