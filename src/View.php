<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use SfphpProject\src\View\SfhtEngine;

/**
 * Renders application views and partials using SFHT template engine.
 */
final class View
{
    private static ?SfhtEngine $engine = null;

    /**
     * Get or create SFHT engine instance.
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

    /**
     * Render a view with the provided data.
     *
     * @param string $view The name of the view to render
     * @param array $data The data to pass to the view
     * @throws InvalidArgumentException If the view name is invalid or not found
     * @return void
     */
    public static function render(
        string $view,
        array $data = []
    ): void {
        if (!preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $view)) {
            throw new InvalidArgumentException("View name \"$view\" is invalid.");
        }

        try {
            echo self::engine()->render($view, $data);
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException("View $view not found: " . $e->getMessage());
        }
    }

    /**
     * Render a partial view with the provided data.
     *
     * @param string $view The name of the partial view
     * @param array $data The data to pass to the partial view
     * @throws InvalidArgumentException If the partial view name is invalid or not found
     * @return void
     */
    public static function partial(
        string $view,
        array $data = []
    ): void {
        if (!preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $view)) {
            throw new InvalidArgumentException("Partial name \"$view\" is invalid.");
        }

        try {
            echo self::engine()->render("partials/$view", $data);
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException("Partial $view not found: " . $e->getMessage());
        }
    }
}
