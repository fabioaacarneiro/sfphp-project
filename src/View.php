<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use RuntimeException;
use SfphpProject\src\View\SfhtEngine;

/**
 * Renders application views and partials using the SFHT template engine.
 *
 * make() and makePartial() return the rendered string; render() and partial()
 * echo it. The returning pair is what the HTTP layer uses, because a Response
 * needs a body it can carry rather than output that has already escaped to the
 * client. The echoing pair stays for templates and scripts that write directly,
 * and simply delegates, so the view-name validation lives in one place.
 */
final class View
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/';

    private static ?SfhtEngine $engine = null;

    /**
     * Render a view and return the result.
     *
     * @param string $view The name of the view to render
     * @param array<string, mixed> $data The data to pass to the view
     * @return string The rendered output
     * @throws InvalidArgumentException If the view name is invalid or not found
     */
    public static function make(string $view, array $data = []): string
    {
        return self::renderTemplate($view, $view, $data, 'View');
    }

    /**
     * Render a partial and return the result.
     *
     * @param string $view The name of the partial view
     * @param array<string, mixed> $data The data to pass to the partial view
     * @return string The rendered output
     * @throws InvalidArgumentException If the partial name is invalid or not found
     */
    public static function makePartial(string $view, array $data = []): string
    {
        return self::renderTemplate($view, "partials/$view", $data, 'Partial');
    }

    /**
     * Render a view and echo the result.
     *
     * @deprecated Use View::make() and return a Response instead.
     * @param string $view The name of the view to render
     * @param array<string, mixed> $data The data to pass to the view
     * @return void
     * @throws InvalidArgumentException If the view name is invalid or not found
     */
    public static function render(string $view, array $data = []): void
    {
        echo self::make($view, $data);
    }

    /**
     * Render a partial and echo the result.
     *
     * @deprecated Use View::makePartial() and return a Response instead.
     * @param string $view The name of the partial view
     * @param array<string, mixed> $data The data to pass to the partial view
     * @return void
     * @throws InvalidArgumentException If the partial name is invalid or not found
     */
    public static function partial(string $view, array $data = []): void
    {
        echo self::makePartial($view, $data);
    }

    /**
     * Validate a template name and render it.
     *
     * @param string $name The name as the caller wrote it, used in error messages
     * @param string $template The template path handed to the engine
     * @param array<string, mixed> $data The data to pass to the template
     * @param string $label The wording used in error messages
     * @return string The rendered output
     * @throws InvalidArgumentException If the name is invalid or the template is missing
     */
    private static function renderTemplate(
        string $name,
        string $template,
        array $data,
        string $label
    ): string {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("$label name \"$name\" is invalid.");
        }

        try {
            return self::engine()->render($template, $data);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException(
                "$label $name not found: " . $exception->getMessage()
            );
        }
    }

    /**
     * Get or create the SFHT engine instance.
     *
     * @return SfhtEngine The shared engine
     */
    private static function engine(): SfhtEngine
    {
        if (self::$engine === null) {
            $viewsPath = __DIR__ . '/../app/resources/views';
            $cachePath = sys_get_temp_dir() . '/sfphp-sfht-cache';
            self::$engine = new SfhtEngine([$viewsPath], $cachePath);
        }

        return self::$engine;
    }
}
