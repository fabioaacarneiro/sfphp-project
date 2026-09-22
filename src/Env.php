<?php

namespace SfphpProject\src;

/**
 * Reads a value from the environment, wherever PHP decided to put it.
 *
 * The framework read `$_ENV` and nothing else, which works until it does not:
 * `variables_order` decides whether PHP populates `$_ENV` at all, and the
 * shipped `php.ini-production` leaves the `E` out. On such a host a container
 * started with `-e DB_HOST=...` passes a value the framework cannot see, and
 * the failure is a default being used silently rather than an error.
 *
 * That is the deployment the documentation describes — configuration from the
 * environment, no `.env` file — so reading one superglobal was the wrong
 * amount of trust. All three sources are consulted, nearest first.
 */
final class Env
{
    /**
     * Read a value.
     *
     * @param string $key The variable's name
     * @param string|null $default Returned when it is set nowhere
     * @return string|null The value
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return self::normalise($_ENV[$key]);
        }

        if (array_key_exists($key, $_SERVER)) {
            return self::normalise($_SERVER[$key]);
        }

        $value = getenv($key);

        return $value === false ? $default : self::normalise($value);
    }

    /**
     * Whether a variable is set anywhere.
     *
     * @param string $key The variable's name
     * @return bool True when it is set
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }

    /**
     * Turn the words an environment uses for nothing into nothing.
     *
     * An environment variable is always a string, so "false" and "null" arrive
     * as text that is true. Converting them here means a value written the way
     * everybody writes it means what everybody expects.
     *
     * @param mixed $value The raw value
     * @return string|null The value
     */
    private static function normalise(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return match (strtolower($value)) {
            'null', '(null)' => null,
            'false', '(false)' => '',
            'true', '(true)' => '1',
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
