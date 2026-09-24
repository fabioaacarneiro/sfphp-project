<?php

namespace SfphpProject\src\Http;

/**
 * The actual implementation of StreamWriter used by Emitter.
 *
 * Handles flushing output buffers, checking connection status, and managing
 * zlib compression to ensure streaming works even with output compression.
 *
 * @internal
 */
final class ResponseStream implements StreamWriter
{
    /** @var callable(StreamWriter): void */
    private readonly mixed $producer;

    /**
     * @param callable(StreamWriter): void $producer The generator function
     */
    public function __construct(callable $producer)
    {
        $this->producer = $producer;
    }

    /**
     * Execute the producer.
     *
     * @return void
     */
    public function execute(): void
    {
        ($this->producer)($this);
    }

    public function write(string $chunk): bool
    {
        if (connection_aborted()) {
            return false;
        }

        echo $chunk;
        $this->flush();

        return true;
    }

    public function aborted(): bool
    {
        return connection_aborted() !== 0;
    }

    public function flush(): void
    {
        // Disable zlib temporary to ensure real flushing
        $level = ob_get_level();

        for ($i = 0; $i < $level; $i++) {
            ob_flush();
        }

        if (function_exists('flush')) {
            flush();
        }
    }
}
