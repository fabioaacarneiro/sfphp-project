<?php

namespace SfphpProject\src\Http;

use JsonException;
use JsonSerializable;
use LogicException;
use Stringable;
use SfphpProject\src\Router;
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
 *
 * Responses can stream their body: instead of buffering the entire response,
 * the body is generated in chunks. Streaming responses bypass normal body
 * buffering and are sent directly to the client as they're produced.
 */
final class Response
{
    /** @var callable(StreamWriter): void|null */
    private readonly mixed $producer;

    /**
     * Create a response.
     *
     * @param string $body The response body
     * @param int $status The HTTP status code
     * @param array<string, string> $headers The headers, indexed by name
     * @param callable(StreamWriter): void|null $producer For streaming responses
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = HTTP_OK,
        private readonly array $headers = [],
        callable|null $producer = null
    ) {
        $this->producer = $producer;
    }

    /**
     * Create a streaming response.
     *
     * The producer receives a StreamWriter and writes chunks as they're
     * generated. Nothing is executed until the Emitter sends the response.
     *
     * Headers are sent before the first chunk. Middlewares can still modify
     * status and headers before emission.
     *
     *     return Response::stream(function(StreamWriter $out) {
     *         for ($i = 0; $i < 100; $i++) {
     *             if ($out->aborted()) break;
     *             $out->write("Chunk $i\n");
     *             usleep(100000);
     *         }
     *     });
     *
     * @param callable(StreamWriter): void $producer Generates response chunks
     * @param int $status The HTTP status code
     * @param array<string, string> $headers The headers, indexed by name
     * @return self The response
     */
    public static function stream(
        callable $producer,
        int $status = HTTP_OK,
        array $headers = []
    ): self {
        return new self('', $status, $headers, $producer);
    }

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
     * Answer with a fragment, or with the page it belongs to.
     *
     * The same action serves both: SFJS asked for the piece that changed and
     * gets it; a browser with no JavaScript running submitted the same form
     * and gets the whole page, with the fragment already in place. Without
     * this the choice is written by hand in every action, and the two answers
     * drift apart — which is exactly what happened in the example application
     * before this existed.
     *
     *     return Response::fragment($request, $panel, page: fn ($inner) => Dashboard($inner));
     *
     * @param Request $request The incoming request
     * @param Stringable|string $fragment The piece that changed
     * @param callable(Stringable|string): (Stringable|string)|null $page Wraps the fragment in its page
     * @param int $status The HTTP status code
     * @return self The response
     */
    public static function fragment(
        Request $request,
        Stringable|string $fragment,
        ?callable $page = null,
        int $status = HTTP_OK
    ): self {
        if ($page === null || $request->isFragment()) {
            return self::html((string) $fragment, $status);
        }

        return self::html((string) $page($fragment), $status);
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
    /**
     * Redirect to a named route.
     *
     * @param string $name The route name
     * @param array<string, string|int> $parameters The route parameters
     * @param array<string, string|int> $query Query string values
     * @return self The response
     */
    public static function route(string $name, array $parameters = [], array $query = []): self
    {
        return self::redirect(Router::url($name, $parameters, $query));
    }

    /**
     * Send the visitor back where they came from.
     *
     * The referer is a header, which means the visitor chooses it, which makes
     * it a redirect destination an attacker can pick. Following one to another
     * origin is an open redirect — how a phishing link borrows a domain's good
     * name. Only a path on this site is followed; anything else falls back.
     *
     * @param Request $request The current request
     * @param string $fallback Where to go when there is no usable referer
     * @return self The response
     */
    public static function back(Request $request, string $fallback = '/'): self
    {
        $referer = trim((string) $request->header('Referer'));

        if ($referer === '' || str_starts_with($referer, '//')) {
            // "//evil.example/x" has no scheme and is still another origin to a
            // browser, which is why it is refused before anything is parsed.
            return self::redirect($fallback);
        }

        $parts = parse_url($referer);

        if ($parts === false) {
            return self::redirect($fallback);
        }

        $host = $parts['host'] ?? null;

        /*
         * A referer naming another host is not somewhere "back" should go, even
         * though only its path would be used: a visitor arriving from a search
         * engine would be sent to whatever that engine's path happens to spell
         * on this site.
         */
        if ($host !== null && strcasecmp($host, (string) $request->header('Host')) !== 0) {
            return self::redirect($fallback);
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || !str_starts_with($path, '/')) {
            return self::redirect($fallback);
        }

        $query = $parts['query'] ?? '';

        return self::redirect($path . ($query !== '' ? '?' . $query : ''));
    }

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
     * For streaming responses, this throws LogicException: the body is
     * generated in chunks and cannot be read as a string.
     *
     * @return string The body
     * @throws LogicException If this is a streaming response
     */
    public function body(): string
    {
        if ($this->producer !== null) {
            throw new LogicException(
                'Cannot get body of a streaming response. The body is generated in chunks by the producer.'
            );
        }

        return $this->body;
    }

    /**
     * Check if this is a streaming response.
     *
     * @return bool True if the response will stream its body
     */
    public function isStream(): bool
    {
        return $this->producer !== null;
    }

    /**
     * Get the stream producer (internal use only).
     *
     * @return callable(StreamWriter): void|null
     * @internal
     */
    public function producer(): ?callable
    {
        return $this->producer;
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
        return new self($this->body, $status, $this->headers, $this->producer);
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

        return new self($this->body, $this->status, $headers, $this->producer);
    }

    /**
     * Return a copy with a different body.
     *
     * @param string $body The response body
     * @return self The copy
     */
    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers, $this->producer);
    }
}
