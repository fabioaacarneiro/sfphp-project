<?php

use SfphpProject\src\Cache\CacheManager;
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
