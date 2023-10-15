<?php

$settings = require_once __DIR__ . "/settings.php";
$info = $settings["info"];

define("APP_NAME", $info["APP_NAME"]);
define("APP_VERSION", $info["APP_VERSION"]);

if ($file = file_exists(__DIR__ . "/.env")) {

    $dotenv = Dotenv\Dotenv::createImmutable($file);
    $dotenv->load();
    
    // database setup with .env if exists
    define("MYSQL_HOST", $_ENV["MYSQL_HOST"]);
    define("MYSQL_DATABASE", $_ENV["MYSQL_DATABASE"]);
    define("MYSQL_USER", $_ENV["MYSQL_USER"]);
    define("MYSQL_PASSWORD", $_ENV["MYSQL_PASSWORD"]);
    
} else {

    $database = $settings["database"];

    // database setup with .env not if exists
    define("MYSQL_HOST", $database["MYSQL_HOST"]);
    define("MYSQL_DATABASE", $database["MYSQL_DATABASE"]);
    define("MYSQL_USER", $database["MYSQL_USER"]);
    define("MYSQL_PASSWORD", $database["MYSQL_PASSWORD"]);
}
