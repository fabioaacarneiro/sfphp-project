<?php

namespace SfphpProject\src;

use Exception;

class View
{
    /**
     * Render a view with the provided data.
     *
     * @param string $view The name of the view to render
     * @param array $data The data to pass to the view
     * @throws Exception If the view file does not exist
     * @return void
     */
    public static function render(
        string $view,
        array $data
    ): void {
        extract($data);
        $path = __DIR__ . "/../app/resources/views/$view.php";

        if (!file_exists($path)) {
            throw new Exception("View $view not found");
        }

        /*
         * require, not require_once: a view or partial may legitimately be
         * rendered more than once in the same request (a row partial inside a
         * loop, for instance). require_once would silently output nothing from
         * the second render onwards.
         */
        require $path;
    }

    /**
     * Render a partial view with the provided data.
     *
     * @param string $view The name of the partial view
     * @param array $data The data to pass to the partial view
     * @throws Exception If the partial view file does not exist
     * @return void
     */
    public static function partial(
        string $view,
        array $data = []
    ): void {
        extract($data);
        $path = __DIR__ . "/../app/resources/views/partials/$view.php";

        if (!file_exists($path)) {
            throw new Exception("Partial $view not found");
        }

        require $path;
    }
}
