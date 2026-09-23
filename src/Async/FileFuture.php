<?php

namespace SfphpProject\src\Async;

/**
 * File Future for async file I/O operations
 *
 * Handles file operations without blocking: read, write, delete, etc.
 */
class FileFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private array $callbacks = [];

    private string $operation;
    private string $filepath;
    private mixed $data;
    private array $options;

    /**
     * Create a file future
     *
     * @param string $operation read|write|delete|copy|move|exists|size
     * @param string $filepath File path
     * @param mixed $data Data for write operation
     * @param array $options Additional options
     */
    public function __construct(
        string $operation,
        string $filepath,
        mixed $data = null,
        array $options = []
    ) {
        $this->operation = strtolower($operation);
        $this->filepath = $filepath;
        $this->data = $data;
        $this->options = $options;
    }

    /**
     * Execute the file operation
     */
    private function execute(): void
    {
        if ($this->executed) {
            return;
        }

        try {
            $this->result = $this->performOperation();
            $this->executed = true;
            $this->notifyCallbacks();
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
        }
    }

    /**
     * Perform the actual file operation
     */
    private function performOperation(): mixed
    {
        return match ($this->operation) {
            'read' => $this->readFile(),
            'write' => $this->writeFile(),
            'append' => $this->appendFile(),
            'delete', 'unlink' => $this->deleteFile(),
            'copy' => $this->copyFile(),
            'move', 'rename' => $this->moveFile(),
            'exists' => $this->fileExists(),
            'size' => $this->fileSize(),
            'type' => $this->fileType(),
            'mkdir' => $this->createDirectory(),
            'rmdir' => $this->removeDirectory(),
            'list', 'scandir' => $this->listDirectory(),
            default => throw new \Exception("Unknown file operation: {$this->operation}"),
        };
    }

    /**
     * Read file content
     */
    private function readFile(): string
    {
        if (!file_exists($this->filepath)) {
            throw new \Exception("File not found: {$this->filepath}");
        }

        $content = file_get_contents($this->filepath);
        if ($content === false) {
            throw new \Exception("Failed to read file: {$this->filepath}");
        }

        return $content;
    }

    /**
     * Write content to file
     */
    private function writeFile(): int
    {
        $bytes = file_put_contents($this->filepath, $this->data);
        if ($bytes === false) {
            throw new \Exception("Failed to write file: {$this->filepath}");
        }
        return $bytes;
    }

    /**
     * Append content to file
     */
    private function appendFile(): int
    {
        $bytes = file_put_contents($this->filepath, $this->data, FILE_APPEND);
        if ($bytes === false) {
            throw new \Exception("Failed to append to file: {$this->filepath}");
        }
        return $bytes;
    }

    /**
     * Delete file
     */
    private function deleteFile(): bool
    {
        if (!file_exists($this->filepath)) {
            return false;
        }

        if (!unlink($this->filepath)) {
            throw new \Exception("Failed to delete file: {$this->filepath}");
        }

        return true;
    }

    /**
     * Copy file
     */
    private function copyFile(): bool
    {
        $destination = $this->data;
        if (!copy($this->filepath, $destination)) {
            throw new \Exception("Failed to copy file from {$this->filepath} to $destination");
        }
        return true;
    }

    /**
     * Move/rename file
     */
    private function moveFile(): bool
    {
        $destination = $this->data;
        if (!rename($this->filepath, $destination)) {
            throw new \Exception("Failed to move file from {$this->filepath} to $destination");
        }
        return true;
    }

    /**
     * Check if file exists
     */
    private function fileExists(): bool
    {
        return file_exists($this->filepath);
    }

    /**
     * Get file size
     */
    private function fileSize(): int
    {
        if (!file_exists($this->filepath)) {
            return 0;
        }
        return filesize($this->filepath);
    }

    /**
     * Get file type
     */
    private function fileType(): string
    {
        if (!file_exists($this->filepath)) {
            return 'unknown';
        }
        return filetype($this->filepath);
    }

    /**
     * Create directory
     */
    private function createDirectory(): bool
    {
        $mode = $this->options['mode'] ?? 0755;
        $recursive = $this->options['recursive'] ?? true;

        if (!mkdir($this->filepath, $mode, $recursive)) {
            throw new \Exception("Failed to create directory: {$this->filepath}");
        }

        return true;
    }

    /**
     * Remove directory
     */
    private function removeDirectory(): bool
    {
        if (!rmdir($this->filepath)) {
            throw new \Exception("Failed to remove directory: {$this->filepath}");
        }
        return true;
    }

    /**
     * List directory contents
     */
    private function listDirectory(): array
    {
        $files = scandir($this->filepath);
        if ($files === false) {
            throw new \Exception("Failed to scan directory: {$this->filepath}");
        }

        // Remove . and ..
        return array_diff($files, ['.', '..']);
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
            $this->execute();
        }

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        if (!$this->executed) {
            $this->execute();
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
     * Static factory methods
     */

    public static function read(string $filepath): self
    {
        return new self('read', $filepath);
    }

    public static function write(string $filepath, mixed $content): self
    {
        return new self('write', $filepath, $content);
    }

    public static function append(string $filepath, mixed $content): self
    {
        return new self('append', $filepath, $content);
    }

    public static function delete(string $filepath): self
    {
        return new self('delete', $filepath);
    }

    public static function copy(string $source, string $destination): self
    {
        return new self('copy', $source, $destination);
    }

    public static function move(string $source, string $destination): self
    {
        return new self('move', $source, $destination);
    }

    public static function exists(string $filepath): self
    {
        return new self('exists', $filepath);
    }

    public static function size(string $filepath): self
    {
        return new self('size', $filepath);
    }

    public static function mkdir(string $path, array $options = []): self
    {
        return new self('mkdir', $path, null, $options);
    }

    public static function scan(string $path): self
    {
        return new self('list', $path);
    }
}
