<?php

namespace SfphpProject\src\Log;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Writes one JSON object per line to a stream.
 *
 * JSON lines rather than a sentence, because a log line is read by a program
 * before it is read by a person: every collector can parse this, and a field
 * can be filtered on without a regular expression that breaks the first time a
 * message contains a colon.
 *
 * The default destination is stderr. That is the right default for a container
 * and for the PHP development server, it needs no directory to exist and no
 * permission to be granted, and it keeps the framework from deciding where a
 * deployment's logs should live. A path can be given instead.
 *
 * Timestamps are UTC. A distributed system whose log lines carry local time
 * cannot be put in order, and the moment there are two machines the ordering is
 * the only thing that makes the logs worth having.
 */
final class StreamDriver implements Logger
{
    /** @var resource|null */
    private $handle = null;

    private bool $lockable;

    /**
     * Create the driver.
     *
     * The stream is opened on the first record rather than in the constructor,
     * so building a logger that is never used cannot fail, and so a worker that
     * forks does not inherit a handle it shares with its parent.
     *
     * @param string $destination A stream URI or file path
     */
    public function __construct(private string $destination = 'php://stderr')
    {
        /*
         * Locking a file keeps two processes from interleaving halves of a line.
         * php://stderr and php://stdout cannot be locked and do not need to be:
         * the operating system already writes a single small write atomically to
         * a pipe, and asking for a lock there only produces a warning.
         */
        $this->lockable = !str_starts_with($destination, 'php://');
    }

    /**
     * Write one record as a line of JSON.
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Structured detail belonging to the record
     * @return void
     * @throws RuntimeException When the destination cannot be opened
     */
    public function write(Level $level, string $message, array $context = []): void
    {
        $record = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level->value,
            'message' => $message,
        ];

        if ($context !== []) {
            $record['context'] = $context;
        }

        /*
         * A record must never be the reason a request fails, so a value that
         * cannot be encoded — a resource, a closure, a recursive structure —
         * degrades to a line saying so rather than throwing from inside
         * whatever was being logged.
         */
        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($line === false) {
            $line = json_encode([
                'timestamp' => $record['timestamp'],
                'level' => $level->value,
                'message' => $message,
                'context_error' => json_last_error_msg(),
            ]);
        }

        $this->writeLine($line . PHP_EOL);
    }

    /** Whether the fallback to error_log() has been announced. */
    private bool $warnedFallback = false;

    /**
     * Put one already-encoded line on the stream.
     *
     * @param string $line The line, newline included
     * @return void
     * @throws RuntimeException When the destination cannot be opened
     */
    private function writeLine(string $line): void
    {
        /*
         * A destination that cannot be opened — LOG_PATH pointing at a
         * directory the web server may not write — used to throw from here,
         * and since every request logs, every request became a 500 over a
         * log line. The line goes to PHP's own error log instead, with a
         * note the first time saying why.
         */
        try {
            $handle = $this->handle();
        } catch (RuntimeException $exception) {
            if (!$this->warnedFallback) {
                $this->warnedFallback = true;
                error_log('sfphp: ' . $exception->getMessage() . ' Writing to the PHP error log instead.');
            }

            error_log(rtrim($line, "\n"));

            return;
        }

        if ($this->lockable) {
            flock($handle, LOCK_EX);
        }

        fwrite($handle, $line);

        if ($this->lockable) {
            fflush($handle);
            flock($handle, LOCK_UN);
        }
    }

    /**
     * The open stream, opening it the first time it is needed.
     *
     * @return resource The stream handle
     * @throws RuntimeException When the destination cannot be opened
     */
    private function handle()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        $directory = dirname($this->destination);

        if ($this->lockable && !is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = @fopen($this->destination, 'a');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the log destination ' . $this->destination . '.');
        }

        $this->handle = $handle;

        return $handle;
    }

    /**
     * Close the stream, if one was opened.
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->handle) && $this->lockable) {
            fclose($this->handle);
        }

        $this->handle = null;
    }
}
