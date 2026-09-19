<?php

/**
 * File of HTTP constants and HTTP methods
 *
 * Here are defined the HTTP response status constants
 * and the most commonly used HTTP methods.
 *
 * @package SfphpProject
 * @subpackage src
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

const HTTP_OK = 200;
const HTTP_CREATED = 201;
const HTTP_ACCEPTED = 202;
const HTTP_NO_CONTENT = 204;
const HTTP_MOVED_PERMANENTLY = 301;
const HTTP_FOUND = 302;
const HTTP_SEE_OTHER = 303;
const HTTP_NOT_MODIFIED = 304;
const HTTP_TEMPORARY_REDIRECT = 307;
const HTTP_PERMANENT_REDIRECT = 308;
const HTTP_BAD_REQUEST = 400;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_METHOD_NOT_ALLOWED = 405;
const HTTP_NOT_ACCEPTABLE = 406;
const HTTP_REQUEST_TIMEOUT = 408;
const HTTP_CONFLICT = 409;
const HTTP_GONE = 410;
const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;
const HTTP_UNPROCESSABLE_ENTITY = 422;
const HTTP_TOO_MANY_REQUESTS = 429;
const HTTP_INTERNAL_SERVER_ERROR = 500;
const HTTP_NOT_IMPLEMENTED = 501;
const HTTP_BAD_GATEWAY = 502;
const HTTP_SERVICE_UNAVAILABLE = 503;
const HTTP_GATEWAY_TIMEOUT = 504;

const GET = "GET";
const POST = "POST";
const PUT = "PUT";
const DELETE = "DELETE";
const PATCH = "PATCH";
const HEAD = "HEAD";
const OPTIONS = "OPTIONS";
