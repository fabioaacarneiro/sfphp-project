<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;

/**
 * Quotes and normalizes SQL identifiers for the schema builder.
 */
final class Identifier
{
    /**
     * Get the maximum identifier length for a driver.
     *
     * @param string $driver The PDO driver name
     * @return int
     */
    public static function limit(string $driver): int
    {
        return match ($driver) {
            'mysql' => 64,
            'sqlsrv', 'dblib' => 128,
            default => 63,
        };
    }

    /**
     * Quote a single identifier for a driver.
     *
     * @param string $driver The PDO driver name
     * @param string $identifier The identifier name
     * @return string
     */
    public static function quote(string $driver, string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid schema identifier: $identifier");
        }

        if (strlen($identifier) > self::limit($driver)) {
            throw new InvalidArgumentException(
                "Schema identifier is longer than {$driver} allows (" . self::limit($driver) . "): $identifier"
            );
        }

        return match ($driver) {
            'mysql' => '`' . $identifier . '`',
            'sqlsrv', 'dblib' => '[' . $identifier . ']',
            default => '"' . $identifier . '"',
        };
    }

    /**
     * Quote a table name, optionally qualified as "schema.table".
     *
     * @param string $driver The PDO driver name
     * @param string $table The table name
     * @return string
     */
    public static function quoteTable(string $driver, string $table): string
    {
        [$schema, $name] = self::split($table);

        return $schema === null
            ? self::quote($driver, $name)
            : self::quote($driver, $schema) . '.' . self::quote($driver, $name);
    }

    /**
     * Split a table name into its optional schema and its bare name.
     *
     * @param string $table The table name
     * @return array{0: string|null, 1: string}
     */
    public static function split(string $table): array
    {
        $parts = explode('.', $table);

        return match (count($parts)) {
            1 => [null, $parts[0]],
            2 => [$parts[0], $parts[1]],
            default => throw new InvalidArgumentException("Invalid schema identifier: $table"),
        };
    }

    /**
     * Get the table name without its schema qualifier.
     *
     * @param string $table The table name
     * @return string
     */
    public static function bareTable(string $table): string
    {
        return self::split($table)[1];
    }

    /**
     * Shorten a generated name to fit the driver limit, keeping it deterministic.
     *
     * @param string $driver The PDO driver name
     * @param string $name The generated name
     * @return string
     */
    public static function shorten(string $driver, string $name): string
    {
        $limit = self::limit($driver);

        if (strlen($name) <= $limit) {
            return $name;
        }

        return substr($name, 0, $limit - 9) . '_' . substr(md5($name), 0, 8);
    }
}
