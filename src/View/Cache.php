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
     * @param string $path The template file path
     * @return string The cache key
     */
    public function getCacheKey(string $path): string
    {
        return md5($path);
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
         * filemtime() has one-second resolution, so a template edited in the
         * same second the cache was written compares equal. Treating equal as
         * stale forces a recompile on the next request, which is the safe
         * direction: serving a stale template is a bug, recompiling once more
         * than needed is not.
         */
        return filemtime($cachePath) > filemtime($templatePath);
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

        return true;
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
        $cachePath = $this->compiledPath($templatePath);

        if (is_file($cachePath)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cachePath, true);
            }

            return unlink($cachePath);
        }

        return true;
    }
}
