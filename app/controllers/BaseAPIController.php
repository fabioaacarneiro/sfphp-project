<?php

namespace SfphpProject\app\controllers;

use JsonException;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Base controller for JSON API endpoints.
 *
 * Reading the request — the raw body, the decoded JSON, headers, the bearer
 * token — moved to Request, where it belongs and where it can be tested. What
 * is left here are the two helpers for producing a response.
 */
class BaseAPIController
{
    /**
     * Build a JSON response.
     *
     * This returns rather than sending. An earlier version was declared
     * "never" and called exit, so no middleware ever saw the response on its
     * way out, and only on the routes that happened to use it.
     *
     * @param mixed $data The response payload
     * @param int $status The HTTP status code
     * @return Response The JSON response
     * @throws JsonException If the payload cannot be encoded
     */
    protected function json(mixed $data = [], int $status = HTTP_OK): Response
    {
        if ($status === HTTP_NO_CONTENT) {
            return Response::noContent();
        }

        return Response::json($data, $status);
    }

    /**
     * Decode the request body, refusing anything that is not JSON.
     *
     * Returns a Response instead of the payload when the request is not
     * acceptable, so the action can hand it straight back:
     *
     *     $data = $this->payload($request);
     *     if ($data instanceof Response) {
     *         return $data;
     *     }
     *
     * @param Request $request The incoming request
     * @return array<string, mixed>|Response The payload, or the response to send
     */
    protected function payload(Request $request): array|Response
    {
        $contentType = $request->header('Content-Type');

        if ($contentType !== null && !str_contains(strtolower($contentType), 'application/json')) {
            return $this->json(
                ['message' => 'Content-Type must be application/json'],
                HTTP_UNSUPPORTED_MEDIA_TYPE
            );
        }

        try {
            return $request->json();
        } catch (JsonException) {
            return $this->json(['message' => 'Invalid JSON body'], HTTP_BAD_REQUEST);
        }
    }
}
