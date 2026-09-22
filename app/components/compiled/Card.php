<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A card, written the way templ writes them: a function whose markup lives
 * inside it, with the parameters as the props.
 */
function Card(string $title, string $body, string $colour = 'blue'): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card mb-4 border-';
echo \SfphpProject\src\View\Compiler::text(($colour));
echo '-500">
            <div class="card-header">
                <h3 class="m-0 text-';
echo \SfphpProject\src\View\Compiler::text(($colour));
echo '-600">';
echo \SfphpProject\src\View\Compiler::text(($title));
echo '</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">';
echo \SfphpProject\src\View\Compiler::text(($body));
echo '</p>
            </div>
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}

/**
 * A list, showing that the SFHT directives work inside the markup.
 */
function BulletList(array $items): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<ul class="list-unstyled">
            ';
foreach ($items as $item) {
echo '
                <li class="py-1">';
echo \SfphpProject\src\View\Compiler::text(($item));
echo '</li>
            ';
}
echo '
        </ul>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}

/**
 * A page, composing the two above.
 *
 * Both interpolations below are {{ }}. The card renders and the list renders,
 * because a component returns Sfht; a string in the same position would be
 * escaped. The type decides, so nobody has to remember which values are safe.
 */
function CardPage(array $items): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<main class="container py-8">
            ';
echo \SfphpProject\src\View\Compiler::text((Card('Hello', 'Written inside the function', 'emerald')));
echo '
            ';
echo \SfphpProject\src\View\Compiler::text((BulletList($items)));
echo '
        </main>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
