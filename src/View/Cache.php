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

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
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
        $cachePath = $this->getCachePath($templatePath);

        return file_put_contents($cachePath, $compiled) !== false;
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
        $cachePath = $this->getCachePath($templatePath);

        if (is_file($cachePath)) {
            return unlink($cachePath);
        }

        return true;
    }
}
