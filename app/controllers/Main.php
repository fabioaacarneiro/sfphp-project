<?php

namespace sfphp\Controllers;

use sfphp\Controllers\BaseController;
use sfphp\Models\Agents;

class Main extends BaseController
{
    public function index()
    {
       
        $this->view("layouts/html_header");
        $this->view("home");
        $this->view("layouts/html_footer");
    }
}