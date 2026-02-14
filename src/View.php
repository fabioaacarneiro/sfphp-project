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
    ) {
        extract($data);
        $path = __DIR__ . "/../app/resources/views/$view.php";
        
        if (!file_exists($path)) {
            throw new Exception("View $view not found");
        }
            
        require_once $path;
    }
}
