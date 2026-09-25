<?php

namespace SfphpProject\app\components\examples\layout;

use SfphpProject\src\View\Sfht;

function PageHeader(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<header class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white py-8">
            <div class="container max-w-6xl mx-auto px-4">
                <h1 class="text-3xl font-bold mb-2">'; echo '@stream'; echo ' & '; echo '@sse'; echo ' Examples</h1>
                <p class="text-lg opacity-90">
                    Real-time data delivery with SFJS directives
                </p>
            </div>
        </header>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
