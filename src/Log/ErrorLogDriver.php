<?php

namespace SfphpProject\src\Log;

/**
 * Writes through PHP's own error_log().
 *
 * For a deployment where something already collects what PHP reports — a
 * shared host, or a php-fpm pool whose error log is shipped somewhere — this
 * puts the structured record into that same stream instead of opening a second
 * destination nobody is watching.
 *
 * The record is still JSON: the point of structured logging is that a program
 * can read it, and that does not change because of where it lands.
 */
final class ErrorLogDriver implements Logger
{
    /**
     * Write one record through error_log().
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Structured detail belonging to the record
     * @return void
     */
    public function write(Level $level, string $message, array $context = []): void
    {
        $record = [
            'level' => $level->value,
            'message' => $message,
        ];

        if ($context !== []) {
            $record['context'] = $context;
        }

        /*
         * No timestamp: error_log() puts its own in front of every line, and a
         * second one would be both redundant and, where the two disagree about
         * the time zone, confusing.
         */
        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        error_log($line === false ? $level->value . ': ' . $message : $line);
    }
}
