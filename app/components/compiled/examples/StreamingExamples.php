<?php

namespace SfphpProject\app\components\examples;

use SfphpProject\src\View\Sfht;

use function SfphpProject\app\components\examples\layout\PageHeader;
use function SfphpProject\app\components\examples\layout\PageFooter;
use function SfphpProject\app\components\examples\streaming\TextStreamExample;
use function SfphpProject\app\components\examples\streaming\SseExample;
use function SfphpProject\app\components\examples\streaming\CodeExamples;

/**
 * Examples of @stream and @sse directives from SFJS
 */
function StreamingExamples(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<!DOCTYPE html>
        <html lang="';
echo \SfphpProject\src\View\Compiler::text((lang_tag()));
echo '">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>';
echo '@stream';
echo ' & ';
echo '@sse';
echo ' Examples — SFPHP</title>
            <link rel="stylesheet" href="';
echo \SfphpProject\src\View\Compiler::text((asset('css/sfcss.min.css')));
echo '">
        </head>
        <body class="bg-light">
            ';
echo \SfphpProject\src\View\Compiler::text((PageHeader()));
echo '

            <main class="container py-12 max-w-6xl mx-auto px-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
                    ';
echo \SfphpProject\src\View\Compiler::text((TextStreamExample()));
echo '
                    ';
echo \SfphpProject\src\View\Compiler::text((SseExample()));
echo '
                </div>

                ';
echo \SfphpProject\src\View\Compiler::text((CodeExamples()));
echo '
            </main>

            ';
echo \SfphpProject\src\View\Compiler::text((PageFooter()));
echo '

            <script src="';
echo \SfphpProject\src\View\Compiler::text((asset('js/sfjs.min.js')));
echo '"></script>
            <script src="';
echo \SfphpProject\src\View\Compiler::text((asset('js/sfjs-stream.min.js')));
echo '"></script>
        </body>
        </html>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}
