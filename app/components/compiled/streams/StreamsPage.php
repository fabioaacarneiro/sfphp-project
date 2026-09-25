<?php

namespace SfphpProject\app\components\streams;

use SfphpProject\src\View\Sfht;

use function SfphpProject\app\components\streams\layout\PageFooter;
use function SfphpProject\app\components\streams\layout\PageHeader;
use function SfphpProject\app\components\streams\boxes\TextStreamBox;
use function SfphpProject\app\components\streams\boxes\SseStreamBox;
use function SfphpProject\app\components\streams\explain\HowItWorks;

/**
 * The page that demonstrates HTTP Streaming and Server-Sent Events (SSE).
 */
function StreamsPage(): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<!DOCTYPE html>
        <html lang="'; echo \SfphpProject\src\View\Compiler::text((lang_tag())); echo '">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>HTTP Streaming + SSE — SFPHP</title>
            <link rel="stylesheet" href="'; echo \SfphpProject\src\View\Compiler::text((asset('css/sfcss.min.css'))); echo '">
        </head>
        <body class="bg-light">
            '; echo \SfphpProject\src\View\Compiler::text((PageHeader())); echo '

            <main class="container py-12 max-w-4xl mx-auto px-4">
                <div class="grid grid-cols-2 gap-6">
                    '; echo \SfphpProject\src\View\Compiler::text((TextStreamBox())); echo '
                    '; echo \SfphpProject\src\View\Compiler::text((SseStreamBox())); echo '
                </div>

                '; echo \SfphpProject\src\View\Compiler::text((HowItWorks())); echo '
            </main>

            '; echo \SfphpProject\src\View\Compiler::text((PageFooter())); echo '

            <script src="'; echo \SfphpProject\src\View\Compiler::text((asset('js/sfjs.min.js'))); echo '"></script>
        </body>
        </html>';  return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
