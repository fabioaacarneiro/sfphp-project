<?php

namespace SfphpProject\app\controllers;

use SfphpProject\app\controllers\BaseController;
use SfphpProject\src\View;

class MainController extends BaseController
{
    public function index()
    {
        $data = [
            "title" => "FSPHP",
        ];
        
        View::render("home", $data);
    }
}