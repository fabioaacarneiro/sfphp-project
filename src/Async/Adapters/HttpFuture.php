<?php

namespace SfphpProject\src\Async\Adapters;

use SfphpProject\src\Async\Future;

/**
 * A Future wrapper for HTTP requests
 *
 * Allows making HTTP requests asynchronously using async/await syntax.
 * Currently uses curl but with lazy execution - actual request happens
 * when getValue() is called (i.e., when await() needs the result).
 *
 * Future versions could use truly non-blocking socket I/O.
 */
class HttpFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];

    private string $method;
    private string $url;
    private array $headers;
    private mixed $body;
    private array $options;

    /**
     * Create an HttpFuture for a GET/POST/etc request
     *
     * @param string $method HTTP method (GET, POST, etc)
     * @param string $url The full URL
     * @param array $headers Request headers
     * @param mixed $body Request body (for POST/PUT)
     * @param array $options Additional curl options
     */
    public function __construct(
        string $method,
        string $url,
        array $headers = [],
        mixed $body = null,
        array $options = []
    ) {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->options = $options;
    }

    /**
     * Execute the HTTP request
     */
    private function execute(): void
    {
        if ($this->executed) {
            return;
        }

        try {
            $this->result = $this->makeRequest();
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
        }
    }

    /**
     * Execute the actual HTTP request using curl
     */
    private function makeRequest(): array
    {
        $curl = curl_init();

        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_CUSTOMREQUEST => $this->method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            // Add headers
            if (!empty($this->headers)) {
                $headerLines = [];
                foreach ($this->headers as $key => $value) {
                    $headerLines[] = "$key: $value";
                }
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headerLines);
            }

            // Add body for POST/PUT/PATCH
            if ($this->body !== null) {
                $bodyString = is_string($this->body)
                    ? $this->body
                    : json_encode($this->body);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $bodyString);
            }

            // Apply custom options
            foreach ($this->options as $option => $value) {
                curl_setopt($curl, $option, $value);
            }

            // Get response headers
            $responseHeaders = [];
            curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                if (strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
                return $len;
            });

            $body = curl_exec($curl);
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

            if ($body === false) {
                throw new \Exception("HTTP request failed: " . curl_error($curl));
            }

            return [
                'status' => $statusCode,
                'headers' => $responseHeaders,
                'content_type' => $contentType,
                'body' => $body,
                'json' => function () use ($body) {
                    return json_decode($body, true);
                },
            ];
        } finally {
            curl_close($curl);
        }
    }

    public function isPending(): bool
    {
        return !$this->executed;
    }

    public function isResolved(): bool
    {
        return $this->executed && $this->exception === null;
    }

    public function isRejected(): bool
    {
        return $this->exception !== null;
    }

    public function getValue()
    {
        if (!$this->executed) {
            $this->execute();
        }

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        if (!$this->executed) {
            $this->execute();
        }
        return $this->exception;
    }

    public function onResolve(callable $callback): void
    {
        if ($this->executed) {
            $callback($this);
        } else {
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Notify all callbacks
     */
    private function notifyCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                // Ignore callback errors
            }
        }
        $this->callbacks = [];
    }

    /**
     * Create an HttpFuture for a GET request
     */
    public static function get(string $url, array $headers = [], array $options = []): self
    {
        return new self('GET', $url, $headers, null, $options);
    }

    /**
     * Create an HttpFuture for a POST request
     */
    public static function post(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('POST', $url, $headers, $body, $options);
    }

    /**
     * Create an HttpFuture for a PUT request
     */
    public static function put(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('PUT', $url, $headers, $body, $options);
    }

    /**
     * Create an HttpFuture for a PATCH request
     */
    public static function patch(string $url, mixed $body = null, array $headers = [], array $options = []): self
    {
        return new self('PATCH', $url, $headers, $body, $options);
    }

    /**
     * Create an HttpFuture for a DELETE request
     */
    public static function delete(string $url, array $headers = [], array $options = []): self
    {
        return new self('DELETE', $url, $headers, null, $options);
    }
}
