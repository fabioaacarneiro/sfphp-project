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
     * @throws \InvalidArgumentException When the event name, id or comment holds a line break
     */
    public function send(
        string $data,
        ?string $event = null,
        string|int|null $id = null,
        ?int $retry = null,
        ?string $comment = null
    ): bool {
        $lines = [];

        /*
         * A line break ends a field, so one inside the event name, the id or
         * a comment wrote fields of its own — event: "x\nid: 99" sent an id
         * nobody asked for. Refused, since those three are names, not text.
         */
        foreach (['event' => $event, 'id' => $id === null ? null : (string) $id, 'comment' => $comment] as $field => $value) {
            if ($value !== null && preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('The SSE %s cannot contain a line break.', $field));
            }
        }

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

        /*
         * Data can span lines, and each one gets its own "data:". The spec
         * ends a line at CR, LF or CRLF, so all three split here; splitting
         * on LF alone let a lone CR start a field the browser would read.
         */
        foreach (preg_split('/\r\n|\r|\n/', $data) ?: [$data] as $line) {
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
