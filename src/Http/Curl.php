<?php

namespace SfphpProject\src\Http;

use Closure;

/**
 * What every cURL transfer the framework makes has in common.
 *
 * The synchronous client, the streaming client and the async future each
 * configured cURL on their own, and each got the same things wrong: HEAD sent
 * as a custom request, which waits for a body that never comes; POST sent as
 * a custom request, which curl repeats — without its body — after a redirect;
 * the headers of every redirect merged into the final response; and header
 * values sent as given, so a line break in one added headers of the caller's
 * choosing. They share this now.
 */
final class Curl
{
    /**
     * The options that make cURL send a method properly.
     *
     * GET, HEAD and POST have options of their own, and those are what make
     * cURL behave like a browser across a redirect: HEAD reads no body, and a
     * POST answered with 301, 302 or 303 is followed with a GET, while 307
     * and 308 repeat the POST with its body. CURLOPT_CUSTOMREQUEST repeats
     * the method on every hop and drops the body.
     *
     * @param string $method The method, in any case
     * @return array<int, mixed> cURL options
     */
    public static function methodOptions(string $method): array
    {
        return match (strtoupper($method)) {
            'GET' => [CURLOPT_HTTPGET => true],
            'HEAD' => [CURLOPT_NOBODY => true],
            'POST' => [CURLOPT_POST => true],
            default => [CURLOPT_CUSTOMREQUEST => strtoupper($method)],
        };
    }

    /**
     * Turn headers into lines, refusing any that would inject others.
     *
     * @param array<string, string> $headers Name => value
     * @return list<string> The lines
     * @throws ClientException When a name is not a header name, or a value carries a line break
     */
    public static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;

            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new ClientException(sprintf('"%s" is not a valid header name.', $name));
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new ClientException(sprintf(
                    'The value of the %s header contains a line break, which would add headers of its own. Refused.',
                    $name
                ));
            }

            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * A CURLOPT_HEADERFUNCTION that keeps the final response's headers.
     *
     * Every status line starts a new set, so a redirect's Location and
     * Set-Cookie, or a 100 Continue, do not end up in the response that is
     * returned. A header sent more than once is kept whole, its values
     * joined with a comma, rather than only the last one.
     *
     * @param array<string, string> $headers Filled in as the headers arrive
     * @return Closure The callback
     */
    public static function headerCollector(array &$headers): Closure
    {
        return static function ($handle, string $line) use (&$headers): int {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];

                return strlen($line);
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $existing = null;

                foreach (array_keys($headers) as $key) {
                    if (strcasecmp($key, $name) === 0) {
                        $existing = $key;

                        break;
                    }
                }

                if ($existing === null) {
                    $headers[$name] = $value;
                } else {
                    $headers[$existing] .= ', ' . $value;
                }
            }

            return strlen($line);
        };
    }

    /**
     * Whether a URL is on the same origin as another.
     *
     * @param string $url The URL being requested
     * @param string $base The client's base URL
     * @return bool
     */
    public static function sameOrigin(string $url, string $base): bool
    {
        $a = parse_url($url);
        $b = parse_url($base);

        if (!is_array($a) || !is_array($b)) {
            return false;
        }

        $port = static fn (array $u): int => (int) ($u['port'] ?? (strtolower($u['scheme'] ?? '') === 'https' ? 443 : 80));

        return strcasecmp($a['scheme'] ?? '', $b['scheme'] ?? '') === 0
            && strcasecmp($a['host'] ?? '', $b['host'] ?? '') === 0
            && $port($a) === $port($b);
    }
}
