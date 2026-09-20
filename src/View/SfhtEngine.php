<?php

namespace SfphpProject\src\View;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * SFHT Template Engine - Simple Framework HTML Template.
 * Renders .sfht templates with support for control flow, inheritance, components, and filters.
 *
 * Variables whose names start with "__" are reserved for the engine.
 */
final class SfhtEngine
{
    private Compiler $compiler;
    private Cache $cache;
    private array $paths = [];
    private array $globals = [];
    private array $filters = [];

    /** True while a top-level render() is running. */
    private bool $rendering = false;

    /** @var array<string, string> Block contents by name, the first definition wins. */
    private array $blocks = [];

    /** @var array<int, string> Names of the blocks currently being captured. */
    private array $blockStack = [];

    /** @var array<int, string|null> Parent layout requested by each template being rendered. */
    private array $layouts = [];

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
     * @param string $template The template name, using dots or slashes for folders
     * @param array<string, mixed> $data Variables to pass to template
     * @return string The rendered output
     * @throws InvalidArgumentException If the template name is invalid
     * @throws RuntimeException If template not found or rendering fails
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->locate($template);

        if ($this->rendering) {
            return $this->renderFile($file, $data);
        }

        $this->rendering = true;

        try {
            return $this->renderFile($file, $data);
        } finally {
            $this->rendering = false;
            $this->blocks = [];
            $this->blockStack = [];
            $this->layouts = [];
        }
    }

    /**
     * Render a template file, following its @extends chain.
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

            try {
                $compiled = $this->compiler->compile($content);
            } catch (RuntimeException $e) {
                throw new RuntimeException("{$e->getMessage()} ({$file})", 0, $e);
            }

            $this->cache->store($file, $compiled);
        }

        $this->layouts[] = null;

        try {
            $output = $this->executeTemplate($compiled, $data);
            $parent = $this->layouts[array_key_last($this->layouts)];
        } finally {
            array_pop($this->layouts);
        }

        // A child template only defines blocks: its own output is discarded
        // and the layout is rendered around them.
        return $parent === null
            ? $output
            : $this->renderFile($this->locate($parent), $data);
    }

    /**
     * Execute compiled template code.
     *
     * @param string $__code The compiled PHP code
     * @param array<string, mixed> $__data Template variables
     * @return string
     */
    private function executeTemplate(string $__code, array $__data): string
    {
        $__engine = $this;

        extract(array_merge($this->globals, $__data), EXTR_SKIP);

        $__level = ob_get_level();
        ob_start();

        try {
            eval('?>' . $__code);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    /**
     * Resolve template path.
     *
     * @param string $template The template name
     * @return string The full file path, or a relative name when not found
     * @throws InvalidArgumentException If the template name is invalid
     */
    public function resolve(string $template): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+(?:[\/.][A-Za-z0-9_-]+)*$/', $template)) {
            throw new InvalidArgumentException("Template name \"{$template}\" is invalid.");
        }

        $template = str_replace('.', '/', $template);

        foreach ($this->paths as $path) {
            $full = rtrim($path, '/') . '/' . $template . '.sfht';

            if (is_file($full)) {
                return $full;
            }
        }

        return $template . '.sfht';
    }

    /**
     * Resolve a template name to a file that exists.
     *
     * @throws RuntimeException If the template does not exist
     */
    private function locate(string $template): string
    {
        $path = $this->resolve($template);

        if (!is_file($path)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        return $path;
    }

    /**
     * Apply a registered filter to a value.
     *
     * @param string $name The filter name
     * @param mixed $value The value being filtered
     * @param array<int, mixed> $args Extra filter arguments
     * @return mixed
     * @throws RuntimeException If the filter is not registered
     */
    public function filter(string $name, $value, array $args = [])
    {
        if (!isset($this->filters[$name])) {
            throw new RuntimeException("Filter not registered: {$name}");
        }

        return call_user_func($this->filters[$name], $value, ...$args);
    }

    /**
     * Register a filter function.
     *
     * @param string $name The filter name, used as "{{ $value | name }}"
     * @param callable $fn Receives the value followed by the filter arguments
     * @return void
     */
    public function addFilter(string $name, callable $fn): void
    {
        $this->filters[$name] = $fn;
    }

    /**
     * Escape a value for HTML output. Used by "{{ }}".
     *
     * @param mixed $value The value to escape
     * @return string
     */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    /*
     * Runtime API used by compiled templates. Not meant to be called directly.
     */

    /**
     * Declare the layout the current template extends (@extends).
     */
    public function extend(string $template): void
    {
        $this->layouts[array_key_last($this->layouts)] = $template;
    }

    /**
     * Start capturing a block (@block).
     */
    public function startBlock(string $name): void
    {
        $this->blockStack[] = $name;
        ob_start();
    }

    /**
     * Finish a block (@endblock) and return the content to print.
     *
     * The first definition of a name wins, and children render before their
     * layout, so a child's block replaces the layout's default content.
     */
    public function endBlock(): string
    {
        $content = (string) ob_get_clean();
        $name = (string) array_pop($this->blockStack);

        return $this->blocks[$name] ??= $content;
    }

    /**
     * Create the $loop variable for a @foreach.
     */
    public function loop(mixed $items, ?Loop $parent): Loop
    {
        return new Loop($items, $parent);
    }

    /**
     * Render an @include: the partial sees the including template's variables.
     *
     * @param array<string, mixed> $vars Extra data for the partial
     * @param array<string, mixed> $scope The including template's variables
     */
    public function includeTemplate(string $template, array $vars, array $scope): string
    {
        $scope = array_filter(
            $scope,
            fn ($key) => !str_starts_with((string) $key, '__'),
            ARRAY_FILTER_USE_KEY
        );

        return $this->renderFile($this->locate($template), array_merge($scope, $vars));
    }

    /**
     * Render a @component: it only sees the data passed to it.
     *
     * @param array<string, mixed> $vars Data for the component
     */
    public function componentTemplate(string $template, array $vars): string
    {
        return $this->renderFile($this->locate($template), $vars);
    }
}
