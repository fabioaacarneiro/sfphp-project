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
    explode(",", (string) ($_ENV["APP_LOCALES"] ?? "en,pt_BR,es"))
))));

/*
 * The runtime is UTC, and deliberately not configurable.
 *
 * A naive timestamp in a database column is only an instant if something says
 * which zone wrote it. If that answer is "whatever the server was set to", then
 * moving the server — or adding a second one — silently changes what every
 * existing row means, and no later fix can recover the intent because it was
 * never written down. APP_TIMEZONE below decides how times are *shown*; it does
 * not decide how they are stored.
 */
date_default_timezone_set("UTC");

/**
 * The zone times are displayed in. Storage is always UTC.
 */
define("APP_TIMEZONE", $_ENV["APP_TIMEZONE"] ?? "UTC");

/**
 * Where sessions are stored: "native" (PHP's files), "database" or "cache".
 *
 * Native files are local to one machine, so two application instances cannot
 * see each other's sessions — which is what forces sticky sessions on a load
 * balancer. "cache" with a shared driver, or "database", removes that.
 */
define("SESSION_DRIVER", $_ENV["SESSION_DRIVER"] ?? "native");

/**
 * Seconds of inactivity before a session ends. 0 disables the idle timeout.
 */
define("SESSION_LIFETIME", (int) ($_ENV["SESSION_LIFETIME"] ?? 7200));

/**
 * Seconds since creation before a session ends, however busy it has been.
 * 0 disables the absolute timeout.
 */
define("SESSION_ABSOLUTE_LIFETIME", (int) ($_ENV["SESSION_ABSOLUTE_LIFETIME"] ?? 43200));

/**
 * The table the "database" session driver writes to.
 */
define("SESSION_TABLE", $_ENV["SESSION_TABLE"] ?? "sessions");

/**
 * Where log records go: "stream", "error_log" or "null".
 */
define("LOG_CHANNEL", $_ENV["LOG_CHANNEL"] ?? "stream");

/**
 * The stream or file the "stream" channel writes to.
 *
 * Defaults to stderr, which needs no directory to exist and no permission to
 * be granted, and is where a container expects to find an application's logs.
 */
define("LOG_PATH", $_ENV["LOG_PATH"] ?? "php://stderr");

/**
 * The least severe level that is written: debug, info, notice, warning, error,
 * critical, alert or emergency. Defaults to debug in development and info in
 * production.
 */
define("LOG_LEVEL", $_ENV["LOG_LEVEL"] ?? (APP_ENV === "development" ? "debug" : "info"));

/*
 * The application's own catalogs. Registered here rather than in the front
 * controller so that the CLI, the test runner and a queue worker all see the
 * same messages a web request would.
 */
\SfphpProject\src\I18n\Translator::addPath(__DIR__ . "/../../lang");
\SfphpProject\src\I18n\Translator::setLocale(APP_LOCALE);
\SfphpProject\src\I18n\Translator::setFallback("en");
