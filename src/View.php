<?php

namespace SfphpProject\src;

use InvalidArgumentException;

/**
 * Renders application views and partials.
 */
final class View
{
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
        array $data
    ): void {
        self::renderFile(
            __DIR__ . '/../app/resources/views',
            $view,
            $data,
            'View'
        );
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
        self::renderFile(
            __DIR__ . '/../app/resources/views/partials',
            $view,
            $data,
            'Partial'
        );
    }

    /**
     * Render a PHP template from a trusted view directory.
     *
     * @param string $directory The base directory containing templates
     * @param string $view The template name relative to the base directory
     * @param array $data The data exposed to the template
     * @param string $type The template type used in error messages
     * @throws InvalidArgumentException If the template name is invalid or not found
     * @return void
     */
    private static function renderFile(
        string $directory,
        string $view,
        array $data,
        string $type
    ): void {
        if (!preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $view)) {
            throw new InvalidArgumentException("$type name \"$view\" is invalid.");
        }

        $path = "$directory/$view.php";
        if (!is_file($path)) {
            throw new InvalidArgumentException("$type $view not found.");
        }

        extract($data, EXTR_SKIP);

        require $path;
    }
}
