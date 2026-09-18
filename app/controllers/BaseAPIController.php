<?php

namespace SfphpProject\app\controllers;

use JsonException;

/**
 * Base API controller, other controllers api needs extends this
 */
class BaseAPIController {
    /**
     * Get request data
     *
     * @return string
     */
    public function getRequest(): string {
        return file_get_contents('php://input');
    }

    /**
     * Get Bearer token
     *
     * @return string|null
     */
    public function getBearerToken(): ?string {
        $headers = getallheaders();
    
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }

    /**
     * Send a JSON response and end the request.
     *
     * This method never returns: it terminates the request so that code after
     * the call cannot run. Returning here would let an action keep executing
     * after it has already answered — for example issuing a token right after
     * responding with "login failed" — and emit a second body into the same
     * response.
     *
     * @param array $data
     * @param int $httpCode
     * @return never
     */
    public function responseJSON(
        array $data = [],
        int $httpCode = HTTP_OK
    ): never {
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=utf-8");
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
        } catch (JsonException $e) {
            error_log("Failed to encode JSON response: " . $e->getMessage());

            if (!headers_sent()) {
                http_response_code(HTTP_INTERNAL_SERVER_ERROR);
            }

            echo '{"message":"Internal Server Error"}';
        }

        exit;
    }
}
