<?php

namespace bng\Controllers;

abstract class BaseController
{
    public function view()
    {
        // "../" volta para o nível do projeto
        require_once "../app/views/layouts/html_header.php";
        echo "teste";
        require_once "../app/views/layouts/html_footer.php";
    }
}