<?php

namespace SfphpProject\src\Async;

/**
 * Base exception for async operations
 */
class AsyncException extends \RuntimeException
{
}

/**
 * Thrown when an async operation exceeds its timeout
 */
class TimeoutException extends AsyncException
{
}

/**
 * Thrown when an async operation is cancelled
 */
class CancelledException extends AsyncException
{
}
