<?php

namespace SfphpProject\src\View;

/**
 * Manages SFHT template compilation cache.
 */
final class Cache
{
    private string $cacheDir;

    /**
     * Create a cache manager.
     *
     * @param string $cacheDir The cache directory path
     */
    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/sfht-cache';

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException("Cannot create template cache directory: {$this->cacheDir}");
        }
    }

    /**
     * Get cache key for a template file.
     *
     * The key names the source, not just its location: the path says which
     * template, and the modification time and size say which version of it.
     * A template that changes therefore compiles to a different file, and a
     * compiled file can only ever answer for the bytes it was made from.
     *
     * This used to be the path alone, with a newer-than comparison deciding
     * whether the compiled file still counted. That is only true while time
     * moves forward, and extracting an archive moves it backwards: a
     * `composer create-project` writes the package's own timestamps, so an
     * updated template arrived older than a cache file written minutes before
     * and was never recompiled. The site served the previous version of a page
     * whose source on disk was already the new one.
     *
     * @param string $path The template file path
     * @return string The cache key
     */
    public function getCacheKey(string $path): string
    {
        $stamp = is_file($path)
            ? dechex((int) filemtime($path)) . '-' . dechex((int) filesize($path))
            : '0-0';

        return md5($path) . '-' . $stamp;
    }

    /**
     * Get cached compiled template path.
     *
     * @param string $path The template file path
     * @return string The cached file path
     */
    public function getCachePath(string $path): string
    {
        return $this->compiledPath($path);
    }

    /**
     * Get the path of the compiled file for a template.
     *
     * @param string $path The template file path
     * @return string The compiled file path
     */
    public function compiledPath(string $path): string
    {
        return $this->cacheDir . '/' . $this->getCacheKey($path) . '.php';
    }

    /**
     * Check if template cache is valid.
     *
     * @param string $templatePath The template file path
     * @return bool
     */
    public function isValid(string $templatePath): bool
    {
        if (!is_file($templatePath)) {
            return false;
        }

        $cachePath = $this->getCachePath($templatePath);

        if (!is_file($cachePath)) {
            return false;
        }

        /*
         * The name already pins the version, so a compiled file that exists
         * was made from this exact template. What the name cannot separate is
         * two edits within the same second that leave the size unchanged —
         * filemtime() has one-second resolution. Treating a cache file written
         * in the template's own second as stale closes that: it forces one
         * more compile, and serving a stale template is a bug while compiling
         * twice is not.
         */
        return filemtime($cachePath) !== filemtime($templatePath);
    }

    /**
     * Store compiled template in cache.
     *
     * @param string $templatePath The template file path
     * @param string $compiled The compiled PHP code
     * @return bool
     */
    public function store(string $templatePath, string $compiled): bool
    {
        $cachePath = $this->compiledPath($templatePath);

        /*
         * Written to a temporary file and renamed into place. rename() is
         * atomic on the same filesystem, so a concurrent request either
         * includes the previous complete file or the new complete one, never
         * a half-written file that would be a PHP parse error.
         */
        $temporary = $cachePath . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (file_put_contents($temporary, $compiled, LOCK_EX) === false) {
            return false;
        }

        if (!rename($temporary, $cachePath)) {
            @unlink($temporary);

            return false;
        }

        /*
         * The compiled file is include()d, so OPcache may be holding the
         * previous version's opcodes for this exact path.
         */
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }

        /*
         * The previous versions of this same template, which now answer for
         * bytes nobody has any more. Left alone they would accumulate one file
         * per edit, which on a template being worked on is a file per save.
         */
        $this->clearOlderThan($templatePath, $cachePath);

        return true;
    }

    /**
     * Remove the compiled files of a template's earlier versions.
     *
     * @param string $templatePath The template file path
     * @param string $keep The compiled file to leave in place
     * @return void
     */
    private function clearOlderThan(string $templatePath, string $keep): void
    {
        foreach (glob($this->cacheDir . '/' . md5($templatePath) . '-*.php') ?: [] as $file) {
            if ($file === $keep) {
                continue;
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }

            @unlink($file);
        }
    }

    /**
     * Get compiled template from cache.
     *
     * @param string $templatePath The template file path
     * @return string|null The compiled code or null
     */
    public function get(string $templatePath): ?string
    {
        if (!$this->isValid($templatePath)) {
            return null;
        }

        $cachePath = $this->getCachePath($templatePath);

        return file_get_contents($cachePath) ?: null;
    }

    /**
     * Clear all cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        $files = glob($this->cacheDir . '/*.php');

        foreach ($files as $file) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }

            unlink($file);
        }

        return true;
    }

    /**
     * Clear cache for a specific template.
     *
     * @param string $templatePath The template file path
     * @return bool
     */
    public function clearFor(string $templatePath): bool
    {
        // Every version of it, since the name carries the version.
        $this->clearOlderThan($templatePath, '');

        return true;
    }
}
