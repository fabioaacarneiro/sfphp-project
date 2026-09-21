<?php

/**
 * Configuration file for the application.
 *
 * This file is loaded by Composer on every request, before anything else runs,
 * and is responsible for loading the .env file and defining the application
 * settings.
 *
 * @package SfphpProject
 * @subpackage app/config
 * @author Fabio Carneiro <fabioaacarneiro@gmail.com>
 * @copyright Copyright (c) 2022, Fabio Carneiro
 * @license https://opensource.org/licenses/MIT MIT License
 */

namespace SfphpProject\app\config;

use SfphpProject\src\Dotenv;

/*
 * Loaded as optional. Because this file runs from Composer's autoloader, a
 * required .env made `require vendor/autoload.php` fatal on a fresh clone,
 * before the application had a chance to say what was missing. Features that
 * genuinely need configuration (the database, JWT) fail on their own when the
 * value they need is absent.
 */
Dotenv::loadEnv(__DIR__ . "/../../.env", required: false);

/**
 * Application name.
 */
define("APP_NAME", $_ENV["APP_NAME"] ?? "SfphpProject");

/**
 * Application version.
 */
define("APP_VERSION", $_ENV["APP_VERSION"] ?? "1.0.0");

/**
 * Application environment: "production" or "development".
 */
define("APP_ENV", $_ENV["APP_ENV"] ?? "production");

/**
 * Application locale, used when the client asks for none the application has.
 */
define("APP_LOCALE", $_ENV["APP_LOCALE"] ?? "en");

/**
 * Locales the application offers, in order of preference.
 */
define("APP_LOCALES", array_values(array_filter(array_map(
    "trim",
    explode(",", (string) ($_ENV["APP_LOCALES"] ?? "en,pt_BR"))
))));

/*
 * The application's own catalogs. Registered here rather than in the front
 * controller so that the CLI, the test runner and a queue worker all see the
 * same messages a web request would.
 */
\SfphpProject\src\I18n\Translator::addPath(__DIR__ . "/../../lang");
\SfphpProject\src\I18n\Translator::setLocale(APP_LOCALE);
\SfphpProject\src\I18n\Translator::setFallback("en");
