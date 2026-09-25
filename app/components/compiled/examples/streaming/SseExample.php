<?php

namespace SfphpProject\app\components\examples\streaming;

use SfphpProject\src\View\Sfht;

/**
 * Example of @sse directive in action
 */
function SseExample(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<div class="card">
            <div class="card-header">
                <h2 class="text-lg font-bold">Server-Sent Events ('; echo '@sse'; echo ')</h2>
                <p class="text-sm text-gray-600 mt-1">Real-time event delivery</p>
            </div>

            <div class="card-body">
                <p class="text-sm mb-4">
                    Listen to named events from the server (perfect for notifications, live updates, or progress).
                </p>

                <div
                    id="sse-output"
                    class="bg-white border border-gray-300 rounded p-4 font-mono text-sm min-h-40 max-h-64 overflow-y-auto whitespace-pre-wrap break-words"
                ><span class="text-muted">Click "Connect" to receive events...</span></div>

                <button
                    class="btn btn-success w-full mt-4"
                    '; echo '@stream'; echo '="/stream/sse"
                    '; echo '@sse'; echo '
                    '; echo '@events'; echo '="status,progress,data,complete"
                    '; echo '@target'; echo '="#sse-output"
                >
                    Connect to Events
                </button>
            </div>

            <div class="card-footer text-xs">
                <p><strong>HTML:</strong></p>
                <code class="block bg-gray-50 p-2 rounded mt-2 overflow-x-auto">
&lt;button '; echo '@stream'; echo '="/events" '; echo '@sse'; echo ' '; echo '@events'; echo '="progress,complete" '; echo '@target'; echo '="#output"&gt;
  Connect
&lt;/button&gt;
                </code>
            </div>
        </div>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
