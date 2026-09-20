<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * SFHT Template Engine - Simple Framework HTML Template.
 * Renders .sfht templates with support for control flow, inheritance, components, and filters.
 */
final class SfhtEngine
{
    private Compiler $compiler;
    private Cache $cache;
    private array $paths = [];
    private array $globals = [];
    private array $filters = [];

    /**
     * Create the SFHT template engine.
     *
     * @param array<int, string> $paths Paths to search for templates
     * @param string $cachePath Path for compiled template cache
     */
    public function __construct(array $paths = [], string $cachePath = '')
    {
        $this->compiler = new Compiler();
        $this->cache = new Cache($cachePath);
        $this->paths = $paths ?: [getcwd() . '/resources/views'];

        $this->registerDefaultFilters();
    }

    /**
     * Render a template with given data.
     *
     * @param string $template The template file (without extension)
     * @param array<string, mixed> $data Variables to pass to template
     * @return string The rendered output
     * @throws RuntimeException If template not found or rendering fails
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->resolve($template);

        if (!is_file($path)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        return $this->renderFile($path, $data);
    }

    /**
     * Render a template file.
     *
     * @param string $file The full file path
     * @param array<string, mixed> $data Template variables
     * @return string
     */
    private function renderFile(string $file, array $data = []): string
    {
        $compiled = $this->cache->get($file);

        if ($compiled === null) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException("Cannot read template file: {$file}");
            }

            $compiled = $this->compiler->compile($content);
            $this->cache->store($file, $compiled);
        }

        return $this->executeTemplate($compiled, $data);
    }

    /**
     * Execute compiled template code.
     *
     * @param string $code The compiled PHP code
     * @param array<string, mixed> $data Template variables
     * @return string
     */
    private function executeTemplate(string $code, array $data): string
    {
        $__engine = $this;
        $__vars = array_merge($this->globals, $data);

        extract($__vars, EXTR_SKIP);

        ob_start();

        try {
            eval('?>' . $code);
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RuntimeException("Template execution error: " . $e->getMessage());
        }
    }

    /**
     * Resolve template path.
     *
     * @param string $template The template name
     * @return string The full file path
     */
    public function resolve(string $template): string
    {
        $template = str_replace('.', '/', $template);
        $template = ltrim($template, '/');

        foreach ($this->paths as $path) {
            $full = rtrim($path, '/') . '/' . $template . '.sfht';

            if (is_file($full)) {
                return $full;
            }
        }

        return $template . '.sfht';
    }

    /**
     * Register a filter function.
     *
     * @param string $name The filter name
     * @param callable $fn The filter function
     * @return void
     */
    public function filter(string $name, $value, array $args = [])
    {
        if (!isset($this->filters[$name])) {
            throw new RuntimeException("Filter not registered: {$name}");
        }

        return call_user_func($this->filters[$name], $value, ...$args);
    }

    /**
     * Register default filters.
     *
     * @return void
     */
    private function registerDefaultFilters(): void
    {
        $this->filters['upper'] = fn ($v) => strtoupper($v);
        $this->filters['lower'] = fn ($v) => strtolower($v);
        $this->filters['capitalize'] = fn ($v) => ucfirst($v);
        $this->filters['truncate'] = fn ($v, $len = 50, $suffix = '...')
            => strlen($v) > $len ? substr($v, 0, $len) . $suffix : $v;
        $this->filters['escape'] = fn ($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $this->filters['json'] = fn ($v) => json_encode($v);
        $this->filters['format'] = fn ($v, $fmt) => sprintf($fmt, $v);
        $this->filters['trim'] = fn ($v) => trim($v);
        $this->filters['reverse'] = fn ($v) => strrev($v);
        $this->filters['abs'] = fn ($v) => abs($v);
        $this->filters['round'] = fn ($v, $prec = 0) => round($v, $prec);
    }

    /**
     * Set a global template variable.
     *
     * @param string $key The variable name
     * @param mixed $value The variable value
     * @return void
     */
    public function setGlobal(string $key, $value): void
    {
        $this->globals[$key] = $value;
    }

    /**
     * Set multiple global variables.
     *
     * @param array<string, mixed> $variables
     * @return void
     */
    public function setGlobals(array $variables): void
    {
        $this->globals = array_merge($this->globals, $variables);
    }

    /**
     * Clear all cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }
}
