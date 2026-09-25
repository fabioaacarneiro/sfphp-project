<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A card, written the way templ writes them: a function whose markup lives
 * inside it, with the parameters as the props.
 */
function Card(string $title, string $body, string $colour = 'blue'): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card mb-4 border-'; echo \SfphpProject\src\View\Compiler::text(($colour)); echo '-500">
            <div class="card-header">
                <h3 class="m-0 text-'; echo \SfphpProject\src\View\Compiler::text(($colour)); echo '-600">'; echo \SfphpProject\src\View\Compiler::text(($title)); echo '</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">'; echo \SfphpProject\src\View\Compiler::text(($body)); echo '</p>
            </div>
        </div>';  return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
