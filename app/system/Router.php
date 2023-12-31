<?php

namespace sfphp\System;

use Exception;

abstract class Router
{
    public static function dispatch()
    {
        // main route values
        $httpverb = $_SERVER["REQUEST_METHOD"];
        $controller = "main";
        $method = "index";

        // check uri parameters
        if (isset($_GET["ct"])) {
            $controller = $_GET["ct"];
        }

        if (isset($_GET["mt"])) {
            $method = $_GET["mt"];
        }

        // methods parameters
        $parameters = $_GET;

        // remove controller from parameters
        if (key_exists("ct", $parameters)) {
            unset($parameters["ct"]);
        }
        
        // remove method from parameters
        if (key_exists("mt", $parameters)) {
            unset($parameters["mt"]);
        }

        // try to instanciate the controller and execute the method
        try {
            $class = "sfphp\Controllers\\" . ucfirst($controller);
            $controller = new $class();
            $controller->$method(...$parameters);
        } catch (Exception $err) {
            die($err->getMessage());
        }

    }
}
