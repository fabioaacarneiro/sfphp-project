<?php

/**
 * Messages the framework itself sends to a visitor.
 *
 * An application overrides any of these by adding a file with the same name
 * under its own lang directory; only the keys it declares are replaced.
 */

return [
    'not_found_title' => '404 - Page Not Found',
    'not_found_message' => 'Sorry, the page you are looking for was not found.',
    'method_not_allowed_title' => '405 - Method Not Allowed',
    'method_not_allowed_message' => 'The HTTP method used is not allowed for this page.',
    'csrf_title' => '403 - Forbidden',
    'csrf_message' => 'The form has expired. Reload the page and try again.',
    'server_error_title' => 'Internal error',
    'server_error_message' => 'Internal Server Error',
    'too_many_requests_message' => 'Too many requests. Please wait and try again.',
    'back_home' => 'Back to the home page',
];
