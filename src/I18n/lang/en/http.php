<?php

/**
 * Messages the framework itself sends to a visitor.
 *
 * An application overrides any of these by adding a file with the same name
 * under its own lang directory; only the keys it declares are replaced.
 */

return [
    'bad_request_title' => '400 - Bad request',
    'bad_request_message' => 'The request could not be understood.',
    'unauthorized_title' => '401 - Unauthorized',
    'unauthorized_message' => 'You need to sign in to see this page.',
    'forbidden_title' => '403 - Forbidden',
    'forbidden_message' => 'You are not allowed to do this.',
    'not_found_title' => '404 - Page not found',
    'not_found_message' => 'Sorry, the page you are looking for was not found.',
    'method_not_allowed_title' => '405 - Method not allowed',
    'method_not_allowed_message' => 'The HTTP method used is not allowed for this page.',
    'csrf_title' => '403 - Forbidden',
    'csrf_message' => 'The form has expired. Reload the page and try again.',
    'too_many_requests_title' => '429 - Too many requests',
    'too_many_requests_message' => 'Too many requests. Please wait and try again.',
    'server_error_title' => '500 - Internal error',
    'server_error_message' => 'Something went wrong on our side. Please try again later.',
    'unavailable_title' => '503 - Unavailable',
    'unavailable_message' => 'The service is temporarily unavailable. Please try again later.',
    'back_home' => 'Back to the home page',
    'json_required' => 'Content-Type must be application/json.',
    'json_invalid' => 'The request body is not valid JSON: :reason',
];
