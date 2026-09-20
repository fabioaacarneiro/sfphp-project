<?php

namespace SfphpProject\src;

use SfphpProject\src\View\SfhtEngine;

/**
 * Renders application views and partials using the SFHT template engine.
 *
 * The engine is a singleton created on first use and caches compiled templates.
 */
final class View
{
    private static ?SfhtEngine $engine = null;

    /**
     * Render a view with the provided data.
     *
     * @param string $view The name of the view to render
     * @param array $data The data to pass to the view
     * @return void
     */
    public static function render(string $view, array $data): void
    {
        echo self::engine()->render($view, $data);
    }

    /**
     * Render a partial view with the provided data.
     *
     * The partial sees the including view's variables, unlike a component which
     * only sees its own data. This matches the legacy behavior.
     *
     * @param string $view The name of the partial view
     * @param array $data The data to pass to the partial view
     * @return void
     */
    public static function partial(string $view, array $data = []): void
    {
        echo self::engine()->render('partials.' . $view, $data);
    }

    /**
     * Get the SFHT engine singleton, creating it with the views directory.
     */
    private static function engine(): SfhtEngine
    {
        return self::$engine ??= new SfhtEngine(
            [__DIR__ . '/../app/resources/views'],
            sys_get_temp_dir() . '/sfphp-sfht-cache'
        );
    }
}
