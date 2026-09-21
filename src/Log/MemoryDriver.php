<?php

namespace SfphpProject\src\Log;

/**
 * Keeps records in memory so a test can assert on them.
 *
 * Logging is the one subsystem whose output is normally invisible to the code
 * that produced it, which is exactly why it goes untested. This makes the
 * records a value a test can read.
 */
final class MemoryDriver implements Logger
{
    /** @var list<array{level: Level, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * Keep the record.
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Structured detail belonging to the record
     * @return void
     */
    public function write(Level $level, string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Every record written so far.
     *
     * @return list<array{level: Level, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * The most recent record, or null when nothing was written.
     *
     * @return array{level: Level, message: string, context: array<string, mixed>}|null
     */
    public function last(): ?array
    {
        return $this->records === [] ? null : $this->records[array_key_last($this->records)];
    }

    /**
     * Forget every record.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->records = [];
    }
}
