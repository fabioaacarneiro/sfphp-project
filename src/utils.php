<?php

/**
 * Legacy global helpers for templates and validation.
 *
 * @package SfphpProject
 */

use SfphpProject\src\ValidationResult;
use SfphpProject\src\Csrf;
use SfphpProject\src\Validator;
use SfphpProject\src\View;

/**
 * Render a partial view with the provided data.
 *
 * @deprecated Use View::partial() instead.
 * @param string $view The name of the partial view to render
 * @param array $data The data to pass to the partial view
 * @return void
 */
function partial(
    string $view,
    array $data = []
): void {
    View::partial($view, $data);
}

/**
 * Build the URL for a public asset.
 *
 * @param string $asset The asset path relative to the public assets directory
 * @return string The asset URL
 * @throws InvalidArgumentException If the asset path is invalid
 */
function asset(string $asset): string
{
    $asset = ltrim($asset, '/');
    if (
        $asset === ''
        || str_contains($asset, '..')
        || !preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', $asset)
    ) {
        throw new InvalidArgumentException('Asset path is invalid.');
    }

    return '/assets/' . $asset;
}

/**
 * Escape a scalar value for HTML output.
 *
 * @param string|int|float|bool|null $value The value to escape
 * @return string The escaped HTML-safe value
 */
function e(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Output the URL for a public asset.
 *
 * @deprecated Use asset() to obtain the URL before rendering it.
 * @param string $asset The asset path relative to the public assets directory
 * @return void
 */
function assets(string $asset): void
{
    echo asset($asset);
}

/**
 * Validate data against the given rules.
 *
 * @deprecated Use Validator::validate() instead.
 * @param array $data The data to validate
 * @param array $rules The validation rules, keyed by field name
 * @param array $errorMessages Custom error messages for validation failures
 * @return ValidationResult The validation result
 */
function validate(
    array $data,
    array $rules,
    array $errorMessages = []
): ValidationResult {
    return Validator::validate($data, $rules, $errorMessages);
}

/**
 * Get the current CSRF token.
 *
 * @return string The active CSRF token
 */
function csrf_token(): string
{
    return Csrf::token();
}

/**
 * Render a hidden CSRF field for HTML forms.
 *
 * @param string $name The input name used by forms
 * @return string The HTML hidden field
 */
function csrf_field(string $name = '_token'): string
{
    return Csrf::field($name);
}

/**
 * Render a CSRF meta tag.
 *
 * @param string $name The meta tag name
 * @return string The HTML meta tag
 */
function csrf_meta(string $name = 'csrf-token'): string
{
    return Csrf::meta($name);
}

/**
 * Validate the current request token.
 *
 * @return bool True when the request token matches the session token
 */
function csrf_verify(): bool
{
    return Csrf::validateRequest();
}
