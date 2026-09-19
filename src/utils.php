<?php

/**
 * Legacy global helpers for view rendering and data validation.
 *
 * @package SfphpProject
 */

use SfphpProject\src\ValidationResult;
use SfphpProject\src\Validator;
use SfphpProject\src\View;

/**
 * Render a partial view with the provided data.
 *
 * @deprecated Use View::partial() instead.
 * @param string $view The name of the partial view to render
 * @param array $data The data to pass to the view
 * @throws \Exception If the view file does not exist
 * @return void
 */
function partial(
    string $view,
    array $data = []
): void {
    View::partial($view, $data);
}

/**
 * Output the URL for a given asset.
 *
 * @param string $asset The name of the asset
 * @return void
 */
function assets(string $asset): void
{
    echo "/assets/$asset";
}

/**
 * Validate data against the given rules.
 *
 * @deprecated Use Validator::validate() instead.
 * @param array $data The data to validate
 * @param array $rules The validation rules, keyed by field name
 * @param array $errorMessages Custom error messages for validation failures
 * @return ValidationResult
 * @throws InvalidArgumentException If a rule name is not recognised
 */
function validate(
    array $data,
    array $rules,
    array $errorMessages = []
): ValidationResult {
    return Validator::validate($data, $rules, $errorMessages);
}
