<?php

namespace SfphpProject\app\components\postcode\lookup;

use SfphpProject\src\View\Sfht;

/**
 * Something to say when there is no address to show.
 *
 * The message arrives as a prop and is printed with {{ }}, so it is escaped —
 * the same rule that lets a component beside it render untouched.
 */
function Notice(string $message, string $kind = 'warning'): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<div class="alert alert-'; echo \SfphpProject\src\View\Compiler::text(($kind)); echo '">'; echo \SfphpProject\src\View\Compiler::text(($message)); echo '</div>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
