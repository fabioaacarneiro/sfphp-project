<?php

namespace SfphpProject\app\components\examples\layout;

use SfphpProject\src\View\Sfht;

function PageFooter(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<footer class="bg-gray-900 text-gray-300 py-8 mt-12">
            <div class="container max-w-6xl mx-auto px-4 text-center">
                <p class="mb-2">
                    <strong>SFPHP Framework</strong> — Zero runtime dependencies, real-time ready.
                </p>
                <p class="text-sm">
                    <a href="/phpx" class="text-blue-400 hover:text-blue-300 mr-3">PHPX Examples</a>
                    •
                    <a href="/streams" class="text-blue-400 hover:text-blue-300 mr-3">Streams Demo</a>
                    •
                    <a href="/" class="text-blue-400 hover:text-blue-300">Home</a>
                </p>
            </div>
        </footer>';  return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
