<?php

/**
 * Global helpers for templates: asset URLs, escaping and CSRF.
 *
 * @package SfphpProject
 */

use SfphpProject\src\Csrf;

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

    /*
     * The file's modification time and size go in the query string, so a
     * browser that cached the previous sfjs.min.js asks for the new one the
     * moment it changes — after an upgrade it used to keep running the old
     * script until its cache expired. A file that is not there gets no
     * version, and the URL still names it.
     */
    static $versions = [];

    if (!array_key_exists($asset, $versions)) {
        $file = \SfphpProject\src\Bootstrap::basePath('public/assets/' . $asset);
        $versions[$asset] = is_file($file) ? substr(md5(filemtime($file) . '-' . filesize($file)), 0, 8) : null;
    }

    return '/assets/' . $asset . ($versions[$asset] === null ? '' : '?v=' . $versions[$asset]);
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
