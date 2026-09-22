<?php

namespace SfphpProject\src\Http;

/**
 * The front door for talking to another service.
 *
 * The framework had Request and Response for what arrives and nothing for what
 * it sends, so calling a microservice meant curl_setopt_array with a dozen
 * constants, decoding the body by hand, and remembering — or, far more often,
 * forgetting — to set a timeout.
 *
 *     $response = Http::get('https://api.example.com/users', ['page' => 2]);
 *     $response = Http::post('https://api.example.com/users', ['name' => 'Ana']);
 *
 *     $response->ok();      // true for 2xx
 *     $response->json();    // the decoded body
 *
 * For a service you call more than once, keep a configured client. It is a
 * value: every method returns a new one, so it can be shared without anything
 * it is handed to being able to change it.
 *
 *     $billing = Http::base('https://billing.internal')->token($jwt)->timeout(5);
 *
 *     $invoice = $billing->get('/invoices/7')->throw()->json();
 *
 * This class exists separately from Client so that the verbs can be instance
 * methods there. They were static at first, which meant a configured client
 * calling one built a fresh default and **silently dropped the configuration**:
 * the token and the base URL never left the building, and the request went out
 * looking perfectly fine.
 *
 * **What this deliberately does not do: retry.** How many times to try, how long
 * to wait, and which failures deserve another attempt are decisions about the
 * service being called rather than about HTTP — a request that charges a card
 * is not one to repeat because a response was slow. That belongs to the
 * integration, next to the knowledge that can answer it.
 *
 * > **A URL that came from a visitor is a request an attacker chose.** Pointed
 * > at `169.254.169.254` or at something only your network can reach, this
 * > fetches it and hands back the answer — the attack called SSRF. Nothing here
 * > can tell a URL you built from one somebody typed, so check the ones you did
 * > not build.
 */
final class Http
{
    /**
     * A client with nothing configured.
     *
     * @return Client The client
     */
    public static function client(): Client
    {
        return new Client();
    }

    /**
     * Send a GET request.
     *
     * @param string $url The URL
     * @param array<string, mixed> $query Values appended as a query string
     * @return ClientResponse The response
     */
    public static function get(string $url, array $query = []): ClientResponse
    {
        return self::client()->get($url, $query);
    }

    /**
     * Send a POST request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body, sent as JSON
     * @return ClientResponse The response
     */
    public static function post(string $url, array|string|null $body = null): ClientResponse
    {
        return self::client()->post($url, $body);
    }

    /**
     * Send a PUT request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public static function put(string $url, array|string|null $body = null): ClientResponse
    {
        return self::client()->put($url, $body);
    }

    /**
     * Send a PATCH request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public static function patch(string $url, array|string|null $body = null): ClientResponse
    {
        return self::client()->patch($url, $body);
    }

    /**
     * Send a DELETE request.
     *
     * @param string $url The URL
     * @param array<string, mixed>|string|null $body The body
     * @return ClientResponse The response
     */
    public static function delete(string $url, array|string|null $body = null): ClientResponse
    {
        return self::client()->delete($url, $body);
    }

    /**
     * A client whose relative URLs hang off this one.
     *
     * @param string $url The base URL
     * @return Client The client
     */
    public static function base(string $url): Client
    {
        return self::client()->base($url);
    }

    /**
     * A client that sends these headers.
     *
     * @param array<string, string> $headers The headers
     * @return Client The client
     */
    public static function withHeaders(array $headers): Client
    {
        return self::client()->headers($headers);
    }

    /**
     * A client that sends a bearer token.
     *
     * @param string $token The token
     * @return Client The client
     */
    public static function withToken(string $token): Client
    {
        return self::client()->token($token);
    }

    /**
     * A client that sends HTTP basic credentials.
     *
     * @param string $username The username
     * @param string $password The password
     * @return Client The client
     */
    public static function withBasic(string $username, string $password): Client
    {
        return self::client()->basic($username, $password);
    }

    /**
     * A client that waits this long.
     *
     * @param int $seconds Seconds for the whole exchange
     * @param int|null $connect Seconds for the connection
     * @return Client The client
     */
    public static function timeout(int $seconds, ?int $connect = null): Client
    {
        return self::client()->timeout($seconds, $connect);
    }
}
