<?php

namespace SfphpProject\app\controllers;

use JsonException;

/**
 * Base controller for JSON API endpoints.
 */
class BaseAPIController
{
    /**
     * Get the raw request body.
     *
     * @return string The unparsed request body
     */
    public function getRequest(): string
    {
        return file_get_contents('php://input');
    }

    /**
     * Decode the current JSON request body.
     *
     * @return array The decoded request payload
     */
    public function getJsonRequest(): array
    {
        $contentType = $this->getHeader('Content-Type');
        if ($contentType !== null && !str_contains(
            strtolower($contentType),
            'application/json'
        )) {
            $this->responseJSON(
                ['message' => 'Content-Type must be application/json'],
                HTTP_UNSUPPORTED_MEDIA_TYPE
            );
        }

        try {
            $data = json_decode(
                $this->getRequest(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $this->responseJSON(
                ['message' => 'Invalid JSON body'],
                HTTP_BAD_REQUEST
            );
        }

        if (!is_array($data)) {
            $this->responseJSON(
                ['message' => 'JSON body must be an object or array'],
                HTTP_BAD_REQUEST
            );
        }

        return $data;
    }

    /**
     * Get a request header without depending on server-specific casing.
     *
     * @param string $name The header name
     * @return string|null The header value, or null when absent
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    /**
     * Get the Bearer token from the Authorization header.
     *
     * @return string|null The token, or null when absent or malformed
     */
    public function getBearerToken(): ?string
    {
        $authorization = $this->getHeader('Authorization');
        if ($authorization === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Send a JSON response and end the request.
     *
     * @param array $data The response payload
     * @param int $httpCode The HTTP response status
     * @return never
     */
    public function responseJSON(
        array $data = [],
        int $httpCode = HTTP_OK
    ): never {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($httpCode);
        }

        if ($httpCode === HTTP_NO_CONTENT) {
            exit;
        }

        try {
            echo json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            error_log('Failed to encode JSON response: ' . $exception->getMessage());

            if (!headers_sent()) {
                http_response_code(HTTP_INTERNAL_SERVER_ERROR);
            }

            echo '{"message":"Internal Server Error"}';
        }

        exit;
    }

    /**
     * Get normalized request headers from available PHP server APIs.
     *
     * @return array The headers indexed by lowercase name
     */
    private function headers(): array
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[$name] = $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
