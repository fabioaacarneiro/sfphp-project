<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\ServerSentEvent;
use SfphpProject\src\Http\StreamWriter;

use function SfphpProject\app\components\streams\StreamsPage;

/**
 * Demo controller showing server-to-client streaming.
 */
final class StreamController
{
    /**
     * Show the demonstration page with live examples.
     *
     * @return Response The page
     */
    public function index(): Response
    {
        return Response::html((string) StreamsPage());
    }

    /**
     * Stream text chunks (e.g., AI-generated text token by token).
     *
     * Curl with: curl -N http://localhost:8000/stream
     * The -N flag disables buffering to show chunks arriving over time.
     */
    public function text(): Response
    {
        return Response::stream(
            function (StreamWriter $out): void {
                $out->write("<html><body><h1>Streaming Text</h1>\n");
                $out->write("<p>This demonstrates server-to-client streaming:</p>\n");
                $out->write("<pre>\n");

                // Simulate text generation (e.g., LLM output)
                for ($i = 1; $i <= 10; $i++) {
                    if ($out->aborted()) {
                        break;
                    }

                    $out->write("Chunk $i: Processing line " . ($i * 10) . "%...\n");
                    usleep(200000); // 200ms delay
                }

                $out->write("\nDone!\n");
                $out->write("</pre></body></html>\n");
            },
            status: 200,
            headers: ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * Stream Server-Sent Events (SSE).
     *
     * Curl with: curl -N http://localhost:8000/stream/sse
     * Browser: let es = new EventSource('/stream/sse'); es.onmessage = ...
     */
    public function sse(): Response
    {
        return Response::stream(
            function (StreamWriter $out): void {
                $sse = new ServerSentEvent($out);

                $sse->send('Event stream started', event: 'status');

                for ($i = 1; $i <= 5; $i++) {
                    if ($out->aborted()) {
                        break;
                    }

                    // Send progress event
                    $sse->send("Progress: $i/5", event: 'progress', id: (string) $i);

                    // Optionally send data event
                    if ($i % 2 === 0) {
                        $sse->send("Data update #{$i}", event: 'data');
                    }

                    // Keep connection alive with heartbeat
                    if ($i % 3 === 0) {
                        $sse->heartbeat();
                    }

                    usleep(500000); // 500ms delay
                }

                $sse->send('All done!', event: 'complete');
            },
            status: 200,
            headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
        );
    }
}
