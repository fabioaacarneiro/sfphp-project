<?php

namespace SfphpProject\src\Log;

/**
 * Somewhere a log record can be written.
 *
 * One method, on purpose. A driver's whole job is to put a record somewhere;
 * the convenience of info() and error(), the level filtering and the shared
 * context all live in LogManager, so a new destination is one short class
 * rather than eight near-identical methods.
 */
interface Logger
{
    /**
     * Write one record.
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Structured detail belonging to the record
     * @return void
     */
    public function write(Level $level, string $message, array $context = []): void;
}
