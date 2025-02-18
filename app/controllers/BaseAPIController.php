<?php

namespace SfphpProject\app\controllers;

/**
 * Base API controller, other controllers api needs extends this
 */
class BaseAPIController {
    /**
     * Constructor
     */
    public function __construct() {
        header("Content-Type: application/json");
    }

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
    }

    /**
     * Response in JSON format
     *
     * @param array $data
     * @param int $httpCode
     * @return void
     */
    public function responseJSON(
        array $data = [], 
        int $httpCode = HTTP_OK
    ): void {
        http_response_code($httpCode);

        if ($httpCode === HTTP_NO_CONTENT) {
            return;
        }

        echo json_encode($data);
        return;
    }
}
