<?php

namespace SfphpProject\src\Async;

/**
 * WebSocket Future — EXPERIMENTAL, and not a working WebSocket client.
 *
 * What it does: open a plain TCP connection to the URL's host and port
 * (port 80 when none is given), settle once that connection is open or has
 * failed, and write text frames to it, masked as RFC 6455 requires of a
 * client. Messages can be queued and flushed, and the connection closed.
 *
 * What it does not do, and why that matters:
 *
 * - **No opening handshake.** It never sends the HTTP `Upgrade: websocket`
 *   request, so a real WebSocket server sees frames arrive on a connection
 *   that never became a WebSocket, and closes it. The headers passed to the
 *   constructor are stored and never sent.
 * - **No TLS.** A `wss://` URL is refused, rather than connected to in the
 *   clear on port 80 as it used to be.
 * - **No receiving.** Nothing reads the socket, so callbacks registered with
 *   onMessage() are never called; there are no ping/pong or close frames.
 * - **Not on the event loop.** Connecting and writing block, so this does not
 *   overlap with other work the way an HTTP request does.
 *
 * It is kept because it is public API and something may construct it; it is
 * not a basis for real-time features. For pushing to browsers, use the
 * framework's server-sent events (see STREAMING.md).
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
            if (parse_url($this->url, PHP_URL_SCHEME) === 'wss') {
                throw new \Exception('WebSocketFuture does not support TLS, so a wss:// URL cannot be opened.');
            }

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
     *
     * Experimental: nothing reads the socket yet, so these callbacks are
     * stored and never called. See the class description.
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
            /*
             * A 64-bit length is eight bytes. This used to write six, holding
             * only the low sixteen bits, so any frame of 64 KiB or more
             * announced the wrong length and corrupted the stream.
             */
            $header = pack('CCJ', 0x81, 0x7F | 0x80, $len);
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
     * Open a connection.
     *
     * Named open() rather than connect() because a class cannot have both an
     * instance method and a static method of the same name, and it had both:
     * the file raised a fatal error the moment PHP parsed it, so the class had
     * never been loaded by anything — it shipped in the package as a file that
     * could not be used.
     *
     * @param string $url The endpoint
     * @param array<string, string> $headers Handshake headers
     * @return self The future
     */
    public static function open(string $url, array $headers = []): self
    {
        $future = new self($url, $headers);
        $future->connect();

        return $future;
    }
}
