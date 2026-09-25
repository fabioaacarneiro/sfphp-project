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

use SfphpProject\app\controllers\ExamplesController;
use SfphpProject\app\controllers\MainController;
use SfphpProject\app\controllers\PhpxController;
use SfphpProject\app\controllers\StreamController;
use SfphpProject\src\Router;

// Homepage
Router::get("/", [MainController::class, 'index']);

// PHPX Component Demo
// Shows web pages built with .phpx components (PHP functions + SFHT templates)
Router::get('/phpx', [PhpxController::class, 'index'])->name('phpx');
Router::get('/phpx/postcode', [PhpxController::class, 'postcode'])->name('phpx.postcode');

// Examples
Router::get('/examples/streaming', [ExamplesController::class, 'streaming'])->name('examples.streaming');

// Streaming Demo
Router::get('/streams', [StreamController::class, 'index'])->name('streams');
Router::get('/stream', [StreamController::class, 'text']);
Router::get('/stream/sse', [StreamController::class, 'sse']);
