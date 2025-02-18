<?php

/**
 * This file is responsible for defining all routes of the system.
 *
 * Here you can define all routes of the system. The routes are defined
 * using the Router class of the SFPHP framework. The syntax is very
 * simple and intuitive.
 *
 * Example:
 *   Router::get("/users", "UserController", "getAll");
 *   Router::post("/users", "UserController", "createUser");
 *   Router::put("/users/id:number", "UserController", "updateUser");
 *   Router::delete("/users/id:number", "UserController", "deleteUser");
 *
 * The first parameter is the route path, the second parameter is the
 * controller name and the third parameter is the controller method.
 *
 * The routes can also be defined using regular expressions.
 *
 * Example:
 *   Router::get("/users/name:alpha", "UserController", "getUserByName");
 *
 * The regular expression is defined using the syntax of the PHP
 * language. The regular expression is used to validate the route
 * parameters.
 *
 * @package SfphpProject
 * @subpackage src
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

use SfphpProject\src\Router;

Router::get("/", "MainController", "index");