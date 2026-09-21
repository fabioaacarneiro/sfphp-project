<?php

use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Time;
use SfphpProject\src\Log\ErrorLogDriver;
use SfphpProject\src\Log\Level;
use SfphpProject\src\Log\LogManager;
use SfphpProject\src\Log\NullDriver;
use SfphpProject\src\Log\StreamDriver;
use SfphpProject\src\Queue\QueueManager;
use SfphpProject\src\I18n\Translator;
use SfphpProject\src\Queue\Job;

if (!function_exists('cache')) {
    function cache(): CacheManager
    {
        static $cache = null;

        if ($cache === null) {
            $cache = new CacheManager();
        }

        return $cache;
    }
}

if (!function_exists('now')) {
    /**
     * The current instant, in UTC.
     *
     * Everything the framework stores and computes is UTC, so this is what a
     * timestamp written by application code should come from. See Time.
     *
     * @return DateTimeImmutable The current instant
     */
    function now(): DateTimeImmutable
    {
        return Time::now();
    }
}

if (!function_exists('logger')) {
    /**
     * The application's logger.
     *
     * Built once from LOG_CHANNEL, LOG_PATH and LOG_LEVEL, so that a request,
     * a queue worker and a console command all write to the same place without
     * any of them being told where that is.
     *
     * @return LogManager The shared logger
     */
    function logger(): LogManager
    {
        static $log = null;

        if ($log !== null) {
            return $log;
        }

        $channel = defined('LOG_CHANNEL') ? LOG_CHANNEL : 'stream';
        $path = defined('LOG_PATH') ? LOG_PATH : 'php://stderr';

        $driver = match ($channel) {
            'error_log' => new ErrorLogDriver(),
            'null' => new NullDriver(),
            default => new StreamDriver($path),
        };

        $minimum = Level::fromName(
            defined('LOG_LEVEL') ? LOG_LEVEL : null,
            defined('APP_ENV') && APP_ENV === 'development' ? Level::Debug : Level::Info
        );

        return $log = new LogManager($driver, $minimum);
    }
}

if (!function_exists('dispatch')) {
    function dispatch(Job $job, ?int $delay = null): string
    {
        static $queue = null;

        if ($queue === null) {
            $queue = new QueueManager();
        }

        return $queue->push($job, $delay);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a message key.
     *
     * @param string $key The message key, as "group.entry"
     * @param array<string, string|int|float> $replace Placeholder values
     * @param string|null $locale The locale to read, or null for the active one
     * @return string The translated message
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        return Translator::get($key, $replace, $locale);
    }
}

if (!function_exists('trans_choice')) {
    /**
     * Translate a message key, choosing the form that matches a count.
     *
     * @param string $key The message key
     * @param int $count The number deciding the form
     * @param array<string, string|int|float> $replace Placeholder values
     * @param string|null $locale The locale to read, or null for the active one
     * @return string The translated message
     */
    function trans_choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        return Translator::choice($key, $count, $replace, $locale);
    }
}

if (!function_exists('locale')) {
    /**
     * Get the active locale.
     *
     * @return string The locale
     */
    function locale(): string
    {
        return Translator::locale();
    }
}
