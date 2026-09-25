<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

use function SfphpProject\app\components\postcode\explain\HowItWorks;
use function SfphpProject\app\components\postcode\layout\PageFooter;
use function SfphpProject\app\components\postcode\layout\PageHeader;
use function SfphpProject\app\components\postcode\lookup\PostcodeLookup;

/**
 * The page that demonstrates .phpx, SFCSS and SFJS working together.
 *
 * It composes the components beside it and nothing else, so what the page is
 * made of can be read in one screen. Each of them lives in its own file, named
 * after the function, which is the convention this framework follows.
 *
 * Its parts are grouped by what they do — layout, lookup, explain — so the page
 * imports them by name. A component reaches its own folder's neighbours with no
 * import at all; crossing a folder is a `use function`, the same as any other
 * function in PHP.
 *
 * The result is a prop because the same page answers a browser that ran the
 * lookup without JavaScript: the controller renders it once, with the address
 * already in place.
 */
function PostcodePage(?Sfht $result = null): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>.phpx + SFCSS + SFJS — SFPHP</title>
            <link rel="stylesheet" href="'; echo \SfphpProject\src\View\Compiler::text((asset('css/sfcss.min.css'))); echo '">
            <link rel="icon" href="data:,">
            '; echo (csrf_meta()); echo '
        </head>
        <body class="bg-light">
            '; echo \SfphpProject\src\View\Compiler::text((PageHeader())); echo '

            <main class="container py-12 max-w-2xl mx-auto px-4">
                '; echo \SfphpProject\src\View\Compiler::text((PostcodeLookup($result))); echo '
                '; echo \SfphpProject\src\View\Compiler::text((HowItWorks())); echo '
            </main>

            '; echo \SfphpProject\src\View\Compiler::text((PageFooter())); echo '

            <script src="'; echo \SfphpProject\src\View\Compiler::text((asset('js/sfjs.min.js'))); echo '"></script>
        </body>
        </html>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
