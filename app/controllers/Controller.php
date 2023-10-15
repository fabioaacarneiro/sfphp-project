<?php

namespace sfphp\Controllers;

abstract class Controller
{
    public function view($view, $data = [])
    {
        // check if data is array
        if (!is_array($data)) {
            die("Data is not an array: " . var_dump($data));
        }

        // transforms data into variable
        extract($data);

        // includes the file is exists
        if (file_exists("../app/views/$view.php")) {
            require_once "../app/views/$view.php";
        } else {
            die("View does not exists: " . $view);
        }
    }
}