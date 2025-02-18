<?php

namespace SfphpProject\src;

use Exception;

/**
 * Class to work with .env file
 */
class Dotenv
{
    /**
     * Load environment variables from .env file
     *
     * @param string $filePath
     * @return void
     * @throws Exception
     */
    static function loadEnv(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new Exception(".env file not found!");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}
