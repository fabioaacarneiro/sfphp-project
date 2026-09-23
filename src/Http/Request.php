<?php

namespace SfphpProject\src\Http;

use JsonException;

/**
 * An incoming HTTP request.
 *
 * The constructor takes plain arrays and never reads a superglobal. Only
 * fromGlobals() does, and it is a named constructor rather than the
 * constructor itself. That separation is what makes the request reusable
 * outside a classic PHP-FPM cycle: a Swoole or FrankenPHP worker builds its
 * own arrays and calls "new Request(...)", and nothing else in the framework
 * has to know the difference. It is also what makes the router testable,
 * because a test can build a request without touching $_SERVER.
 *
 * Every field describing the HTTP message is readonly. Attributes are the one
 * exception: route parameters, an authenticated user and similar values get
 * attached as the request travels through middleware, so withAttribute()
 * clones and writes. It cannot be readonly to do that, because PHP 8.1 through
 * 8.4 refuse to reassign a readonly property on a clone ("clone with" only
 * arrives in 8.5).
 */
final class Request
{
    /**
     * Proxies whose forwarding headers are believed.
     *
     * @var array<int, string>
     */
    private static array $trustedProxies = [];

    /**
     * Values attached to the request while it travels through the pipeline.
     *
     * @var array<string, mixed>
     */
    private array $attributes;

    private ?array $decodedJson = null;
    private bool $jsonDecoded = false;

    /**
     * Create a request.
     *
     * @param string $method The HTTP method, upper case
     * @param string $path The decoded path, without the query string
     * @param array<string, mixed> $query The query string values
     * @param array<string, mixed> $body The parsed request body
     * @param array<string, string> $headers The headers, indexed by lower-case name
     * @param array<string, string> $cookies The request cookies
     * @param array<string, mixed> $files The uploaded files
     * @param array<string, mixed> $server The server and execution environment
     * @param string $rawBody The unparsed request body
     * @param array<string, mixed> $attributes Values attached to the request
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
        public readonly string $rawBody = '',
        array $attributes = []
    ) {
        $this->attributes = $attributes;
    }

    /**
     * Build a request from the current PHP superglobals.
     *
     * This is the only place in the framework that reads them.
     *
     * @return self The current request
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? GET));
        $path = self::decodePath(
            parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'
        );

        /*
         * php://input is read only when the method can carry a body. For a
         * multipart upload PHP has already consumed the stream into $_POST and
         * $_FILES, so the read yields an empty string rather than the payload;
         * that is expected, and $files carries the data instead.
         */
        $rawBody = in_array($method, [GET, HEAD], true)
            ? ''
            : (string) file_get_contents('php://input');

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            self::normalizeHeaders($_SERVER),
            $_COOKIE,
            $_FILES,
            $_SERVER,
            $rawBody
        );
    }

    /**
     * Build a request directly, for tests and for non-web entry points.
     *
     * @param string $method The HTTP method
     * @param string $uri The request URI, with or without a query string
     * @param array<string, mixed> $options Any of: query, body, headers, cookies,
     *                                      files, server, rawBody, attributes
     * @return self The built request
     */
    public static function create(string $method, string $uri, array $options = []): self
    {
        $path = self::decodePath(parse_url($uri, PHP_URL_PATH) ?: '/');

        $query = $options['query'] ?? [];
        if ($query === []) {
            parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        }

        $headers = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }

        return new self(
            strtoupper($method),
            $path,
            $query,
            $options['body'] ?? [],
            $headers,
            $options['cookies'] ?? [],
            $options['files'] ?? [],
            $options['server'] ?? [],
            (string) ($options['rawBody'] ?? ''),
            $options['attributes'] ?? []
        );
    }

    /**
     * Check whether the request uses the given HTTP method.
     *
     * @param string $method The method to compare against
     * @return bool True when the methods match
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Get a query string value.
     *
     * @param string $key The parameter name
     * @param mixed $default Returned when the parameter is absent
     * @return mixed The raw value, unmodified
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get every query string value.
     *
     * @return array<string, mixed> The query string
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * Get a parsed body value.
     *
     * @param string $key The field name
     * @param mixed $default Returned when the field is absent
     * @return mixed The raw value, unmodified
     */
    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get the whole parsed body.
     *
     * @return array<string, mixed> The request body
     */
    public function bodyAll(): array
    {
        return $this->body;
    }

    /**
     * Get a value from the body, the JSON payload, or the query string.
     *
     * The order is fixed and deliberate: body, then JSON, then query. A form
     * post and a JSON post reach the action the same way, and a query string
     * never shadows a submitted field.
     *
     * @param string $key The value name
     * @param mixed $default Returned when the value is absent everywhere
     * @return mixed The raw value, unmodified
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        $json = $this->jsonOrNull();
        if ($json !== null && array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get everything submitted, merging body, JSON payload and query string.
     *
     * @return array<string, mixed> The merged values
     */
    public function all(): array
    {
        return array_merge($this->query, $this->jsonOrNull() ?? [], $this->body);
    }

    /**
     * Check whether a value was submitted and is not empty.
     *
     * @param string $key The value name
     * @return bool True when present and not an empty string
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== [];
    }

    /**
     * Decode the request body as JSON.
     *
     * @return array<string, mixed> The decoded payload
     * @throws JsonException If the body is not a valid JSON object or array
     */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('JSON body must be an object or array.');
        }

        return $decoded;
    }

    /**
     * Get a header value.
     *
     * @param string $name The header name, in any casing
     * @return string|null The value, or null when the header is absent
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Get every header.
     *
     * @return array<string, string> The headers, indexed by lower-case name
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Check whether a header is present.
     *
     * @param string $name The header name, in any casing
     * @return bool True when the header is present
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Get the Bearer token from the Authorization header.
     *
     * @return string|null The token, or null when absent or malformed
     */
    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');
        if ($authorization === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get a cookie value.
     *
     * @param string $name The cookie name
     * @param string|null $default Returned when the cookie is absent
     * @return string|null The cookie value
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get an uploaded file.
     *
     * Returns an UploadedFile rather than the raw `$_FILES` entry, which is
     * what this used to hand back. The raw entry is still there through
     * rawFiles(), but nothing should need it: everything security-critical
     * about an upload — that it is an upload at all, what it actually contains,
     * and what it is safe to call it — is on the object.
     *
     * A field that accepted several files answers null here; use files().
     *
     * @param string $name The field name
     * @return UploadedFile|null The file, or null when the field is absent or holds several
     */
    public function file(string $name): ?UploadedFile
    {
        $entry = $this->files[$name] ?? null;

        if (!is_array($entry) || is_array($entry['name'] ?? null)) {
            return null;
        }

        return UploadedFile::fromArray($entry);
    }

    /**
     * Get every file sent under one field name.
     *
     * A form with `name="photos[]"` arrives as one entry whose members are
     * arrays, which is the shape that catches people out: `$_FILES['photos']`
     * is not a list of files, it is a file whose every property is a list.
     * This turns it the right way round.
     *
     * @param string $name The field name
     * @return list<UploadedFile> The files, in the order they were sent
     */
    public function files(string $name): array
    {
        $entry = $this->files[$name] ?? null;

        if (!is_array($entry)) {
            return [];
        }

        if (!is_array($entry['name'] ?? null)) {
            return [UploadedFile::fromArray($entry)];
        }

        $files = [];

        foreach (array_keys($entry['name']) as $index) {
            $files[] = UploadedFile::fromArray([
                'name' => $entry['name'][$index] ?? '',
                'tmp_name' => $entry['tmp_name'][$index] ?? '',
                'size' => $entry['size'][$index] ?? 0,
                'error' => $entry['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            ]);
        }

        return $files;
    }

    /**
     * Whether a usable file arrived under this field name.
     *
     * @param string $name The field name
     * @return bool True when at least one valid file is present
     */
    public function hasFile(string $name): bool
    {
        foreach ($this->files($name) as $file) {
            if ($file->isValid()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw `$_FILES` structure.
     *
     * @return array<string, mixed> The structure as PHP built it
     */
    public function rawFiles(): array
    {
        return $this->files;
    }

    /**
     * Read a server or environment value.
     *
     * @param string $key The entry name
     * @param mixed $default Returned when the entry is absent
     * @return mixed The value
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Declare which proxies may be believed.
     *
     * Forwarding headers are client-controlled: anyone can send
     * X-Forwarded-For or X-Forwarded-Proto. They are only meaningful when the
     * connection itself comes from a machine known to rewrite them, which is
     * why nothing is trusted until the deployment says what to trust.
     *
     *     Request::setTrustedProxies(['10.0.0.0/8', '172.16.0.5']);
     *
     * This is not optional detail behind a TLS-terminating load balancer. The
     * PHP process there sees plain HTTP, so isSecure() answers false, and the
     * session cookie loses its "secure" flag — it then travels in the clear if
     * the visitor ever reaches the site over HTTP.
     *
     * @param array<int, string> $proxies Addresses or CIDR ranges
     * @return void
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = $proxies;
    }

    /**
     * Get the proxies whose forwarding headers are believed.
     *
     * @return array<int, string> The addresses or CIDR ranges
     */
    public static function trustedProxies(): array
    {
        return self::$trustedProxies;
    }

    /**
     * Get the client IP address.
     *
     * X-Forwarded-For is read only when the connection itself comes from a
     * trusted proxy; otherwise any visitor could claim any address, which
     * matters as soon as anything rate limits or logs by IP.
     *
     * @return string|null The address, or null when unknown
     */
    public function ip(): ?string
    {
        $address = $this->server['REMOTE_ADDR'] ?? null;
        $address = is_string($address) ? $address : null;

        if (!$this->fromTrustedProxy()) {
            return $address;
        }

        $forwarded = $this->header('X-Forwarded-For');

        if ($forwarded === null) {
            return $address;
        }

        /*
         * The header is a chain, oldest first: "client, proxy1, proxy2". The
         * left-most entry is the original client — and also the only one the
         * client itself could have written, which is why it is read only after
         * establishing that a trusted proxy appended to it.
         */
        $first = trim(explode(',', $forwarded)[0]);

        return $first === '' ? $address : $first;
    }

    /**
     * Determine whether the request arrived over HTTPS.
     *
     * @return bool True when the request is secure
     */
    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        if (!$this->fromTrustedProxy()) {
            return false;
        }

        return strtolower(trim(explode(',', $this->header('X-Forwarded-Proto') ?? '')[0])) === 'https';
    }

    /**
     * Check whether the connection came from a proxy that may be believed.
     *
     * @return bool True when REMOTE_ADDR is trusted
     */
    private function fromTrustedProxy(): bool
    {
        if (self::$trustedProxies === []) {
            return false;
        }

        $remote = $this->server['REMOTE_ADDR'] ?? null;

        if (!is_string($remote)) {
            return false;
        }

        foreach (self::$trustedProxies as $proxy) {
            if (self::addressMatches($remote, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check an address against a literal or a CIDR range.
     *
     * @param string $address The address to check
     * @param string $range The literal address or CIDR range
     * @return bool True when the address falls inside
     */
    private static function addressMatches(string $address, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $address === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);

        $addressBinary = inet_pton($address);
        $subnetBinary = inet_pton($subnet);

        if ($addressBinary === false || $subnetBinary === false
            || strlen($addressBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($addressBinary, $subnetBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($addressBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    /**
     * Whether this request wants a fragment rather than a whole page.
     *
     * SFJS sends `X-Requested-With: XMLHttpRequest` when it is going to swap
     * the answer into the page, so what it needs is the piece that changed.
     * A browser that submitted the same form with no JavaScript running sends
     * nothing of the sort and needs the page around it — which is how one
     * action serves both without the author writing the answer twice.
     *
     * @return bool True when only the fragment is wanted
     */
    public function isFragment(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /**
     * Determine whether the client wants a JSON response.
     *
     * @return bool True when JSON is requested or submitted
     */
    public function expectsJson(): bool
    {
        $accept = strtolower($this->header('Accept') ?? '');
        $contentType = strtolower($this->header('Content-Type') ?? '');

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
    }

    /**
     * Get the languages the client asked for, best first.
     *
     * Parses Accept-Language, including the quality values that order it, so
     * "pt-BR,pt;q=0.9,en;q=0.8" becomes ['pt-BR', 'pt', 'en']. A tag with
     * q=0 is dropped, since that is how a client says it does not want one.
     *
     * @return array<int, string> The language tags, in descending preference
     */
    public function acceptedLanguages(): array
    {
        $header = $this->header('Accept-Language');

        if ($header === null || trim($header) === '') {
            return [];
        }

        $languages = [];

        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = trim($pieces[0]);

            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            /*
             * The position is kept as a tiebreaker so that tags sharing a
             * quality keep the order the client wrote them in, which usort
             * alone would not guarantee.
             */
            $languages[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
        }

        usort(
            $languages,
            static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']
                ?: $a['position'] <=> $b['position']
        );

        return array_column($languages, 'tag');
    }

    /**
     * Pick the best match between what the client asked for and what exists.
     *
     * A request for "pt-BR" matches an available "pt_BR" exactly, and falls
     * back to a plain "pt" when only that is offered — asking for Brazilian
     * Portuguese and being served Portuguese is better than being served
     * English.
     *
     * @param array<int, string> $available The locales the application has
     * @param string|null $fallback Returned when nothing matches
     * @return string|null The chosen locale
     */
    public function preferredLanguage(array $available, ?string $fallback = null): ?string
    {
        $normalized = [];
        foreach ($available as $locale) {
            $normalized[strtolower(str_replace('-', '_', $locale))] = $locale;
        }

        foreach ($this->acceptedLanguages() as $tag) {
            $candidate = strtolower(str_replace('-', '_', $tag));

            if (isset($normalized[$candidate])) {
                return $normalized[$candidate];
            }

            $base = strstr($candidate, '_', true);

            if ($base !== false && isset($normalized[$base])) {
                return $normalized[$base];
            }

            // "pt" asked for, only "pt_BR" offered: take the regional variant.
            foreach ($normalized as $key => $locale) {
                if (strstr($key, '_', true) === $candidate) {
                    return $locale;
                }
            }
        }

        return $fallback;
    }

    /**
     * Get a route parameter.
     *
     * @param string $name The parameter name
     * @param mixed $default Returned when the parameter is absent
     * @return mixed The parameter value
     */
    public function route(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Get the authenticated user, when the request has one.
     *
     * Set by the Authenticate middleware. A request that never went through it
     * reports no user, rather than resolving one here — resolving lazily would
     * mean a route could read a user the pipeline never authenticated.
     *
     * @return mixed The user, or null when the request is anonymous
     */
    public function user(): mixed
    {
        return $this->attributes['user'] ?? null;
    }

    /**
     * Get a value attached to the request.
     *
     * @param string $key The attribute name
     * @param mixed $default Returned when the attribute is absent
     * @return mixed The attribute value
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get every value attached to the request.
     *
     * @return array<string, mixed> The attributes
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Attach a value, returning a new request.
     *
     * @param string $key The attribute name
     * @param mixed $value The attribute value
     * @return self A copy carrying the attribute
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributes[$key] = $value;

        return $copy;
    }

    /**
     * Attach several values, returning a new request.
     *
     * @param array<string, mixed> $attributes The attributes to merge in
     * @return self A copy carrying the attributes
     */
    public function withAttributes(array $attributes): self
    {
        $copy = clone $this;
        $copy->attributes = array_merge($copy->attributes, $attributes);

        return $copy;
    }

    /**
     * Decode the JSON body, returning null instead of throwing.
     *
     * @return array<string, mixed>|null The decoded payload, or null
     */
    private function jsonOrNull(): ?array
    {
        if ($this->jsonDecoded) {
            return $this->decodedJson;
        }

        $this->jsonDecoded = true;

        if ($this->rawBody === '' || !str_contains(strtolower($this->header('Content-Type') ?? ''), 'json')) {
            return $this->decodedJson = null;
        }

        try {
            $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->decodedJson = null;
        }

        return $this->decodedJson = is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalize request headers from the server environment.
     *
     * Handles the HTTP_ prefix, the two entries PHP reports without it, and
     * the Authorization header that Apache hands over as REDIRECT_HTTP_*
     * once a rewrite has run.
     *
     * @param array<string, mixed> $server The server environment
     * @return array<string, string> The headers, indexed by lower-case name
     */
    private static function normalizeHeaders(array $server): array
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        if (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return $normalized;
    }

    /**
     * Decode a percent-encoded path without inventing new segments.
     *
     * Decoding is done segment by segment, and any separator produced by the
     * decoding is encoded straight back. Otherwise "/a%2Fb" would decode to
     * "/a/b" and reach a route registered as "/a/b", giving the client a way
     * to address a route through a path it never actually requested.
     *
     * @param string $path The raw request path
     * @return string The decoded path
     */
    private static function decodePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => str_replace(
                ['/', '\\'],
                ['%2F', '%5C'],
                rawurldecode($segment)
            ),
            explode('/', $path)
        );

        return implode('/', $segments);
    }
}
