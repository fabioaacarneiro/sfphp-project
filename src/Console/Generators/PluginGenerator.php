<?php

namespace SfphpProject\src\Console\Generators;

use InvalidArgumentException;
use SfphpProject\src\View\PageScripts;

/**
 * Generates an SFJS plugin: app/resources/js/plugins/<name>.js.
 *
 * The name is the attribute — make:plugin countdown writes the plugin for
 * @countdown — so it is checked the way sf.plugin checks it, here, where the
 * mistake costs nothing, rather than in the browser after a build.
 */
final class PluginGenerator extends GeneratorBase
{
    /**
     * The names sf.plugin refuses, as its RESERVED list has them. A test keeps
     * the two lists the same.
     */
    public const RESERVED = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
        'foreach', 'endforeach', 'forelse', 'empty', 'endforelse',
        'for', 'endfor', 'while', 'endwhile',
        'extends', 'block', 'endblock', 'include', 'includewhen',
        'component', 'use', 'php', 'endphp',
        'script', 'scripts', 'sfcss', 'sfjs',
        'get', 'post', 'put', 'patch', 'delete', 'target', 'swap', 'trigger',
        'error-target', 'into', 'loading', 'state', 'show', 'text', 'class',
        'model', 'on', 'toggle', 'validate', 'key',
        'sse', 'method', 'body', 'abort', 'events', 'done',
        'modal', 'dismiss', 'tabs', 'tooltip', 'tooltip-placement',
        // Registered by SFJS itself.
        'stream',
    ];

    /**
     * Write the plugin.
     *
     * @param string $name The attribute, without the @: "countdown", "chart-line"
     * @return string The file written
     * @throws InvalidArgumentException If the name is not one sf.plugin accepts
     */
    public function generate(string $name): string
    {
        $name = strtolower(trim($name));

        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "\"{$name}\" is not a plugin name. Use lower-case letters, digits and hyphens, starting with a letter: countdown, chart-line."
            );
        }

        if (in_array($name, self::RESERVED, true) || str_starts_with($name, 'hx')) {
            throw new InvalidArgumentException(
                "@{$name} is reserved — SFJS or the SFPHP templates already read it. Choose another name."
            );
        }

        $filePath = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/' . PageScripts::PLUGINS_SOURCE . '/' . $name . '.js';

        @mkdir(dirname($filePath), 0755, true);

        $content = <<<'JS'
/**
 * @{NAME} — an SFJS plugin.
 *
 *     <div @{NAME}="a value"></div>
 *
 * attach runs once for every element with the attribute: on the page, and in
 * every fragment a swap brings in. What it sets up through ctx — ctx.on,
 * ctx.every, ctx.after, ctx.debounce, ctx.req — is undone by itself when the
 * element leaves the page.
 *
 * Run ./sfphp js:build after editing, and load it with @sfjs.
 */
sf.plugin('{NAME}', {
  attach(el, ctx) {
    el.textContent = ctx.value;
  },

  // Optional: a swap changed the attribute's value. Without it, the plugin is
  // detached and attached again.
  update(el, ctx) {
    el.textContent = ctx.value;
  },
});

JS;

        return $this->writeFile($filePath, str_replace('{NAME}', $name, $content));
    }
}
