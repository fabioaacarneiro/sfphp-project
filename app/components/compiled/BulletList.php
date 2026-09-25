<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A list, showing that the SFHT directives work inside the markup.
 *
 * Composing it with {{ BulletList($items) }} renders, because a component
 * returns Sfht; a string in the same position would be escaped. The type
 * decides, so nobody has to remember which values are safe.
 */
function BulletList(array $items): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<ul class="list-unstyled">
            '; foreach ($items as $item) { echo '
                <li class="py-1">'; echo \SfphpProject\src\View\Compiler::text(($item)); echo '</li>
            '; } echo '
        </ul>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
