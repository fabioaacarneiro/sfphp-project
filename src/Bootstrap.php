<?php

namespace SfphpProject\src;

use SfphpProject\src\I18n\Translator;

/**
 * Wires an application to the framework.
 *
 * This exists because the framework became something you install rather than
 * something you clone. A package cannot read the `.env` of whatever installed
 * it, define that application's constants, or know where its views live —
 * those are the application's answers, and the application has to give them.
 *
 *     require __DIR__ . '/../vendor/autoload.php';
 *
 *     Bootstrap::load(dirname(__DIR__));
 *
 * One call, at the top of the front controller and of any console entry point.
 * Everything it does is optional in the sense that nothing here fails when a
 * file is absent: a fresh project with no `.env` boots, and the feature that
 * genuinely needs a value is the one that complains.
 *
 * Constants are defined only when they are not already defined, so an
 * application that wants to decide one itself simply defines it first.
 */
final class Bootstrap
{
    /** The application's root directory, once load() has been called. */
    private static ?string $basePath = null;

    /**
     * Load the application's environment and register its paths.
     *
     * @param string $basePath The application's root directory
     * @param array{env?: string|null, views?: string|null, lang?: string|null, cache?: string|null} $paths
     *     Overrides, each relative to $basePath or absolute; null disables that one
     * @return void
     */
    public static function load(string $basePath, array $paths = []): void
    {
        self::$basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        $env = array_key_exists('env', $paths) ? $paths['env'] : '.env';
        $views = array_key_exists('views', $paths) ? $paths['views'] : 'app/resources/views';
        $lang = array_key_exists('lang', $paths) ? $paths['lang'] : 'lang';
        $cache = array_key_exists('cache', $paths) ? $paths['cache'] : null;

        /*
         * Optional on purpose. A required .env made `require vendor/autoload.php`
         * fatal on a fresh clone, before the application had a chance to say
         * what was missing.
         */
        if ($env !== null) {
            Dotenv::loadEnv(self::path($env), required: false);
        }

        self::defineConstants();

        if ($lang !== null && is_dir(self::path($lang))) {
            Translator::addPath(self::path($lang));
        }

        Translator::setLocale(APP_LOCALE);
        Translator::setFallback('en');

        if ($views !== null) {
            View::setPaths([self::path($views)], $cache === null ? null : self::path($cache));
        }
    }

    /**
     * The application's root directory, or a path inside it.
     *
     * @param string|null $relative A path relative to the root
     * @return string The absolute path
     */
    public static function basePath(?string $relative = null): string
    {
        $base = self::$basePath ?? getcwd() ?: '.';

        return $relative === null ? $base : self::path($relative);
    }

    /**
     * Resolve a path that may already be absolute.
     *
     * @param string $path The path
     * @return string The absolute path
     */
    private static function path(string $path): string
    {
        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return (self::$basePath ?? getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Define the settings the framework reads, unless they already exist.
     *
     * These used to live in the application's own config file, which the
     * package loaded through Composer. That could not survive being installed:
     * a library that defines APP_NAME in whatever installs it is a library that
     * fights with its host. They are here, guarded, so the defaults travel with
     * the framework and the application still gets the last word.
     *
     * @return void
     */
    private static function defineConstants(): void
    {
        $constants = [
            'APP_NAME' => Env::get('APP_NAME') ?? 'SfphpProject',
            'APP_VERSION' => Env::get('APP_VERSION') ?? '1.0.0',
            'APP_ENV' => Env::get('APP_ENV') ?? 'production',
            'APP_LOCALE' => Env::get('APP_LOCALE') ?? 'en',
            'APP_LOCALES' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) (Env::get('APP_LOCALES') ?? 'en,pt_BR,es'))
            ))),
            'APP_TIMEZONE' => Env::get('APP_TIMEZONE') ?? 'UTC',
            'SESSION_DRIVER' => Env::get('SESSION_DRIVER') ?? 'native',
            'SESSION_LIFETIME' => (int) (Env::get('SESSION_LIFETIME') ?? 7200),
            'SESSION_ABSOLUTE_LIFETIME' => (int) (Env::get('SESSION_ABSOLUTE_LIFETIME') ?? 43200),
            'SESSION_TABLE' => Env::get('SESSION_TABLE') ?? 'sessions',
            'MAIL_DRIVER' => Env::get('MAIL_DRIVER') ?? 'log',
            'MAIL_HOST' => Env::get('MAIL_HOST') ?? 'localhost',
            'MAIL_PORT' => (int) (Env::get('MAIL_PORT') ?? 25),
            'MAIL_USERNAME' => Env::get('MAIL_USERNAME') ?? '',
            'MAIL_PASSWORD' => Env::get('MAIL_PASSWORD') ?? '',
            'MAIL_ENCRYPTION' => Env::get('MAIL_ENCRYPTION') ?? 'none',
            'MAIL_TIMEOUT' => (int) (Env::get('MAIL_TIMEOUT') ?? 30),
            'MAIL_ALLOW_PLAINTEXT_AUTH' => Env::get('MAIL_ALLOW_PLAINTEXT_AUTH') ?? false,
            'MAIL_FROM_ADDRESS' => Env::get('MAIL_FROM_ADDRESS') ?? '',
            'MAIL_FROM_NAME' => Env::get('MAIL_FROM_NAME') ?? '',
            'MAIL_ALWAYS_TO' => Env::get('MAIL_ALWAYS_TO') ?? '',
            'LOG_CHANNEL' => Env::get('LOG_CHANNEL') ?? 'stream',
            'LOG_PATH' => Env::get('LOG_PATH') ?? 'php://stderr',
            'CACHE_DRIVER' => Env::get('CACHE_DRIVER') ?? 'file',
            'CACHE_PATH' => Env::get('CACHE_PATH') ?? '',
            'AUTH_LOGIN_PATH' => Env::get('AUTH_LOGIN_PATH') ?? '/login',
            'CACHE_PREFIX' => Env::get('CACHE_PREFIX') ?? 'sfphp:cache:',
            'QUEUE_DRIVER' => Env::get('QUEUE_DRIVER') ?? 'database',
            'QUEUE_TABLE' => Env::get('QUEUE_TABLE') ?? '',
            'QUEUE_FAILED_TABLE' => Env::get('QUEUE_FAILED_TABLE') ?? '',
            'QUEUE_RESERVATION_SECONDS' => (int) (Env::get('QUEUE_RESERVATION_SECONDS') ?? 900),
            'REDIS_HOST' => Env::get('REDIS_HOST') ?? '127.0.0.1',
            'REDIS_PORT' => (int) (Env::get('REDIS_PORT') ?? 6379),
            'REDIS_PASSWORD' => Env::get('REDIS_PASSWORD') ?? '',
            'REDIS_DB' => (int) (Env::get('REDIS_DB') ?? 0),
            'REDIS_TIMEOUT' => (int) (Env::get('REDIS_TIMEOUT') ?? 2),
        ];

        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        /*
         * Defined after the others because its default depends on APP_ENV:
         * a developer wants to see everything, and production does not want
         * every debug line on disk.
         */
        if (!defined('LOG_LEVEL')) {
            define('LOG_LEVEL', Env::get('LOG_LEVEL') ?? (APP_ENV === 'development' ? 'debug' : 'info'));
        }
    }
}
