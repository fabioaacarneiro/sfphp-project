<?php

namespace sfphp\Controllers;
use sfphp\Controllers\BaseController;

class Main extends BaseController
{
    public function index()
    {
        $data["nome"] = "Fabio";
        $data["apelido"] = "Carneiro";
        
        $this->view("layouts/html_header");
        $this->view("home", $data);
        $this->view("layouts/html_footer");
    }
}