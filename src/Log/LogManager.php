<?php

namespace SfphpProject\src\Log;

use Throwable;

/**
 * The logger an application talks to.
 *
 * It adds three things to a driver: the level methods, filtering by minimum
 * level, and a shared context merged into every record. The shared context is
 * what makes the logs of one request joinable — the request id goes in once,
 * at the edge, and every line written afterwards carries it without any code
 * in between having to know it exists.
 *
 *     logger()->info('order placed', ['order_id' => $order->id]);
 *
 * > **Under a persistent runtime the shared context must be reset per request.**
 * > It lives in an object that outlives a request in a Swoole or FrankenPHP
 * > worker, so one visitor's request id — and anything else put there — would
 * > follow the next visitor's logs. LogRequests calls forgetContext() at the
 * > start of every request and is the explicit owner of that reset, exactly as
 * > SetLocale is for the active language and Authenticate for the user.
 */
final class LogManager
{
    /**
     * Keys whose values never belong in a log.
     *
     * Structured logging invites passing whole arrays through, and the request
     * body of a login form is the first array anyone reaches for. Redacting by
     * key is crude, and it is the difference between a password reaching a log
     * aggregator and not.
     *
     * @var list<string>
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'secret',
        'token',
        '_token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'api-key',
        'x-api-key',
        'client_secret',
        'passwd',
        'private_key',
        'x-csrf-token',
        'x-xsrf-token',
        'remember_token',
        'authorization',
        'auth',
        'cookie',
        'set-cookie',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'cpf',
    ];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var list<string> */
    private array $redacted;

    /**
     * Create the manager.
     *
     * @param Logger|null $driver Where records go, or null for stderr
     * @param Level $minimum The least severe level that is kept
     */
    public function __construct(
        private ?Logger $driver = null,
        private Level $minimum = Level::Debug
    ) {
        $this->driver ??= new StreamDriver();
        $this->redacted = self::REDACTED;
    }

    /**
     * Send records somewhere else from now on.
     *
     * @param Logger $driver The destination
     * @return static This manager
     */
    public function driver(Logger $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Set the least severe level that is kept.
     *
     * @param Level $minimum The minimum level
     * @return static This manager
     */
    public function minimumLevel(Level $minimum): static
    {
        $this->minimum = $minimum;

        return $this;
    }

    /**
     * Add values carried by every record from now on.
     *
     * @param array<string, mixed> $context The values to add
     * @return static This manager
     */
    public function withContext(array $context): static
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * The values currently carried by every record.
     *
     * @return array<string, mixed> The shared context
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Drop the shared context.
     *
     * Called at the start of every request by LogRequests. Without it, a
     * worker that serves many requests would attach one visitor's request id to
     * the next visitor's logs.
     *
     * @return static This manager
     */
    public function forgetContext(): static
    {
        $this->context = [];

        return $this;
    }

    /**
     * Also redact these keys, on top of the ones redacted by default.
     *
     * @param string ...$keys The key names, matched case-insensitively
     * @return static This manager
     */
    public function redact(string ...$keys): static
    {
        foreach ($keys as $key) {
            $this->redacted[] = strtolower($key);
        }

        return $this;
    }

    /**
     * Write a record, if its level passes the minimum.
     *
     * @param Level $level The record's severity
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function log(Level $level, string $message, array $context = []): void
    {
        if (!$level->atLeast($this->minimum)) {
            return;
        }

        $this->driver->write(
            $level,
            $message,
            $this->scrub(array_merge($this->context, $context))
        );
    }

    /**
     * Write a record describing a failure.
     *
     * The exception is flattened into fields rather than passed through, so a
     * collector can filter on the class or the file without parsing a message,
     * and so nothing tries to serialise an object graph that may hold a
     * database connection.
     *
     * @param Throwable $throwable The failure
     * @param Level $level The severity to record it at
     * @param array<string, mixed> $context Further detail
     * @return void
     */
    public function exception(
        Throwable $throwable,
        Level $level = Level::Error,
        array $context = []
    ): void {
        $this->log($level, $throwable->getMessage(), array_merge($context, [
            'exception' => $throwable::class,
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ]));
    }

    /**
     * Replace the values of sensitive keys, at any depth.
     *
     * @param array<string, mixed> $context The context being written
     * @return array<string, mixed> The context with sensitive values replaced
     */
    private function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $this->redacted, true)) {
                $context[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->scrub($value);

                continue;
            }

            /*
             * An object is written as its fields, and those need the same
             * treatment: a model or a DTO passed whole carried its password
             * past the key check, which only looked at arrays. Anything that
             * is not a plain data object — a closure, a resource wrapper — is
             * named rather than dumped.
             */
            if (is_object($value) && !$value instanceof \Throwable && !$value instanceof \DateTimeInterface && !$value instanceof \Stringable) {
                $fields = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
                $context[$key] = is_array($fields) ? $this->scrub($fields) : $fields;
            }
        }

        return $context;
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    /**
     * @param string $message What happened
     * @param array<string, mixed> $context Detail belonging to this record
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }
}
