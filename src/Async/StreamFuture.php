<?php

namespace SfphpProject\src\Async;

/**
 * Stream Future for processing large datasets asynchronously
 *
 * Handles batch processing, chunking, and streaming operations
 * without loading entire dataset into memory.
 */
class StreamFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];

    private $source;
    private int $chunkSize = 1000;
    private array $processors = [];
    private bool $isProcessing = false;
    private int $processedCount = 0;

    /**
     * Create a stream future from source
     *
     * @param mixed $source Source (iterable, generator, callable, etc)
     * @param int $chunkSize Items per chunk
     */
    public function __construct($source, int $chunkSize = 1000)
    {
        $this->source = $source;
        $this->chunkSize = $chunkSize;
    }

    /**
     * Add a processor function to the pipeline
     */
    public function pipe(callable $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * Filter items in stream
     */
    public function filter(callable $predicate): self
    {
        return $this->pipe(function ($items) use ($predicate) {
            return array_filter($items, $predicate);
        });
    }

    /**
     * Map items in stream
     */
    public function map(callable $transformer): self
    {
        return $this->pipe(function ($items) use ($transformer) {
            return array_map($transformer, $items);
        });
    }

    /**
     * Reduce stream to single value
     *
     * The pipeline built so far runs chunk by chunk and the reducer folds
     * every item into one value, carried from each chunk to the next. It
     * used to be added as one more pipe stage returning `[$total]` per chunk;
     * the stage results were then concatenated and the first one returned,
     * which was the total after the first chunk only — range(1, 10) in
     * chunks of 3 reduced to 6, not 55.
     *
     * This is a terminal operation: it does not change the pipeline, so the
     * stream can still be read with getValue() afterwards.
     */
    public function reduce(callable $reducer, $initial = null)
    {
        $carry = $initial;

        foreach ($this->processedChunks() as $items) {
            foreach ($items as $item) {
                $carry = $reducer($carry, $item);
            }
        }

        return $carry;
    }

    /**
     * Process stream in chunks
     */
    private function processChunks(): array
    {
        $this->isProcessing = true;
        $results = [];

        try {
            foreach ($this->processedChunks() as $processed) {
                /*
                 * array_values, because filter() keeps the keys array_filter
                 * left, and merging chunks whose keys restart at zero would
                 * otherwise interleave them unpredictably.
                 */
                array_push($results, ...array_values($processed));
            }

            $this->result = $results;
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
        } finally {
            $this->isProcessing = false;
        }

        return $results;
    }

    /**
     * Run each chunk through the pipeline, one chunk at a time.
     *
     * @return \Generator<int, array> The processed chunks
     */
    private function processedChunks(): \Generator
    {
        foreach ($this->getChunks() as $chunk) {
            $processed = $chunk;

            // Apply all processors in pipeline
            foreach ($this->processors as $processor) {
                $processed = call_user_func($processor, $processed);
            }

            $this->processedCount += count($processed);

            yield $processed;
        }
    }

    /**
     * Get chunks from source
     *
     * A generator, so that only one chunk of the source is held at a time
     * while the pipeline runs. getValue() still collects every processed item
     * into its result; reduce() does not, and holds only the running value.
     *
     * @return \Generator<int, array> The chunks
     */
    private function getChunks(): \Generator
    {
        $chunk = [];

        if (is_callable($this->source)) {
            $source = call_user_func($this->source);
        } else {
            $source = $this->source;
        }

        foreach ($source as $item) {
            $chunk[] = $item;

            if (count($chunk) >= $this->chunkSize) {
                yield $chunk;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    /**
     * Get count of processed items
     */
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    /**
     * Check if processing
     */
    public function isProcessing(): bool
    {
        return $this->isProcessing;
    }

    // Future interface implementation

    public function isPending(): bool
    {
        return !$this->executed;
    }

    public function isResolved(): bool
    {
        return $this->executed && $this->exception === null;
    }

    public function isRejected(): bool
    {
        return $this->exception !== null;
    }

    public function getValue()
    {
        if (!$this->executed) {
            $this->processChunks();
        }

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        if (!$this->executed) {
            $this->processChunks();
        }
        return $this->exception;
    }

    public function onResolve(callable $callback): void
    {
        if ($this->executed) {
            $callback($this);
        } else {
            $this->callbacks[] = $callback;
        }
    }

    /**
     * Notify all callbacks
     */
    private function notifyCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                // Ignore callback errors
            }
        }
        $this->callbacks = [];
    }

    /**
     * Create from database query
     *
     * SFPHP's ModelQuery and QueryBuilder have no cursor, so the rows are
     * fetched with get() in one query when the stream is first read, and the
     * chunk size then bounds how many rows each pipeline stage handles at a
     * time — not how many are in memory. This used to call `cursor()`, which
     * no framework query has, so it failed on every framework query. A query
     * object from elsewhere that does offer `cursor()` is still read through it.
     */
    public static function fromQuery($query, int $chunkSize = 1000): self
    {
        return new self(
            fn() => method_exists($query, 'cursor') ? $query->cursor() : $query->get(),
            $chunkSize
        );
    }

    /**
     * Create from CSV file
     */
    public static function fromCsv(string $filepath, int $chunkSize = 1000): self
    {
        return new self(function () use ($filepath) {
            $handle = self::open($filepath);

            // The escape character is passed explicitly: PHP 8.4 deprecates relying on its default.
            while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                yield $row;
            }
            fclose($handle);
        }, $chunkSize);
    }

    /**
     * Create from JSON file (line-delimited)
     */
    public static function fromJsonLines(string $filepath, int $chunkSize = 1000): self
    {
        return new self(function () use ($filepath) {
            $handle = self::open($filepath);
            while (($line = fgets($handle)) !== false) {
                $data = json_decode(trim($line), true);
                if ($data !== null) {
                    yield $data;
                }
            }
            fclose($handle);
        }, $chunkSize);
    }

    /**
     * Open a file for reading, or say why it could not be.
     *
     * Without this, a missing file reached fgetcsv() as `false` and failed
     * with a TypeError that named neither the file nor the problem.
     *
     * @return resource The handle
     */
    private static function open(string $filepath)
    {
        $handle = is_file($filepath) ? @fopen($filepath, 'r') : false;

        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for streaming: {$filepath}");
        }

        return $handle;
    }
}
