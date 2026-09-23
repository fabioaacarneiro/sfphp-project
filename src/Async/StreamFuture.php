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
     */
    public function reduce(callable $reducer, $initial = null)
    {
        $this->pipe(function ($items) use ($reducer, &$initial) {
            foreach ($items as $item) {
                $initial = $reducer($initial, $item);
            }
            return [$initial];
        });

        return $this->getValue()[0] ?? $initial;
    }

    /**
     * Process stream in chunks
     */
    private function processChunks(): array
    {
        $this->isProcessing = true;
        $results = [];

        try {
            $chunks = $this->getChunks();

            foreach ($chunks as $chunk) {
                $processed = $chunk;

                // Apply all processors in pipeline
                foreach ($this->processors as $processor) {
                    $processed = call_user_func($processor, $processed);
                }

                $results = array_merge($results, $processed);
                $this->processedCount += count($processed);
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
     * Get chunks from source
     */
    private function getChunks(): array
    {
        $chunks = [];
        $chunk = [];

        if (is_callable($this->source)) {
            $source = call_user_func($this->source);
        } else {
            $source = $this->source;
        }

        foreach ($source as $item) {
            $chunk[] = $item;

            if (count($chunk) >= $this->chunkSize) {
                $chunks[] = $chunk;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $chunks[] = $chunk;
        }

        return $chunks;
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
     */
    public static function fromQuery($query, int $chunkSize = 1000): self
    {
        return new self(fn() => $query->cursor(), $chunkSize);
    }

    /**
     * Create from CSV file
     */
    public static function fromCsv(string $filepath, int $chunkSize = 1000): self
    {
        return new self(function () use ($filepath) {
            $handle = fopen($filepath, 'r');
            while (($row = fgetcsv($handle)) !== false) {
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
            $handle = fopen($filepath, 'r');
            while (($line = fgets($handle)) !== false) {
                $data = json_decode(trim($line), true);
                if ($data !== null) {
                    yield $data;
                }
            }
            fclose($handle);
        }, $chunkSize);
    }
}
