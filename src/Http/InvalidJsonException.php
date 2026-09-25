<?php

namespace SfphpProject\src\Http;

use JsonException;

/**
 * A request body that is not the JSON it claimed to be.
 *
 * A JsonException still, so code that catches JsonException keeps working,
 * and a 400 when nothing catches it: the client sent something malformed.
 */
final class InvalidJsonException extends JsonException implements HttpStatus
{
    public function status(): int
    {
        return 400;
    }
}
