<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

/**
 * An address, composed of the fields beside it.
 *
 * This is the fragment the lookup swaps in, and it is also part of the whole
 * page when the browser asked for one — the same component either way, which
 * is what stops a page and its updates from drifting apart.
 */
function Address(string $street, string $district, string $city, string $state): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card">
            <div class="card-body">
                ';
echo \SfphpProject\src\View\Compiler::text((Field('Street', $street)));
echo '
                ';
echo \SfphpProject\src\View\Compiler::text((Field('District', $district)));
echo '
                ';
echo \SfphpProject\src\View\Compiler::text((Field('City', $city)));
echo '
                ';
echo \SfphpProject\src\View\Compiler::text((Field('State', $state)));
echo '
            </div>
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
