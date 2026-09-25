<?php

namespace SfphpProject\src\Auth;

use RuntimeException;
use SfphpProject\src\Http\HttpStatus;

/**
 * Raised when an authenticated user is not allowed to do something.
 *
 * Distinct from being unauthenticated: this means the framework knows who you
 * are and the answer is still no, which is a 403 rather than a 401.
 */
final class AuthorizationException extends RuntimeException implements HttpStatus
{
    public function status(): int
    {
        return 403;
    }
}
