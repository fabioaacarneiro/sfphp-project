<?php

namespace SfphpProject\src\Log;

/**
 * Discards every record.
 *
 * For a test that should not write to the terminal, and for a command whose
 * output is the product — a log line in the middle of `./sfphp routes` would
 * corrupt what the user piped it into.
 */
final class NullDriver implements Logger
{
    /**
     * Discard the record.
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Structured detail belonging to the record
     * @return void
     */
    public function write(Level $level, string $message, array $context = []): void
    {
    }
}
