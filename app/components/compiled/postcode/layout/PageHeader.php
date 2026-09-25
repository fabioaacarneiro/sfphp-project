<?php

namespace SfphpProject\app\components\postcode\layout;

use SfphpProject\src\View\Sfht;

/**
 * The page's header.
 *
 * A component with no props is still a component: it exists so the page reads
 * as a list of parts rather than as one long file.
 */
function PageHeader(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<header class="bg-blue-600 text-white py-12 px-4">
            <div class="container mx-auto max-w-2xl">
                <p class="text-sm opacity-90 m-0 mb-2">SFPHP</p>
                <h1 class="text-3xl font-bold m-0 mb-2">Components with .phpx</h1>
                <p class="text-lg m-0 opacity-95">
                    This whole page is written as PHP functions whose markup
                    lives inside them, styled with SFCSS, with SFJS looking an
                    address up without reloading anything.
                </p>
            </div>
        </header>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
