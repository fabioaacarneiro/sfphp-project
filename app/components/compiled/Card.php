<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A card, written the way templ writes them: a function whose markup lives
 * inside it, with the parameters as the props.
 */
function Card(string $titulo, string $corpo, string $cor = 'blue'): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="card mb-4 border-';
echo \SfphpProject\src\View\Compiler::text(($cor));
echo '-500">
            <div class="card-header">
                <div class="testando">
                    <div class="teste">
                        <section>
                            <h1>olá</h1>
                        </section>
                    </div>
                </div>
                <h3 class="m-0 text-';
echo \SfphpProject\src\View\Compiler::text(($cor));
echo '-600">';
echo \SfphpProject\src\View\Compiler::text(($titulo));
echo '</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">';
echo \SfphpProject\src\View\Compiler::text(($corpo));
echo '</p>
            </div>
        </div>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}

/**
 * A list, showing that the SFHT directives work inside the markup.
 */
function Lista(array $itens): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<ul class="list-unstyled">
            ';
foreach ($itens as $item) {
echo '
                <li class="py-1">';
echo \SfphpProject\src\View\Compiler::text(($item));
echo '</li>
                <div class="teste">
                    <h1>teste</h1>
                </div>
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
function Pagina(array $itens): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<main class="container py-8">
            ';
echo \SfphpProject\src\View\Compiler::text((Card('Olá', 'Escrito dentro da função', "emerald")));
echo '
            ';
echo \SfphpProject\src\View\Compiler::text((Lista($itens)));
echo '
        </main>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
