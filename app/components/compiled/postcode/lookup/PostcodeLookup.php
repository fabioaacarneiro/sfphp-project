<?php

namespace SfphpProject\app\components\postcode\lookup;

use SfphpProject\src\View\Sfht;

/**
 * The form, and the place its answer lands.
 *
 * `@get` is SFJS: it takes over the submit, sends the fields to that URL and
 * swaps the answer into `@target`. There is no JavaScript of our own on this
 * page because there is nothing left for it to do — the server already knows
 * how to render an address, since that is what the components are for.
 *
 * Without JavaScript the form submits normally to the same URL and the same
 * controller answers with the whole page. The attribute makes it quicker, not
 * possible.
 */
function PostcodeLookup(?Sfht $result = null): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); $__level = ob_get_level(); ob_start(); try { echo '<section class="card mb-8">
            <div class="card-header">
                <h2 class="text-lg font-semibold m-0">Look up a postcode</h2>
            </div>

            <div class="card-body">
                <form method="get" action="/phpx/postcode" '; echo '@get'; echo '="/phpx/postcode" '; echo '@target'; echo '="#result">
                    <div class="form-group">
                        <label class="form-label" for="postcode">Brazilian postcode</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input id="postcode" name="postcode" type="text" inputmode="numeric" maxlength="9"
                                   placeholder="01001-000"
                                   class="form-control flex-1 w-auto">
                            <button type="submit" class="btn btn-primary">Look up</button>
                        </div>
                        <p class="text-xs text-muted mt-2 mb-0">
                            The answer is markup, rendered by the components in
                            this folder, and swapped in where it belongs.
                        </p>
                    </div>
                </form>

                <div id="result" class="mt-4" aria-live="polite">'; echo \SfphpProject\src\View\Compiler::text(($result)); echo '</div>
            </div>
        </section>';  } catch (\Throwable $__e) { while (ob_get_level() > $__level) { ob_end_clean(); } throw $__e; } return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
