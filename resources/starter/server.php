<?php

/**
 * Router script for PHP's built-in web server.
 *
 * The built-in server looks for an index file inside the requested directory,
 * so "/users/1" would 404 instead of reaching the front controller. This script
 * serves real files as they are and forwards everything else to index.php.
 *
 * Usage:
 *   php -S localhost:8000 -t public server.php
 *
 * Intended for local development only.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicDirectory = realpath(__DIR__ . '/public');
$relativePath = ltrim($path, '/');
$file = $publicDirectory === false
    ? false
    : realpath($publicDirectory . DIRECTORY_SEPARATOR . $relativePath);

if (
    $path !== '/'
    && $file !== false
    && str_starts_with($file, $publicDirectory . DIRECTORY_SEPARATOR)
    && is_file($file)
) {
    return false;
}

require __DIR__ . '/public/index.php';
