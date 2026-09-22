<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

/**
 * The explanation, quoting the component above it.
 */
function HowItWorks(): Sfht
{
    return (static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<section class="card">
            <div class="card-header">
                <h2 class="text-lg font-semibold m-0">What is going on here</h2>
            </div>

            <div class="card-body">
                <p>
                    The card above is a PHP function in a file of its own. Its
                    parameters are its props, its markup lives inside it, and it
                    returns <code>Sfht</code> — markup that is already safe:
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
                    <code>./sfphp build --phpx</code> compiles every component
                    under <code>app/components</code>, mirroring the folders,
                    and runs <code>php -l</code> over each result — so a syntax
                    error shows up at build time rather than in production.
                </p>
            </div>
        </section>';
 return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
