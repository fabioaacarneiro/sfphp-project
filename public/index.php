<?php

use bng\System\Router;

require_once "../vendor/autoload.php";

Router::dispatch();

$nomes = ["João", "Ana", "Carlos"];
$nome = "Fabio Carneiro";

printData($nome);