<?php

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define("APP_NAME", "Basic Name Gathering");

// database
define("MYSQL_HOST", $_ENV["MYSQL_HOST"]);
define("MYSQL_DATABASE", $_ENV["MYSQL_DATABASE"]);
define("MYSQL_USER", $_ENV["MYSQL_USER"]);
define("MYSQL_PASSWORD", $_ENV["MYSQL_PASSWORD"]);
