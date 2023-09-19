<?php

namespace sfphp\Controllers;

use sfphp\Controllers\BaseController;
use sfphp\Models\Agents;

class Main extends BaseController
{
    public function index()
    {

        $model = new Agents();
        $results = $model->get_total_agents();
        printData($results);

        $data["nome"] = "Fabio";
        $data["apelido"] = "Carneiro";
        
        $this->view("layouts/html_header");
        $this->view("home", $data);
        $this->view("layouts/html_footer");
    }
}