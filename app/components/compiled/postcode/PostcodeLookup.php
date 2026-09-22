<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

/**
 * The form, and the place its answer lands.
 *
 * The ids are the contract with public/assets/js/postcode.js, which fills the
 * result area from the browser.
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
