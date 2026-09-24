<?php

namespace SfphpProject\src\Http;

/**
 * Formats and sends Server-Sent Events (SSE) to the client.
 *
 * SSE is a standardized way to stream events from server to client over HTTP.
 * Each event can have data, event name, id, and retry timing.
 *
 * Usage:
 *     return Response::stream(function(StreamWriter $out) {
 *         $sse = new ServerSentEvent($out);
 *         $sse->send('Processing...', event: 'status');
 *         $sse->send('More data...', id: '1');
 *     });
 */
final class ServerSentEvent
{
    public function __construct(private readonly StreamWriter $out) {}

    /**
     * Send an event to the client.
     *
     * @param string $data The event data (can contain multiple lines)
     * @param string|null $event The event type (defaults to 'message')
     * @param string|int|null $id The event id for reconnection
     * @param int|null $retry Milliseconds before client retries on disconnect
     * @param string|null $comment Optional comment (starts with ':')
     * @return bool True if sent, false if client aborted
     */
    public function send(
        string $data,
        ?string $event = null,
        string|int|null $id = null,
        ?int $retry = null,
        ?string $comment = null
    ): bool {
        $lines = [];

        if ($comment !== null) {
            $lines[] = ': ' . $comment;
        }

        if ($event !== null) {
            $lines[] = 'event: ' . $event;
        }

        if ($id !== null) {
            $lines[] = 'id: ' . $id;
        }

        if ($retry !== null) {
            $lines[] = 'retry: ' . $retry;
        }

        // Data can span multiple lines; each line starts with 'data: '
        foreach (explode("\n", $data) as $line) {
            $lines[] = 'data: ' . $line;
        }

        // Empty line marks end of event
        $lines[] = '';

        return $this->out->write(implode("\n", $lines) . "\n");
    }

    /**
     * Send a heartbeat comment to keep the connection alive.
     *
     * @return bool True if sent, false if client aborted
     */
    public function heartbeat(): bool
    {
        return $this->out->write(": heartbeat\n");
    }
}
