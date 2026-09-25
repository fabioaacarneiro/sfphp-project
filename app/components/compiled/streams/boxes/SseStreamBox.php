<?php

namespace SfphpProject\app\components\streams\boxes;

use SfphpProject\src\View\Sfht;

/**
 * Demonstrates Server-Sent Events (SSE).
 * When the button is clicked, it connects to /stream/sse and displays
 * events as they arrive.
 */
function SseStreamBox(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<div class="card">
            <div class="card-header">
                <h2 class="text-lg font-bold">Server-Sent Events</h2>
                <p class="text-sm text-gray-600 mt-1">Real-time event delivery</p>
            </div>

            <div class="card-body">
                <p class="text-sm mb-4">
                    Server sends named events that you can listen to.
                    Each event includes data, with heartbeats to keep connection alive.
                </p>

                <div
                    id="sse-output"
                    class="bg-white border border-gray-300 rounded p-4 mb-4 font-mono text-sm min-h-32 max-h-64 overflow-y-auto whitespace-pre-wrap break-words"
                ><span class="text-muted">Events appear here...</span></div>

                <button
                    class="btn btn-success w-full"
                    '; echo '@stream'; echo '="/stream/sse"
                    '; echo '@sse'; echo '
                    '; echo '@events'; echo '="status,progress,data,complete"
                    '; echo '@target'; echo '="#sse-output"
                >
                    Connect to Events
                </button>
            </div>

            <div class="card-footer text-xs text-gray-600">
                <p><strong>How it works:</strong></p>
                <p>• Server sends text/event-stream response</p>
                <p>• Named events (status, progress, data, etc)</p>
                <p>• Perfect for live updates, notifications, logs</p>
            </div>
        </div>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
