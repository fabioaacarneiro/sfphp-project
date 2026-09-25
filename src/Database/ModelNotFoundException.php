<?php

namespace SfphpProject\src\Database;

use RuntimeException;
use SfphpProject\src\Http\HttpStatus;

/**
 * No row matched a lookup that required one.
 *
 * findOrFail() throws this, and the error handler answers it with 404: the
 * thing the URL names does not exist. It used to be a plain RuntimeException,
 * so every missing record became a 500.
 */
final class ModelNotFoundException extends RuntimeException implements HttpStatus
{
    public function status(): int
    {
        return 404;
    }
}
