<?php

namespace sfphp\Controllers;

use sfphp\Controllers\Controller;
use sfphp\Models\Agents;

class Main extends Controller
{
    public function index()
    {
       
        $this->view("layouts/html_header");
        $this->view("home");
        $this->view("layouts/html_footer");
    }
}