<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * Class to work with .env file
 */
class Dotenv
{
    /**
     * Load environment variables from .env file
     *
     * Real environment variables set by the server or process always win,
     * regardless of php.ini variables_order setting or SAPI.
     * The .env file only fills what the environment did not define.
     *
     * @param string $filePath Path to the .env file
     * @param bool $required Whether a missing file is an error
     * @return void
     * @throws RuntimeException If the file is required and does not exist
     */
    public static function loadEnv(string $filePath, bool $required = true): void
    {
        if (!file_exists($filePath)) {
            if ($required) {
                /*
                 * Named, because the path is no longer always ".env": an
                 * application passes its own to Bootstrap::load(), and "file
                 * not found" without saying which sends whoever reads it
                 * looking in the wrong directory.
                 */
                throw new RuntimeException('The environment file ' . $filePath . ' does not exist.');
            }

            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            /*
             * Skip malformed lines instead of destructuring them: a line with
             * no "=" used to raise an "undefined array key 1" warning on every
             * request that loaded the file.
             */
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);

            // "export KEY=value" is how the same file is sourced by a shell.
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }

            $value = self::parseValue(trim($value));

            if ($key === '') {
                continue;
            }

            // Check with getenv() which works regardless of variables_order.
            // A real environment variable (even empty string) wins over .env.
            if (getenv($key) === false) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    /**
     * Normalise a raw value read from the file.
     *
     * Strips a single layer of matching quotes, and drops trailing inline
     * comments from unquoted values so that "DB_NAME=app # main" yields "app".
     *
     * @param string $value
     * @return string
     */
    private static function parseValue(string $value): string
    {
        /*
         * A quoted value ends at its closing quote, and whatever follows —
         * usually a comment — is not part of it. Only a value that was
         * nothing but the quotes used to lose them, so
         * APP_NAME="My App" # the name kept both quotes and the comment.
         */
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $close = strpos($value, $value[0], 1);

            if ($close !== false) {
                $rest = trim(substr($value, $close + 1));

                if ($rest === '' || str_starts_with($rest, '#')) {
                    return substr($value, 1, $close - 1);
                }
            }
        }

        $commentPosition = strpos($value, ' #');

        if ($commentPosition !== false) {
            $value = substr($value, 0, $commentPosition);
        }

        return rtrim($value);
    }
}
