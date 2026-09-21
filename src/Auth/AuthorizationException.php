<?php

namespace SfphpProject\src\Auth;

use RuntimeException;

/**
 * Raised when an authenticated user is not allowed to do something.
 *
 * Distinct from being unauthenticated: this means the framework knows who you
 * are and the answer is still no, which is a 403 rather than a 401.
 */
final class AuthorizationException extends RuntimeException {}
