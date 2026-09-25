<?php

namespace SfphpProject\app\components\examples\layout;

use SfphpProject\src\View\Sfht;

function PageHeader(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<header class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white py-8">
            <div class="container max-w-6xl mx-auto px-4">
                <h1 class="text-3xl font-bold mb-2">';
echo '@stream';
echo ' & ';
echo '@sse';
echo ' Examples</h1>
                <p class="text-lg opacity-90">
                    Real-time data delivery with SFJS directives
                </p>
            </div>
        </header>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
