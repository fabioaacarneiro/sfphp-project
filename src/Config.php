<?php

namespace SfphpProject\src;

/**
 * The settings the framework reads, in something a test can change.
 *
 * They were constants, and a constant cannot be unset. That is fine for an
 * application, which decides its settings once at boot, and awkward for a test,
 * which wants to know what happens with a different session lifetime without
 * running in a separate process to find out.
 *
 * Constants still exist and still work: Bootstrap defines them and seeds this
 * from them, so nothing that reads APP_ENV directly has changed. What is new is
 * that the framework reads through here, and a test can say:
 *
 *     Config::set('SESSION_LIFETIME', 60);
 *     // ...
 *     Config::forget('SESSION_LIFETIME');   // back to the constant
 *
 * The lookup order is deliberate: an explicit set() wins, then the constant,
 * then the default the caller passed. An application that wants to decide
 * something the framework reads can therefore do it either way.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $overrides = [];

    /**
     * Read a setting.
     *
     * @param string $key The setting's name
     * @param mixed $default Returned when neither an override nor a constant exists
     * @return mixed The value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        return defined($key) ? constant($key) : $default;
    }

    /**
     * Read a setting as an integer.
     *
     * @param string $key The setting's name
     * @param int $default Returned when the setting is absent
     * @return int The value
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read a setting as a string.
     *
     * @param string $key The setting's name
     * @param string $default Returned when the setting is absent
     * @return string The value
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Override a setting for the rest of this process.
     *
     * @param string $key The setting's name
     * @param mixed $value The value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    /**
     * Drop an override, so the constant is read again.
     *
     * @param string|null $key The setting's name, or null for every override
     * @return void
     */
    public static function forget(?string $key = null): void
    {
        if ($key === null) {
            self::$overrides = [];

            return;
        }

        unset(self::$overrides[$key]);
    }

    /**
     * Whether a setting has a value at all.
     *
     * @param string $key The setting's name
     * @return bool True when an override or a constant exists
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$overrides) || defined($key);
    }
}
