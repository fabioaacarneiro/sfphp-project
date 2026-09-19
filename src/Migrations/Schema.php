<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * Executes schema statements for migrations.
 */
final class Schema
{
    /**
     * Create a schema helper.
     *
     * @param PDO $pdo The database connection
     */
    public function __construct(private PDO $pdo) {}

    /**
     * Create a table.
     *
     * @param string $table The table name
     * @param callable(Blueprint):void $callback The column builder
     * @return void
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this->driver(), 'create');
        $callback($blueprint);

        $this->executeStatements($blueprint->compileStatements());
    }

    /**
     * Alter an existing table.
     *
     * @param string $table The table name
     * @param callable(Blueprint):void $callback The schema changes
     * @return void
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this->driver(), 'alter');
        $callback($blueprint);

        $this->executeStatements($blueprint->compileStatements());
    }

    /**
     * Drop a table when it exists.
     *
     * @param string $table The table name
     * @return void
     */
    public function dropIfExists(string $table): void
    {
        $this->statement('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table));
    }

    /**
     * Rename a table.
     *
     * @param string $table The current table name
     * @param string $newName The new table name
     * @return void
     */
    public function rename(string $table, string $newName): void
    {
        $this->statement(
            'ALTER TABLE ' . $this->quoteIdentifier($table)
            . ' RENAME TO ' . $this->quoteIdentifier($newName)
        );
    }

    /**
     * Execute a raw schema statement.
     *
     * @param string $sql The SQL statement
     * @param array<string, mixed> $bindings The bound values
     * @return PDOStatement
     */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new InvalidArgumentException('Unable to prepare schema statement.');
        }

        $statement->execute($bindings);

        return $statement;
    }

    /**
     * Get the current PDO driver.
     *
     * @return string
     */
    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Execute a list of SQL statements.
     *
     * @param array<int, string> $statements The SQL statements
     * @return void
     */
    private function executeStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->statement($statement);
        }
    }

    /**
     * Quote an identifier for the current PDO driver.
     *
     * @param string $identifier The identifier name
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid schema identifier: $identifier");
        }

        return match ($this->driver()) {
            'mysql' => '`' . $identifier . '`',
            'sqlsrv', 'dblib' => '[' . $identifier . ']',
            default => '"' . $identifier . '"',
        };
    }
}
