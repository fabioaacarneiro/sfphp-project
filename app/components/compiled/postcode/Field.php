<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

/**
 * One field of an address.
 *
 * Used from JavaScript rather than from PHP, so it is here to be read: the same
 * shape the browser builds, written once in markup a person can follow.
 */
function Field(string $label, string $value): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="py-1">
            <span class="text-xs text-muted d-block">';
echo \SfphpProject\src\View\Compiler::text(($label));
echo '</span>
            <span class="font-semibold">';
echo \SfphpProject\src\View\Compiler::text(($value));
echo '</span>
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
