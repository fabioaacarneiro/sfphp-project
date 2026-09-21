<?php

namespace SfphpProject\src\View;

use RuntimeException;
use SfphpProject\src\Str;
use Throwable;

/**
 * SFHT template engine.
 *
 * Renders .sfht templates with control flow, template inheritance, partials
 * and filters.
 *
 * Compiled templates are executed with include(), not eval(). The previous
 * engine wrote the compiled PHP to a cache file and then threw the file away
 * and eval()'d the string, which meant OPcache never saw the code and every
 * request re-parsed every template: the cache paid the compilation cost
 * without ever collecting the benefit. Including the cached file lets OPcache
 * hold the opcodes, and it makes runtime errors report a real file and line
 * instead of "eval()'d code".
 */
final class SfhtEngine
{
    /**
     * Guards against a layout cycle, where two templates extend each other.
     */
    private const MAX_INHERITANCE_DEPTH = 16;

    private Compiler $compiler;
    private Cache $cache;

    /** @var array<int, string> */
    private array $paths = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    /** @var array<string, callable> */
    private array $filters = [];

    /**
     * Captured block contents, by block name.
     *
     * @var array<string, string>
     */
    private array $blocks = [];

    /**
     * Block names currently being captured, innermost last.
     *
     * @var array<int, string>
     */
    private array $blockStack = [];

    /**
     * The layout the template being rendered extends, when it declared one.
     */
    private ?string $parent = null;

    /**
     * Create the SFHT template engine.
     *
     * @param array<int, string> $paths Directories searched for templates
     * @param string $cachePath Directory holding compiled templates
     */
    public function __construct(array $paths = [], string $cachePath = '')
    {
        $this->compiler = new Compiler();
        $this->cache = new Cache($cachePath);
        $this->paths = $paths ?: [getcwd() . '/resources/views'];

        $this->registerDefaultFilters();
    }

    /**
     * Render a template.
     *
     * @param string $template The template name, without extension
     * @param array<string, mixed> $data Variables exposed to the template
     * @return string The rendered output
     * @throws RuntimeException If the template is missing or fails to render
     */
    public function render(string $template, array $data = []): string
    {
        /*
         * Inheritance state belongs to one top-level render. Saving and
         * restoring it means a partial that renders a template of its own
         * cannot clobber the blocks of the page including it.
         */
        $previousBlocks = $this->blocks;
        $previousStack = $this->blockStack;
        $previousParent = $this->parent;

        $this->blocks = [];
        $this->blockStack = [];
        $this->parent = null;

        try {
            $output = $this->renderFile($this->resolve($template), $data);

            $depth = 0;
            while ($this->parent !== null) {
                if (++$depth > self::MAX_INHERITANCE_DEPTH) {
                    throw new RuntimeException(
                        "Template inheritance exceeded {$depth} levels; check for a cycle in @extends."
                    );
                }

                $layout = $this->parent;
                $this->parent = null;
                $output = $this->renderFile($this->resolve($layout), $data);
            }

            return $output;
        } finally {
            $this->blocks = $previousBlocks;
            $this->blockStack = $previousStack;
            $this->parent = $previousParent;
        }
    }

    /**
     * Render a partial, reusing the caller's inheritance state.
     *
     * @param string $template The partial name
     * @param array<string, mixed> $data Variables exposed to the partial
     * @return string The rendered output
     * @throws RuntimeException If the partial is missing or fails to render
     */
    public function renderPartial(string $template, array $data = []): string
    {
        /*
         * The engine's own variables leak into get_defined_vars() at the
         * include site, so they are dropped before the partial sees them.
         */
        unset($data['__engine'], $data['__file'], $data['__data'], $data['__forelse']);

        return $this->renderFile($this->resolve($template), $data);
    }

    /**
     * Declare the layout the current template extends.
     *
     * @internal Called by compiled templates.
     * @param string $template The layout name
     * @return void
     */
    public function extend(string $template): void
    {
        $this->parent = $template;
    }

    /**
     * Begin capturing a block.
     *
     * @internal Called by compiled templates.
     * @param string $name The block name
     * @return void
     */
    public function startBlock(string $name): void
    {
        $this->blockStack[] = $name;
        ob_start();
    }

    /**
     * Finish capturing a block and emit its winning content.
     *
     * A child template renders before its layout, so by the time the layout
     * reaches the same block name the child's version is already recorded and
     * wins. When no child defined it, the layout's own content is what was
     * just captured, so the default is used.
     *
     * @internal Called by compiled templates.
     * @return void
     * @throws RuntimeException If no block is open
     */
    public function endBlock(): void
    {
        if ($this->blockStack === []) {
            throw new RuntimeException('@endblock without a matching @block.');
        }

        $name = array_pop($this->blockStack);
        $content = ob_get_clean();

        if (!array_key_exists($name, $this->blocks)) {
            $this->blocks[$name] = $content === false ? '' : $content;
        }

        echo $this->blocks[$name];
    }

    /**
     * Resolve a template name to a file path.
     *
     * @param string $template The template name
     * @return string The template file path
     */
    public function resolve(string $template): string
    {
        $template = ltrim(str_replace('.', '/', $template), '/');

        foreach ($this->paths as $path) {
            $full = rtrim($path, '/') . '/' . $template . '.sfht';

            if (is_file($full)) {
                return $full;
            }
        }

        return $template . '.sfht';
    }

    /**
     * Apply a registered filter.
     *
     * @param string $name The filter name
     * @param mixed $value The value to filter
     * @param array<int, mixed> $args Extra filter arguments
     * @return mixed The filtered value
     * @throws RuntimeException If the filter is not registered
     */
    public function filter(string $name, mixed $value, array $args = []): mixed
    {
        if (!isset($this->filters[$name])) {
            throw new RuntimeException("Filter not registered: {$name}");
        }

        return ($this->filters[$name])($value, ...$args);
    }

    /**
     * Register a filter.
     *
     * @param string $name The filter name
     * @param callable $callback The filter implementation
     * @return void
     */
    public function addFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }

    /**
     * Set a global template variable.
     *
     * @param string $key The variable name
     * @param mixed $value The variable value
     * @return void
     */
    public function setGlobal(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
    }

    /**
     * Set several global template variables.
     *
     * @param array<string, mixed> $variables The variables to set
     * @return void
     */
    public function setGlobals(array $variables): void
    {
        $this->globals = array_merge($this->globals, $variables);
    }

    /**
     * Clear every compiled template.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Compile a template if needed and execute it.
     *
     * @param string $file The template file path
     * @param array<string, mixed> $data Variables exposed to the template
     * @return string The rendered output
     * @throws RuntimeException If the template is missing or fails to render
     */
    private function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$file}");
        }

        $compiled = $this->cache->compiledPath($file);

        if (!$this->cache->isValid($file)) {
            $source = file_get_contents($file);
            if ($source === false) {
                throw new RuntimeException("Cannot read template file: {$file}");
            }

            $this->cache->store($file, $this->compiler->compile($source));
        }

        return $this->evaluate($compiled, array_merge($this->globals, $data));
    }

    /**
     * Execute a compiled template file in an isolated scope.
     *
     * @param string $__file The compiled template path
     * @param array<string, mixed> $__data Variables exposed to the template
     * @return string The rendered output
     * @throws RuntimeException If the template throws while rendering
     */
    private function evaluate(string $__file, array $__data): string
    {
        $__engine = $this;

        /*
         * EXTR_SKIP keeps a template variable named "__engine" or "__file"
         * from replacing the machinery this method needs to finish running.
         */
        extract($__data, EXTR_SKIP);

        $__level = ob_get_level();
        ob_start();

        try {
            include $__file;

            $output = ob_get_clean();

            return $output === false ? '' : $output;
        } catch (Throwable $throwable) {
            /*
             * A template that throws mid-block leaves its own buffers open;
             * unwinding to the level we started at keeps the failure from
             * corrupting output the caller had already produced.
             */
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }

            throw new RuntimeException(
                'Template execution error in ' . $__file . ': ' . $throwable->getMessage(),
                0,
                $throwable
            );
        }
    }

    /**
     * Register the filters every template can use.
     *
     * The string filters go through Str so they count characters instead of
     * bytes: truncate() used substr(), which cut multi-byte characters in half
     * and emitted invalid UTF-8 into the page.
     *
     * @return void
     */
    private function registerDefaultFilters(): void
    {
        $this->filters = [
            'upper' => static fn (mixed $v): string => Str::upper((string) $v),
            'lower' => static fn (mixed $v): string => Str::lower((string) $v),
            'capitalize' => static fn (mixed $v): string => Str::ucfirst((string) $v),
            'truncate' => static fn (mixed $v, int $length = 50, string $suffix = '...'): string
                => Str::truncate((string) $v, $length, $suffix),
            'length' => static fn (mixed $v): int
                => is_countable($v) ? count($v) : Str::length((string) $v),
            'reverse' => static fn (mixed $v): string => Str::reverse((string) $v),
            'escape' => static fn (mixed $v): string
                => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'json' => static fn (mixed $v): string
                => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'format' => static fn (mixed $v, string $format): string => sprintf($format, $v),
            'trim' => static fn (mixed $v): string => trim((string) $v),
            'abs' => static fn (mixed $v): int|float => abs($v),
            'round' => static fn (mixed $v, int $precision = 0): float => round((float) $v, $precision),
            'default' => static fn (mixed $v, mixed $fallback = ''): mixed
                => ($v === null || $v === '') ? $fallback : $v,
        ];
    }
}
