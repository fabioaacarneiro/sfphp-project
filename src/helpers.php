<?php

use SfphpProject\src\Config;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Mail\ArrayDriver as MailArrayDriver;
use SfphpProject\src\Mail\LogDriver as MailLogDriver;
use SfphpProject\src\Mail\MailDriver;
use SfphpProject\src\Mail\MailManager;
use SfphpProject\src\Mail\SmtpDriver;
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
    /**
     * The application's cache.
     *
     * Built once from CACHE_DRIVER, like the logger from LOG_CHANNEL and the
     * mailer from MAIL_DRIVER. It read nothing for a long time and always gave
     * back the file driver, which under more than one instance is the wrong
     * answer for everything that shares state through it: a revoked token, a
     * rate limit counter and a session were each kept per machine.
     *
     * @return CacheManager The shared cache
     */
    function cache(): CacheManager
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        return $cache = CacheManager::fromConfig();
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

        $channel = Config::get('LOG_CHANNEL', 'stream');
        $path = Config::get('LOG_PATH', 'php://stderr');

        $driver = match ($channel) {
            'error_log' => new ErrorLogDriver(),
            'null' => new NullDriver(),
            default => new StreamDriver($path),
        };

        $minimum = Level::fromName(
            Config::get('LOG_LEVEL', null),
            Config::get('APP_ENV') === 'development' ? Level::Debug : Level::Info
        );

        return $log = new LogManager($driver, $minimum);
    }
}

if (!function_exists('mailer')) {
    /**
     * The application's mailer.
     *
     * Named mailer() rather than mail(), which is a function PHP already
     * defines and the SMTP driver still relies on for its own fallback.
     *
     * @return MailManager The shared mailer
     */
    function mailer(): MailManager
    {
        static $mailer = null;

        if ($mailer !== null) {
            return $mailer;
        }

        $driver = Config::get('MAIL_DRIVER', 'log');

        $transport = match ($driver) {
            'smtp' => new SmtpDriver(
                Config::get('MAIL_HOST', 'localhost'),
                Config::int('MAIL_PORT', 25),
                Config::get('MAIL_USERNAME') ?: null,
                Config::get('MAIL_PASSWORD') ?: null,
                Config::get('MAIL_ENCRYPTION', 'none'),
                Config::int('MAIL_TIMEOUT', 30)
            ),
            'mail' => new MailDriver(),
            'array' => new MailArrayDriver(),
            default => new MailLogDriver(),
        };

        $mailer = new MailManager(
            $transport,
            Config::get('MAIL_FROM_ADDRESS') ?: null,
            Config::get('MAIL_FROM_NAME', '')
        );

        if (Config::string('MAIL_ALWAYS_TO') !== '') {
            $mailer->alwaysTo(Config::string('MAIL_ALWAYS_TO'));
        }

        return $mailer;
    }
}

if (!function_exists('queue')) {
    /**
     * The application's queue.
     *
     * Built once from QUEUE_DRIVER. The worker command and dispatch() share it,
     * so a job pushed by a request and a job taken by a worker cannot end up on
     * different queues because one of them was constructed by hand.
     *
     * @return QueueManager The shared queue
     */
    function queue(): QueueManager
    {
        static $queue = null;

        if ($queue !== null) {
            return $queue;
        }

        return $queue = QueueManager::fromConfig();
    }
}

if (!function_exists('dispatch')) {
    /**
     * Put a job on the queue.
     *
     * @param Job $job The job
     * @param int|null $delay Seconds to wait before it may run
     * @return string The job's id
     */
    function dispatch(Job $job, ?int $delay = null): string
    {
        return queue()->push($job, $delay);
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
