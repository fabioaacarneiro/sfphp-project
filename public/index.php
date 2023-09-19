<?php

use bng\Controllers\Main;

require_once "../vendor/autoload.php";

echo APP_NAME;
echo "<br>";

$a = new Main();
echo $a->teste();