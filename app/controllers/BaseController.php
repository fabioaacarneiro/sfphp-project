<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Response;
use SfphpProject\src\View;

/**
 * Base controller for pages that render HTML.
 *
 * Actions receive the Request as their first argument and return a Response.
 * Reading the request is the Request object's job, so the accessors that used
 * to live here are gone; what remains are the two shortcuts for producing a
 * response.
 *
 * The framework's rule for request data has not changed and is worth
 * restating: validate on the way in, escape on the way out. Request returns
 * values untouched, Validator checks without modifying, QueryBuilder binds
 * every value, and SFHT escapes "{{ }}" automatically.
 */
class BaseController
{
    /**
     * Render a view into an HTML response.
     *
     * @param string $view The view name
     * @param array<string, mixed> $data The data passed to the view
     * @param int $status The HTTP status code
     * @return Response The rendered response
     */
    protected function view(string $view, array $data = [], int $status = HTTP_OK): Response
    {
        return Response::html(View::make($view, $data), $status);
    }

    /**
     * Build a redirect response.
     *
     * This returns rather than sending. An earlier version called exit, which
     * meant nothing after the controller ever ran: no middleware got to see
     * the response on its way out.
     *
     * @param string $url The destination
     * @param int $status The HTTP status code
     * @return Response The redirect response
     */
    protected function redirect(string $url, int $status = HTTP_FOUND): Response
    {
        return Response::redirect($url, $status);
    }
}
