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
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card">
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
                    class="bg-white border border-gray-300 rounded p-4 mb-4 font-mono text-sm min-h-32 max-h-64 overflow-y-auto"
                >
                    <span class="text-gray-400">Events appear here...</span>
                </div>

                <button
                    class="btn btn-success w-full"
                    ';
echo '@sse';
echo '="/stream/sse"
                    ';
echo '@events';
echo '="progress,complete,status"
                    onclick="startSSE()"
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
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}

/**
 * Handle SSE connection and display events.
 * This is intentionally verbose so you can see what's happening.
 */
?>

<script>
function startSSE() {
    const output = document.getElementById('sse-output');
    output.innerHTML = '<span class="text-blue-600">Connecting...</span>';

    const es = new EventSource('/stream/sse');

    es.addEventListener('status', (e) => {
        addEvent(output, 'status', e.data);
    });

    es.addEventListener('progress', (e) => {
        addEvent(output, 'progress', e.data);
    });

    es.addEventListener('data', (e) => {
        addEvent(output, 'data', e.data);
    });

    es.addEventListener('complete', (e) => {
        addEvent(output, 'complete', e.data);
        es.close();
    });

    es.onerror = () => {
        addEvent(output, 'error', 'Connection closed or error');
        es.close();
    };
}

function addEvent(container, eventName, data) {
    const time = new Date().toLocaleTimeString();
    const line = document.createElement('div');
    line.className = getEventClass(eventName);
    line.textContent = `[${time}] ${eventName}: ${data}`;

    if (container.firstChild?.classList?.contains('text-gray-400')) {
        container.innerHTML = '';
    }

    container.appendChild(line);
    container.scrollTop = container.scrollHeight;
}

function getEventClass(eventName) {
    const classes = {
        'status': 'text-blue-600 font-bold',
        'progress': 'text-green-600',
        'data': 'text-purple-600',
        'complete': 'text-indigo-600 font-bold',
        'error': 'text-red-600 font-bold'
    };
    return classes[eventName] || 'text-gray-700';
}
</script>
