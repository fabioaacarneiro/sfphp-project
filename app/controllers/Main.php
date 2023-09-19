<?php

namespace bng\Controllers;

class Main
{
    public function index($id)
    {
        echo "Estou dentro do controlador Main - index";
        echo "<br>";
        echo "e o id informado foir $id";
    }
}