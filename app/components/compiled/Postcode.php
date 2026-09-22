<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * The page that demonstrates .phpx, SFCSS and SFJS working together.
 *
 * Each function below is a component: its parameters are its props, its markup
 * lives inside it, and it returns Sfht rather than a string — so composing them
 * with {{ }} renders, while a value that came from outside is escaped in the
 * same position.
 */
function PostcodePage(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<!DOCTYPE html>
        <html lang="';
echo \SfphpProject\src\View\Compiler::text((lang_tag()));
echo '">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>.phpx + SFCSS + SFJS — SFPHP</title>
            <link rel="stylesheet" href="';
echo \SfphpProject\src\View\Compiler::text((asset('css/sfcss.min.css')));
echo '">
        </head>
        <body class="bg-light">
            ';
echo \SfphpProject\src\View\Compiler::text((PageHeader()));
echo '

            <main class="container py-12 max-w-2xl mx-auto px-4">
                ';
echo \SfphpProject\src\View\Compiler::text((PostcodeLookup()));
echo '
                ';
echo \SfphpProject\src\View\Compiler::text((HowItWorks()));
echo '
            </main>

            ';
echo \SfphpProject\src\View\Compiler::text((PageFooter()));
echo '

            <script src="';
echo \SfphpProject\src\View\Compiler::text((asset('js/sfjs.min.js')));
echo '"></script>
            <script src="';
echo \SfphpProject\src\View\Compiler::text((asset('js/postcode.js')));
echo '"></script>
        </body>
        </html>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars());
}

/**
 * The header. A component with no props is still a component: it exists so the
 * page reads as a list of parts rather than as one long file.
 */
function PageHeader(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<header class="bg-blue-600 text-white py-12 px-4">
            <div class="container mx-auto max-w-2xl">
                <p class="text-sm opacity-90 m-0 mb-2">SFPHP</p>
                <h1 class="text-3xl font-bold m-0 mb-2">Components with .phpx</h1>
                <p class="text-lg m-0 opacity-95">
                    This whole page is written as PHP functions whose markup
                    lives inside them, styled with SFCSS, with SFJS looking an
                    address up without reloading anything.
                </p>
            </div>
        </header>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}

/**
 * The form and the place its answer lands.
 */
function PostcodeLookup(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<section class="card mb-8">
            <div class="card-header">
                <h2 class="text-lg font-semibold m-0">Look up a postcode</h2>
            </div>

            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="postcode">Brazilian postcode</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <input id="postcode" type="text" inputmode="numeric" maxlength="9"
                               placeholder="01001-000"
                               class="border border-gray-300 rounded-md px-3 py-2 flex-grow-1">
                        <button id="lookup" type="button" class="btn btn-primary">Look up</button>
                    </div>
                    <p class="text-xs text-muted mt-2 mb-0">
                        The request leaves the browser, through SFJS. The server
                        is never called.
                    </p>
                </div>

                <div id="result" class="mt-4"></div>
            </div>
        </section>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}

/**
 * A field of an address, rendered into the result area.
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

/**
 * The explanation, with the source of the component above it.
 */
function HowItWorks(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<section class="card">
            <div class="card-header">
                <h2 class="text-lg font-semibold m-0">What is going on here</h2>
            </div>

            <div class="card-body">
                <p>
                    The card above is a PHP function. Its parameters are its
                    props, its markup lives inside it, and it returns
                    <code>Sfht</code> — markup that is already safe:
                </p>

                <pre><code>function Field(string $label, string $value): Sfht
{
    return sfht(
        &lt;div class="py-1"&gt;
            &lt;span class="text-xs text-muted d-block"&gt;&#123;&#123; $label &#125;&#125;&lt;/span&gt;
            &lt;span class="font-semibold"&gt;&#123;&#123; $value &#125;&#125;&lt;/span&gt;
        &lt;/div&gt;
    );
}</code></pre>

                <p class="mt-4">
                    Composed with <code>&#123;&#123; &#125;&#125;</code>, a component
                    renders and text that came from outside is escaped — in the
                    same position. The type decides, so nobody has to remember
                    what is trusted.
                </p>

                <p class="mb-0">
                    <code>./sfphp build --phpx</code> compiles and runs
                    <code>php -l</code> over every generated file, so a syntax
                    error shows up at build time rather than in production.
                </p>
            </div>
        </section>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}

/**
 * The footer.
 */
function PageFooter(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<footer class="bg-slate-900 text-white py-8 px-4 mt-12">
            <div class="container mx-auto max-w-2xl">
                <p class="m-0 text-sm">
                    Zero dependencies: no Node, no bundler, no third-party
                    library on this page.
                </p>
                <a href="/" class="text-blue-300 no-underline text-sm">Back</a>
            </div>
        </footer>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
