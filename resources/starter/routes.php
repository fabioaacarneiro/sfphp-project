<?php

/**
 * Where the application's routes are declared.
 *
 * The signature is always (url, controller, action) — three arguments, not
 * 'Controller@action'. The controller is resolved under the namespace the front
 * controller passed to the Router.
 */

use SfphpProject\src\Router;

Router::get('/', 'WelcomeController', 'index')->name('home');
