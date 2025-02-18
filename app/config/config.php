<?php

/**
 * Configuration file for the application.
 *
 * This file contains the application settings, such as database connection
 * and application name.
 *
 * @package SfphpProject
 * @subpackage app/config
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace SfphpProject\app\config;

use SfphpProject\src\Dotenv;

Dotenv::loadEnv(__DIR__ . "/../../.env");

/**
 * Application name.
 *
 * The name of the application.
 *
 * @var string
 */
define("APP_NAME", $_ENV["APP_NAME"] ?? "SfphpProject");

/**
 * Application version.
 *
 * The version of the application.
 *
 * @var string
 */
define("APP_VERSION", $_ENV["APP_VERSION"] ?? "1.0.0");

/**
 * Database host.
 *
 * The host of the database.
 *
 * @var string
 */
define("DB_HOST", $_ENV["DB_HOST"] ?? "localhost");

/**
 * Database name.
 *
 * The name of the database.
 *
 * @var string
 */
define("DB_DATABASE", $_ENV["DB_DATABASE"] ?? "sfphp");

/**
 * Database username.
 *
 * The username of the database.
 *
 * @var string
 */
define("DB_USERNAME", $_ENV["DB_USERNAME"] ?? "root");

/**
 * Database password.
 *
 * The password of the database.
 *
 * @var string
 */
define("DB_PASSWORD", $_ENV["DB_PASSWORD"] ?? "");
