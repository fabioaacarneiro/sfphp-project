<?php

namespace SfphpProject\app\components\postcode\lookup;

use SfphpProject\src\View\Sfht;

/**
 * One field of an address.
 *
 * Composed by Address, which the controller renders for both answers it gives
 * — the fragment SFJS swaps in and the whole page a browser without JavaScript
 * receives. Written once, so the two can never disagree.
 */
function Field(string $label, string $value): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<div class="py-1">
            <span class="text-xs text-muted d-block">'; echo \SfphpProject\src\View\Compiler::text(($label)); echo '</span>
            <span class="font-semibold">'; echo \SfphpProject\src\View\Compiler::text(($value)); echo '</span>
        </div>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
