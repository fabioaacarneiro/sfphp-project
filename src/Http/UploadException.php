<?php

namespace SfphpProject\src\Http;

use RuntimeException;

/**
 * An uploaded file was refused.
 *
 * Thrown by the assertions on UploadedFile and by store(). It is a refusal
 * rather than a failure: the message says which rule the file broke, and the
 * caller decides whether that is a 422 with a form error or a 500.
 */
final class UploadException extends RuntimeException
{
}
