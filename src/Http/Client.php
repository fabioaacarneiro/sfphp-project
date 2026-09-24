<?php

namespace SfphpProject\src\Http;

use JsonException;

/**
 * A configured HTTP client.
 *
 * Reached through Http, which is the front door: Http::get() for a one-off and
 * Http::base() or Http::withToken() for a client you keep. Every `with` method
 * here returns a new client, so one configured for a service can be handed
 * around without anything being able to change it.
 *
 * The framework had Request and Response for what arrives and nothing at all
 * for what it sends, so calling another service meant curl_setopt_array with a
 * dozen constants, remembering to decode the body, and remembering — or, far
 * more often, forgetting — to set a timeout.
 *
 *     $billing = Http::base('https://billing.internal')->token($jwt)->timeout(5);
 *
 *     $billing->get('/invoices/7')->throw()->json();
 *
 * **What this deliberately does not do: retry.** How many times to try, how long
 * to wait between tries and which failures deserve another attempt are
 * decisions about the service being called, not about HTTP — a payment that
 * charges a card is not something to repeat because a response was slow. That
 * belongs to the integration, next to the knowledge that makes it answerable.
 *
 * > **A URL that comes from a visitor is a request an attacker chose.** Pointed
 * > at `169.254.169.254` or at `localhost`, this fetches whatever your server
 * > can reach and hands it back — the shape of attack called SSRF. Nothing here
 * > can tell a URL you built from a URL somebody typed, so validate the ones you
 * > did not build.
 */
final class Client
{
    /** Seconds to wait for the connection to be established. */
    private const CONNECT_TIMEOUT = 5;

    /** Seconds to wait for the whole exchange. */
    private const TIMEOUT = 15;

    /** How many redirects to follow before giving up. */
    private const MAX_REDIRECTS = 5;

    /** @var array<string, string> */
    private array $headers = [];

    private string $base = '';

    private int $timeout = self::TIMEOUT;

    private int $connectTimeout = self::CONNECT_TIMEOUT;

    private bool $asForm = false;

    private bool $verify = true;

    /**
     * Send a GET request.
     *
     * @param string $url The URL, absolute or relative to the base
     * @param array<string, mixed> $query Values appended as a query string
     * @return ClientResponse The response
     */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', $url, null, $query);
    }

    /**
     * Send a POST request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body, encoded as JSON unless asForm()
     * @return ClientResponse The response
     */
    public function post(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('POST', $url, $body);
    }

    /**
     * Send a PUT request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public function put(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('PUT', $url, $body);
    }

    /**
     * Send a PATCH request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public function patch(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('PATCH', $url, $body);
    }

    /**
     * Send a DELETE request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public function delete(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('DELETE', $url, $body);
    }

    /**
     * Hang this client's relative URLs off a base.
     *
     * @param string $url The base URL
     * @return self A new client
     */
    public function base(string $url): self
    {
        $client = clone $this;
        $client->base = rtrim($url, '/');

        return $client;
    }

    /**
     * Add headers to this client.
     *
     * @param array<string, string> $headers The headers
     * @return self A new client, with these added
     */
    public function headers(array $headers): self
    {
        $client = clone $this;

        foreach ($headers as $name => $value) {
            $client->headers[$name] = $value;
        }

        return $client;
    }

    /**
     * Send a bearer token with every request.
     *
     * @param string $token The token
     * @return self A new client
     */
    public function token(string $token): self
    {
        return $this->headers(['Authorization' => 'Bearer ' . $token]);
    }

    /**
     * Send HTTP basic credentials with every request.
     *
     * @param string $username The username
     * @param string $password The password
     * @return self A new client
     */
    public function basic(string $username, string $password): self
    {
        return $this->headers([
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
        ]);
    }

    /**
     * How long to wait.
     *
     * @param int $seconds Seconds for the whole exchange
     * @param int|null $connect Seconds for the connection, or null to keep the default
     * @return self A new client
     */
    public function timeout(int $seconds, ?int $connect = null): self
    {
        $client = clone $this;
        $client->timeout = max(1, $seconds);
        $client->connectTimeout = max(1, $connect ?? min($client->connectTimeout, $client->timeout));

        return $client;
    }

    /**
     * Send the body as a form rather than as JSON.
     *
     * @return self A new client
     */
    public function asForm(): self
    {
        $client = clone $this;
        $client->asForm = true;

        return $client;
    }

    /**
     * Stop verifying the server's certificate.
     *
     * Named to be uncomfortable to type, because it is the line most often
     * copied out of a forum answer to make a development certificate work and
     * then left in production, where it removes the one guarantee TLS offers:
     * that the server answering is the one you asked for.
     *
     * @return self A new client
     */
    public function insecure(): self
    {
        $client = clone $this;
        $client->verify = false;

        return $client;
    }

    /**
     * Send a request with this client.
     *
     * @param string $method The HTTP method
     * @param string $url The URL, absolute or relative to the base
     * @param array<string, mixed>|string|null $body The body
     * @param array<string, mixed> $query Values appended as a query string
     * @return ClientResponse The response
     * @throws ClientException When the request could not be completed at all
     */
    public function send(string $method, string $url, array|string|null $body = null, array $query = []): ClientResponse
    {
        if (!extension_loaded('curl')) {
            /*
             * Refused rather than degraded. A fallback through file_get_contents
             * cannot set a connection timeout, cannot report which stage failed,
             * and silently does nothing when allow_url_fopen is off — three ways
             * to look like a network problem while being a missing extension.
             */
            throw new ClientException('The curl extension is required to make HTTP requests. Install ext-curl.');
        }

        $url = $this->resolve($url);

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        [$payload, $headers] = $this->payload($body);

        $handle = curl_init();
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            /*
             * A redirect from https:// to http:// is a downgrade a server can
             * ask for and a client should refuse: everything after it travels
             * in the clear, including the Authorization header this may be
             * carrying.
             */
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->verify,
            CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);

        curl_close($handle);

        if ($body === false) {
            /*
             * No response at all — DNS, refused connection, timeout, a
             * certificate that did not verify. That is not a status code to
             * inspect, so it throws: there is nothing to return.
             */
            throw new ClientException(
                sprintf('Request to %s failed: %s', $url, $error !== '' ? $error : 'unknown error'),
                $errno
            );
        }

        return new ClientResponse($status, (string) $body, $responseHeaders, $url);
    }

    /**
     * Stream a GET response in chunks.
     *
     * The listener receives chunks as they arrive, before the entire response
     * is buffered. Status and headers are sent to onComplete() after the body,
     * and onChunk() is called for each piece of data.
     *
     * This is useful for large responses, real-time data, or proxying
     * streams to the client.
     *
     *     Http::base('https://api.example.com')
     *         ->stream('/export', new class implements ClientStreamListener {
     *             public function onChunk(string $chunk): bool {
     *                 echo $chunk;
     *                 return true; // continue receiving
     *             }
     *             public function onComplete(int $statusCode, array $headers): void {
     *                 // handle completion
     *             }
     *         });
     *
     * @param string $url The URL, absolute or relative to the base
     * @param ClientStreamListener $listener Receives chunks and completion
     * @throws ClientException When the request fails or curl is unavailable
     */
    public function stream(string $url, ClientStreamListener $listener): void
    {
        if (!extension_loaded('curl')) {
            throw new ClientException('The curl extension is required to stream HTTP responses. Install ext-curl.');
        }

        $url = $this->resolve($url);

        $handle = curl_init();
        $responseHeaders = [];
        $statusCode = 0;
        $receivedFirstChunk = false;

        $manager = new ClientStream($listener);

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => false, // Don't buffer the body
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->verify,
            CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
            CURLOPT_HTTPHEADER => $this->headerLines([]),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$statusCode): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    // Extract status from "HTTP/1.1 200 OK"
                    $statusCode = (int) explode(' ', $line)[1] ?? 0;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (mixed $handle, string $chunk) use ($manager, &$receivedFirstChunk): int {
                $receivedFirstChunk = true;
                return $manager->receive($chunk);
            },
        ]);

        $result = curl_exec($handle);
        $error = curl_error($handle);
        $errno = curl_errno($handle);

        curl_close($handle);

        if ($result === false && !$receivedFirstChunk) {
            throw new ClientException(
                sprintf('Stream request failed: %s', $error !== '' ? $error : 'unknown error'),
                $errno
            );
        }

        // Handle any incomplete UTF-8
        $remainder = $manager->finalize();
        if ($remainder !== '') {
            // A well-formed stream should not end mid-character
            $listener->onChunk($remainder);
        }

        // Notify completion
        $listener->onComplete($statusCode ?: 0, $responseHeaders);
    }

    /**
     * Turn a relative URL into an absolute one.
     *
     * @param string $url The URL
     * @return string The absolute URL
     */
    private function resolve(string $url): string
    {
        if ($this->base === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $this->base . '/' . ltrim($url, '/');
    }

    /**
     * Encode the body, and say what it is.
     *
     * @param array<string, mixed>|string|null $body The body
     * @return array{0: string|null, 1: array<string, string>} The payload and the headers it needs
     * @throws ClientException When the body cannot be encoded
     */
    private function payload(array|string|null $body): array
    {
        if ($body === null) {
            return [null, []];
        }

        if (is_string($body)) {
            // Already encoded by the caller, who therefore owns the content type.
            return [$body, []];
        }

        if ($this->asForm) {
            return [http_build_query($body), ['Content-Type' => 'application/x-www-form-urlencoded']];
        }

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ClientException('The request body could not be encoded as JSON: ' . $exception->getMessage());
        }

        return [$json, ['Content-Type' => 'application/json', 'Accept' => 'application/json']];
    }

    /**
     * Flatten headers into the lines cURL wants, without losing the caller's.
     *
     * @param array<string, string> $defaults Headers the body implies
     * @return list<string> The header lines
     */
    private function headerLines(array $defaults): array
    {
        // The caller's headers win: a Content-Type set explicitly is a decision.
        $headers = array_merge($defaults, $this->headers);
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
