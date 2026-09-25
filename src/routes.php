<?php

/**
 * The example application's routes.
 *
 * A route is a method, a path and an action — the controller class and the
 * method to call, as a pair:
 *
 *   Router::get('/users', [UserController::class, 'index']);
 *   Router::post('/users', [UserController::class, 'store']);
 *   Router::put('/users/id:number', [UserController::class, 'update']);
 *   Router::delete('/users/id:number', [UserController::class, 'destroy']);
 *
 * A path parameter is name:type, with number, alpha or alphanum as the type:
 *
 *   Router::get('/users/name:alpha', [UserController::class, 'byName']);
 *
 * @package SfphpProject
 * @subpackage src
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

use SfphpProject\app\controllers\MainController;
use SfphpProject\app\controllers\PhpxController;
use SfphpProject\src\Router;

Router::get("/", [MainController::class, 'index']);
Router::get('/phpx', [PhpxController::class, 'index'])->name('phpx');
Router::get('/phpx/postcode', [PhpxController::class, 'postcode'])->name('phpx.postcode');
