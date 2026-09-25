<?php

use SfphpProject\src\Config;
use SfphpProject\src\Debug\Dumper;
use SfphpProject\src\Debug\HtmlDump;
use SfphpProject\src\Debug\TextDump;
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
                (string) Config::get('MAIL_ENCRYPTION', 'none'),
                Config::int('MAIL_TIMEOUT', 30),
                allowPlaintextAuth: filter_var(Config::get('MAIL_ALLOW_PLAINTEXT_AUTH', false), FILTER_VALIDATE_BOOLEAN)
            ),
            'mail' => new MailDriver(),
            'array' => new MailArrayDriver(),
            'log' => new MailLogDriver(),
            /*
             * A misspelt driver still logs rather than taking the application
             * down, but it says so: MAIL_DRIVER=smpt used to send nothing
             * without a word, in production as anywhere.
             */
            default => (static function () use ($driver): MailLogDriver {
                logger()->warning(sprintf('MAIL_DRIVER "%s" is not a mail driver; messages are only being logged.', (string) $driver));

                return new MailLogDriver();
            })(),
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

if (!function_exists('dump')) {
    /**
     * Show one or more values and carry on.
     *
     * In a request the values are appended to the page; in a terminal they go
     * to standard output. Nothing stops, which is what separates this from
     * dd(): use it to watch a loop, use dd() to stop and look.
     *
     * @param mixed ...$values The values to show
     * @return void
     */
    function dump(mixed ...$values): void
    {
        $caller = Dumper::caller();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, TextDump::render($values, $caller));

            return;
        }

        if (!sfphp_dump_allowed()) {
            sfphp_dump_to_log($values, $caller, 'dump');

            return;
        }

        /*
         * A fragment, not a page: it goes into a document that already has
         * one, so a second <!DOCTYPE html> would be malformed. dd() sends the
         * page, because dd() is the response.
         *
         * Once the response has started — inside a stream's producer — the
         * fragment is written where it happens. Before that it waits for the
         * Emitter, which puts it into the page: echoing it here sent output
         * ahead of the headers, and the response itself was lost.
         */
        $fragment = HtmlDump::fragment($values, $caller);

        if (headers_sent()) {
            echo $fragment;

            return;
        }

        \SfphpProject\src\Debug\PendingDumps::add($fragment);
    }
}

if (!function_exists('dd')) {
    /**
     * Show one or more values and stop.
     *
     * The response is replaced by a page showing what was dumped, rather than
     * the dump being squeezed in among whatever the page had already printed.
     * That is the whole point: you asked to stop and look, so what you are
     * looking at is the only thing on screen.
     *
     * @param mixed ...$values The values to show
     * @return never
     */
    function dd(mixed ...$values): never
    {
        $caller = Dumper::caller();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, TextDump::render($values, $caller));

            exit(1);
        }

        if (!sfphp_dump_allowed()) {
            /*
             * Production. The dump would be a page handed to a visitor showing
             * whatever was passed to it — a user record, request headers,
             * configuration. It goes to the log, where the LogManager redacts
             * passwords and tokens, and the visitor gets the ordinary error
             * page: a forgotten dd() becomes something you can see and nothing
             * they can.
             */
            sfphp_dump_to_log($values, $caller, 'dd');

            throw new RuntimeException(
                'dd() was called in a production environment. The dump was written to the log instead.'
            );
        }

        /*
         * Headers first: the page is HTML whatever the action was going to
         * answer, and a dump inside a response already declared as JSON is a
         * download prompt rather than a screen.
         */
        if (!headers_sent()) {
            /*
             * 200, not 500. A dump is something you asked for, not a failure:
             * a 500 makes the browser's network panel mark the request red,
             * and a proxy or a container platform configured to replace error
             * bodies with its own page would replace the dump with it.
             */
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo HtmlDump::render($values, $caller);

        exit(1);
    }
}

if (!function_exists('sfphp_dump_allowed')) {
    /**
     * Whether a dump may be written to the response.
     *
     * @return bool True outside production
     */
    function sfphp_dump_allowed(): bool
    {
        return Config::get('APP_ENV', 'production') !== 'production';
    }
}

if (!function_exists('sfphp_dump_to_log')) {
    /**
     * Record a dump that must not reach the response.
     *
     * @param list<mixed> $values The values dumped
     * @param array{file: string, line: int}|null $caller Where it was called
     * @param string $function Which helper was used
     * @return void
     */
    function sfphp_dump_to_log(array $values, ?array $caller, string $function): void
    {
        logger()->warning($function . '() called in production', [
            'file' => $caller['file'] ?? null,
            'line' => $caller['line'] ?? null,
            'dump' => TextDump::render($values, null, false),
        ]);
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

if (!function_exists('state')) {
    /**
     * The initial state of a client-side scope, as an attribute value.
     *
     * Interface state lives in the browser, but its first values usually come
     * from the server — the record being edited, the items already in the
     * cart. This encodes them so that `@state` can read them back:
     *
     *     <div @state="{{ state(['open' => false, 'items' => $items]) }}">
     *
     * A plain string on purpose, so `{{ }}` escapes it: the quotes JSON needs
     * become entities inside the attribute and the browser hands them back
     * intact. Returning Sfht would place unescaped quotes in an attribute,
     * which is how markup ends up broken or, with the wrong value, forged.
     *
     * @param array<string, mixed> $values The starting values
     * @return string JSON, ready to be printed with {{ }}
     * @throws JsonException When a value cannot be encoded
     */
    function state(array $values): string
    {
        return (string) json_encode(
            $values,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

if (!function_exists('lang_tag')) {
    /**
     * The active locale as an HTML language attribute.
     *
     * A catalog is named pt_BR and a `lang` attribute wants pt-BR. The
     * framework was converting between the two in four places, which is three
     * chances to forget.
     *
     * @return string A BCP 47 tag
     */
    function lang_tag(): string
    {
        return str_replace('_', '-', locale());
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
