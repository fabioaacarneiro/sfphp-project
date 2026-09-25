<?php

namespace SfphpProject\src\Http;

use JsonException;

/**
 * What came back.
 *
 * Read-only on purpose: this describes something that already happened, so
 * there is nothing here to change. It is separate from Response, which is what
 * the application sends, because the two have opposite jobs and sharing a class
 * between them would mean half its methods being wrong at any moment.
 */
final class ClientResponse
{
    /**
     * @param int $status The status code
     * @param string $body The body as it arrived
     * @param array<string, string> $headers The response headers
     * @param string $url The URL that answered, after any redirects
     */
    public function __construct(
        private int $status,
        private string $body,
        private array $headers = [],
        private string $url = ''
    ) {
    }

    /**
     * The status code.
     *
     * @return int The code
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Whether the server answered with success.
     *
     * @return bool True for 2xx
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Whether the server answered with an error.
     *
     * @return bool True for 4xx and 5xx
     */
    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /**
     * Whether the error was the caller's.
     *
     * @return bool True for 4xx
     */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Whether the error was the server's.
     *
     * @return bool True for 5xx
     */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * The body, as it arrived.
     *
     * @return string The body
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * The body, decoded as JSON.
     *
     * @param bool $strict Throw when the body is not JSON, rather than answering null
     * @return array<string, mixed>|null The decoded body
     * @throws ClientException When strict and the body does not decode
     */
    public function json(bool $strict = false): ?array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($strict) {
                throw new ClientException(
                    'The response from ' . $this->url . ' is not JSON: ' . $exception->getMessage()
                );
            }

            /*
             * Null rather than an exception by default, because a service
             * answering an error page instead of JSON is a thing that happens
             * and a caller can reasonably want to check the status first.
             */
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * One response header, by name.
     *
     * @param string $name The header name, in any case
     * @return string|null The value
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Every response header.
     *
     * @return array<string, string> The headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The URL that answered, after any redirects.
     *
     * @return string The URL
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Throw when the server answered with an error.
     *
     * Opt-in, because a 404 is often an expected answer rather than a failure.
     * Where it is a failure, this keeps the call readable:
     *
     *     $invoice = Http::get($url)->throw()->json();
     *
     * @return self This response, when it is not an error
     * @throws ClientException When the status is 4xx or 5xx
     */
    public function throw(): self
    {
        if (!$this->failed()) {
            return $this;
        }

        /*
         * The first part of the body, because an API that refuses usually says
         * why and a message without it sends somebody to read server logs they
         * may not have.
         */
        $detail = trim($this->body);
        $detail = $detail === '' ? '' : ': ' . mb_strimwidth($detail, 0, 300, '…');

        throw new ClientException(sprintf('%s answered %d%s', $this->url, $this->status, $detail));
    }
}
