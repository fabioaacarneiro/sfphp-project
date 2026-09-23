<?php

namespace SfphpProject\src\Async;

/**
 * WebSocket Future for async real-time communication
 *
 * Handles WebSocket connections asynchronously with message queuing
 * and event-driven callbacks.
 */
class WebSocketFuture implements Future
{
    private mixed $result = null;
    private ?\Throwable $exception = null;
    private bool $executed = false;
    private bool $connected = false;
    private array $callbacks = [];
    private array $messageQueue = [];
    private array $listeners = [];

    private string $url;
    private array $headers;
    private $connection;

    /**
     * Create a WebSocket future
     *
     * @param string $url WebSocket URL (ws:// or wss://)
     * @param array $headers Custom headers for connection
     */
    public function __construct(string $url, array $headers = [])
    {
        $this->url = $url;
        $this->headers = $headers;
    }

    /**
     * Connect to WebSocket
     */
    public function connect(): bool
    {
        if ($this->executed) {
            return $this->connected;
        }

        try {
            $this->connection = fsockopen(
                parse_url($this->url, PHP_URL_HOST),
                parse_url($this->url, PHP_URL_PORT) ?: 80,
                $errno,
                $errstr,
                5
            );

            if (!$this->connection) {
                throw new \Exception("WebSocket connection failed: $errstr");
            }

            $this->connected = true;
            $this->executed = true;
            $this->result = ['status' => 'connected', 'url' => $this->url];
            $this->notifyCallbacks();

            return true;
        } catch (\Throwable $e) {
            $this->exception = $e;
            $this->executed = true;
            $this->notifyCallbacks();
            return false;
        }
    }

    /**
     * Send message through WebSocket
     */
    public function send(string $message): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $frame = $this->createFrame($message);
            fwrite($this->connection, $frame);
            return true;
        } catch (\Throwable $e) {
            $this->exception = $e;
            return false;
        }
    }

    /**
     * Listen for WebSocket messages
     */
    public function onMessage(callable $callback): self
    {
        $this->listeners[] = $callback;
        return $this;
    }

    /**
     * Broadcast message to all listeners
     */
    private function broadcastMessage(string $message): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($message);
            } catch (\Throwable $e) {
                // Ignore listener errors
            }
        }
    }

    /**
     * Create WebSocket frame
     */
    private function createFrame(string $message): string
    {
        $len = strlen($message);

        if ($len < 126) {
            $header = pack('CC', 0x81, 0x80 | $len);
        } elseif ($len < 65536) {
            $header = pack('CCn', 0x81, 0xFE | 0x80, $len);
        } else {
            $header = pack('CCCCCCCC', 0x81, 0xFF | 0x80, 0, 0, 0, 0, ($len >> 8) & 0xFF, $len & 0xFF);
        }

        $mask = pack('N', random_int(0, 0xFFFFFFFF));
        $masked = '';

        for ($i = 0; $i < $len; $i++) {
            $masked .= $message[$i] ^ $mask[$i % 4];
        }

        return $header . $mask . $masked;
    }

    /**
     * Disconnect from WebSocket
     */
    public function disconnect(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            if ($this->connection) {
                fclose($this->connection);
            }
            $this->connected = false;
            return true;
        } catch (\Throwable $e) {
            $this->exception = $e;
            return false;
        }
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Queue message (for batch processing)
     */
    public function queueMessage(string $message): self
    {
        $this->messageQueue[] = $message;
        return $this;
    }

    /**
     * Send all queued messages
     */
    public function flushQueue(): int
    {
        $sent = 0;
        foreach ($this->messageQueue as $message) {
            if ($this->send($message)) {
                $sent++;
            }
        }
        $this->messageQueue = [];
        return $sent;
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
            $this->connect();
        }

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function getException(): ?\Throwable
    {
        if (!$this->executed) {
            $this->connect();
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
     * Static factory method
     */
    public static function connect(string $url, array $headers = []): self
    {
        $future = new self($url, $headers);
        $future->connect();
        return $future;
    }
}
