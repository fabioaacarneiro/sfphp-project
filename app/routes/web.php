<?php

/**
 * Web Routes — Views & Templates
 *
 * All routes here return HTML pages (views/templates).
 * These routes are rendered using SFHT templates or PHP views.
 *
 * @package SfphpProject
 * @subpackage app/routes
 */

use SfphpProject\src\Router;

// Homepage
Router::get("/", "MainController", "index");

// PHPX Component Demo
// Shows web pages built with .phpx components (PHP functions + SFHT templates)
Router::get('/phpx', 'PhpxController', 'index')->name('phpx');
Router::get('/phpx/postcode', 'PhpxController', 'postcode')->name('phpx.postcode');
