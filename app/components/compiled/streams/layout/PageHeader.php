<?php

namespace SfphpProject\app\components\streams\layout;

use SfphpProject\src\View\Sfht;

function PageHeader(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white py-8">
            <div class="container max-w-4xl mx-auto px-4">
                <h1 class="text-3xl font-bold mb-2">HTTP Streaming & SSE</h1>
                <p class="text-lg opacity-90">
                    Real-time data delivery with Server-Sent Events
                </p>
                <p class="text-sm opacity-75 mt-3">
                    Part of the SFPHP Framework demonstration
                </p>
            </div>
        </header>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
