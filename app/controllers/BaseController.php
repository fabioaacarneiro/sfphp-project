<?php

namespace SfphpProject\app\controllers;

/**
 * Base controller, other controllers needs extends this
 */
class BaseController {

    /**
     * Get request data
     * 
     * @param string $key
     * @return string|null
     */
    public function requestGET(string $key = null) {
        return filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    }

    /**
     * Get post request data
     * 
     * @param string $key
     * @return string|null
     */
    public function requestPOST(string $key = null) {
        return filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    }
}
