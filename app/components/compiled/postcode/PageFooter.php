<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

/**
 * The page's footer.
 */
function PageFooter(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<footer class="bg-slate-900 text-white py-8 px-4 mt-12">
            <div class="container mx-auto max-w-2xl">
                <p class="m-0 text-sm">
                    Zero dependencies: no Node, no bundler, no third-party
                    library on this page.
                </p>
                <a href="/" class="text-blue-300 no-underline text-sm">Back</a>
            </div>
        </footer>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
