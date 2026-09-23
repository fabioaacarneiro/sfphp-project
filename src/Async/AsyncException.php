<?php

namespace SfphpProject\src\Async;

use RuntimeException;

/**
 * Something went wrong in the async runtime itself.
 *
 * Reading a value that has not arrived, a scheduler with nothing left that
 * could wake its tasks: failures of the machinery rather than of the work it
 * was carrying.
 */
class AsyncException extends RuntimeException
{
}
