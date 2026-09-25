<?php

namespace SfphpProject\src\Http;

/**
 * An exception that knows which HTTP status it answers with.
 *
 * The error handler used to turn every uncaught exception into a 500, so a
 * record that does not exist, a user who may not do something and a body that
 * is not JSON all looked like the server had broken. An exception that
 * implements this is answered with its own status instead.
 */
interface HttpStatus
{
    /**
     * The status code to answer with.
     *
     * @return int A 4xx or 5xx code
     */
    public function status(): int;
}
