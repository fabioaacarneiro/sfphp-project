<?php

namespace SfphpProject\src\Http;

use JsonException;
use JsonSerializable;
use LogicException;
use SfphpProject\src\View;

/**
 * An outgoing HTTP response.
 *
 * This is a value object and nothing more. It never calls header(), never
 * echoes, and never touches the output buffer: turning it into bytes is the
 * Emitter's job. Keeping the two apart is what lets the whole dispatch path be
 * asserted in a test without output buffering, and what makes a persistent
 * runtime a matter of writing one more emitter rather than reworking the
 * framework.
 */
final class Response
{
    /**
     * Create a response.
     *
     * @param string $body The response body
     * @param int $status The HTTP status code
     * @param array<string, string> $headers The headers, indexed by name
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = HTTP_OK,
        private readonly array $headers = []
    ) {}

    /**
     * Create an HTML response.
     *
     * @param string $html The rendered HTML
     * @param int $status The HTTP status code
     * @return self The response
     */
    public static function html(string $html, int $status = HTTP_OK): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Create a plain text response.
     *
     * @param string $text The response text
     * @param int $status The HTTP status code
     * @return self The response
     */
    public static function text(string $text, int $status = HTTP_OK): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data The payload to encode
     * @param int $status The HTTP status code
     * @return self The response
     * @throws JsonException If the payload cannot be encoded
     */
    public static function json(mixed $data, int $status = HTTP_OK): self
    {
        /*
         * UNESCAPED_UNICODE matters for a framework meant to run worldwide:
         * without it "São Paulo" ships as "São Paulo", which is valid
         * JSON but larger and unreadable in a log or a terminal.
         */
        $encoded = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return new self($encoded, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Render a view into an HTML response.
     *
     * @param string $view The view name
     * @param array<string, mixed> $data The data passed to the view
     * @param int $status The HTTP status code
     * @return self The response
     */
    public static function view(string $view, array $data = [], int $status = HTTP_OK): self
    {
        return self::html(View::make($view, $data), $status);
    }

    /**
     * Create a redirect response.
     *
     * @param string $url The destination
     * @param int $status The HTTP status code
     * @return self The response
     */
    public static function redirect(string $url, int $status = HTTP_FOUND): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Create an empty 204 response.
     *
     * @return self The response
     */
    public static function noContent(): self
    {
        return new self('', HTTP_NO_CONTENT);
    }

    /**
     * Coerce whatever a controller action returned into a response.
     *
     * A string becomes HTML and an array becomes JSON, so the common cases
     * read naturally. Returning nothing is an error rather than an empty 200:
     * an action that forgot its return statement is a bug, and reporting it
     * with the offending class and method makes it a one-line fix instead of
     * a blank page.
     *
     * @param mixed $value The action's return value
     * @param string $source A description of where the value came from
     * @return self The response
     * @throws LogicException If the value cannot be turned into a response
     * @throws JsonException If an array payload cannot be encoded
     */
    public static function from(mixed $value, string $source = 'The action'): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return self::html($value);
        }

        if (is_array($value) || $value instanceof JsonSerializable) {
            return self::json($value);
        }

        throw new LogicException(sprintf(
            '%s must return %s, a string or an array; got %s.',
            $source,
            self::class,
            get_debug_type($value)
        ));
    }

    /**
     * Get the HTTP status code.
     *
     * @return int The status code
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Get the response body.
     *
     * @return string The body
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Get every header.
     *
     * @return array<string, string> The headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get a single header value.
     *
     * @param string $name The header name, in any casing
     * @return string|null The value, or null when the header is absent
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Return a copy with a different status code.
     *
     * @param int $status The HTTP status code
     * @return self The copy
     */
    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    /**
     * Return a copy carrying an additional header.
     *
     * An existing header with the same name is replaced regardless of casing,
     * so a response cannot end up sending Content-Type twice.
     *
     * @param string $name The header name
     * @param string $value The header value
     * @return self The copy
     */
    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;

        foreach (array_keys($headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }

        $headers[$name] = $value;

        return new self($this->body, $this->status, $headers);
    }

    /**
     * Return a copy with a different body.
     *
     * @param string $body The response body
     * @return self The copy
     */
    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }
}
