<?php

/**
 * The example application's entry into the framework.
 *
 * Loaded by Composer through `autoload-dev`, so it runs in this checkout and
 * never in a project that installs the package — which is the point: an
 * application's `.env` and constants belong to that application, and a library
 * that defines APP_NAME in whatever installs it is a library that fights with
 * its host.
 *
 * A project consuming the package does the same thing explicitly, at the top of
 * its front controller:
 *
 *     require __DIR__ . '/../vendor/autoload.php';
 *
 *     Bootstrap::load(dirname(__DIR__));
 *
 * @package SfphpProject
 * @subpackage app/config
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace SfphpProject\app\config;

use SfphpProject\src\Bootstrap;

Bootstrap::load(dirname(__DIR__, 2));
