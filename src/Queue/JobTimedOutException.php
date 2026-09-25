<?php

namespace SfphpProject\src\Queue;

/**
 * Thrown into a job that ran longer than its timeout().
 *
 * A dedicated type so a failed-jobs listing, or a job's own error handling,
 * can tell a timeout from an error the job raised itself.
 */
final class JobTimedOutException extends \RuntimeException
{
}
