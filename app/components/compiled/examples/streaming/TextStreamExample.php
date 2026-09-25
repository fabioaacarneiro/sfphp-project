<?php

namespace SfphpProject\app\components\examples\streaming;

use SfphpProject\src\View\Sfht;

/**
 * Example of @stream directive in action
 */
function TextStreamExample(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card">
            <div class="card-header">
                <h2 class="text-lg font-bold">Text Streaming (';
echo '@stream';
echo ')</h2>
                <p class="text-sm text-gray-600 mt-1">Progressive text delivery</p>
            </div>

            <div class="card-body">
                <p class="text-sm mb-4">
                    Stream text chunks progressively (perfect for AI responses, logs, or any long text).
                </p>

                <div class="bg-white border border-gray-300 rounded p-4 font-mono text-sm min-h-40 max-h-64 overflow-y-auto whitespace-pre-wrap break-words mb-4">
                    <span class="text-gray-400" id="stream-output">Click "Start Stream" to see chunks arrive...</span>
                </div>

                <button
                    class="btn btn-primary w-full"
                    ';
echo '@stream';
echo '="/stream"
                    ';
echo '@target';
echo '="#stream-output"
                    ';
echo '@swap';
echo '="innerHTML"
                >
                    Start Stream
                </button>
            </div>

            <div class="card-footer text-xs">
                <p><strong>HTML:</strong></p>
                <code class="block bg-gray-50 p-2 rounded mt-2 overflow-x-auto">
&lt;button ';
echo '@stream';
echo '="/stream" ';
echo '@target';
echo '="#output" ';
echo '@swap';
echo '="innerHTML"&gt;
  Stream Text
&lt;/button&gt;
                </code>
            </div>
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
