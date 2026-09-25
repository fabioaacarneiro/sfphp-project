<?php

namespace SfphpProject\src\Http;

use RuntimeException;
use Throwable;

/**
 * Stop handling a request and answer with a status.
 *
 *     throw new HttpException(404);
 *     throw new HttpException(409, 'That slug is taken.');
 *
 * The message is shown to the visitor only for a 4xx: it describes what the
 * client did. A 5xx shows the generic message outside development, as every
 * other server failure does.
 */
class HttpException extends RuntimeException implements HttpStatus
{
    /**
     * @param int $status The status code, 400 to 599
     * @param string $message What went wrong, for the visitor
     * @param Throwable|null $previous The failure that caused it
     */
    public function __construct(private int $status, string $message = '', ?Throwable $previous = null)
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('HttpException needs a 4xx or 5xx status, not %d.', $status));
        }

        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }
}
