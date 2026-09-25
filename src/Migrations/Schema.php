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
        $this->statement('DROP TABLE IF EXISTS ' . Identifier::quoteTable($this->driver(), $table));
    }

    /**
     * Drop a table, failing when it does not exist.
     *
     * @param string $table The table name
     * @return void
     */
    public function drop(string $table): void
    {
        $this->statement('DROP TABLE ' . Identifier::quoteTable($this->driver(), $table));
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
            'ALTER TABLE ' . Identifier::quoteTable($this->driver(), $table)
            . ' RENAME TO ' . Identifier::quote($this->driver(), $newName)
        );
    }

    /**
     * Check whether a table exists (MySQL and PostgreSQL).
     *
     * @param string $table The table name, optionally qualified as "schema.table"
     * @return bool
     */
    public function hasTable(string $table): bool
    {
        [$schema, $name] = Identifier::split($table);
        $bindings = ['table' => $name];

        return match ($this->driver()) {
            'mysql' => $this->exists(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = '
                . $this->schemaExpression('DATABASE()', $schema, $bindings) . ' AND table_name = :table',
                $bindings
            ),
            'pgsql' => $this->exists(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = '
                . $this->schemaExpression('current_schema()', $schema, $bindings) . ' AND table_name = :table',
                $bindings
            ),
            // The database queue asks this, and SQLite is a common development database.
            'sqlite' => $this->exists(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table",
                ['table' => $name]
            ),
            default => $this->unsupportedIntrospection(),
        };
    }

    /**
     * Check whether a column exists (MySQL and PostgreSQL).
     *
     * @param string $table The table name, optionally qualified as "schema.table"
     * @param string $column The column name
     * @return bool
     */
    public function hasColumn(string $table, string $column): bool
    {
        [$schema, $name] = Identifier::split($table);
        $bindings = ['table' => $name, 'column' => $column];
        $default = match ($this->driver()) {
            'mysql' => 'DATABASE()',
            'pgsql' => 'current_schema()',
            default => $this->unsupportedIntrospection(),
        };

        return $this->exists(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = '
            . $this->schemaExpression($default, $schema, $bindings)
            . ' AND table_name = :table AND column_name = :column',
            $bindings
        );
    }

    /**
     * Check whether an index exists (MySQL and PostgreSQL).
     *
     * @param string $table The table name, optionally qualified as "schema.table"
     * @param string $index The index name
     * @return bool
     */
    public function hasIndex(string $table, string $index): bool
    {
        [$schema, $name] = Identifier::split($table);
        $bindings = ['table' => $name, 'index' => $index];

        return match ($this->driver()) {
            'mysql' => $this->exists(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = '
                . $this->schemaExpression('DATABASE()', $schema, $bindings)
                . ' AND table_name = :table AND index_name = :index',
                $bindings
            ),
            'pgsql' => $this->exists(
                'SELECT 1 FROM pg_indexes WHERE schemaname = '
                . $this->schemaExpression('current_schema()', $schema, $bindings)
                . ' AND tablename = :table AND indexname = :index',
                $bindings
            ),
            default => $this->unsupportedIntrospection(),
        };
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
     * Run a query and tell whether it returned any row.
     *
     * @param string $sql The SQL statement
     * @param array<string, mixed> $bindings The bound values
     * @return bool
     */
    private function exists(string $sql, array $bindings): bool
    {
        return $this->statement($sql, $bindings)->fetchColumn() !== false;
    }

    /**
     * Build the schema/database comparison, binding the schema only when one was given.
     *
     * @param string $default The SQL expression for the current schema or database
     * @param string|null $schema The explicit schema name
     * @param array<string, mixed> $bindings The bound values, extended in place
     * @return string
     */
    private function schemaExpression(string $default, ?string $schema, array &$bindings): string
    {
        if ($schema === null) {
            return $default;
        }

        $bindings['schema'] = $schema;

        return ':schema';
    }

    /**
     * Fail for drivers that have no introspection support yet.
     *
     * @return never
     */
    private function unsupportedIntrospection(): never
    {
        throw new InvalidArgumentException(
            'Schema introspection is only supported for mysql and pgsql, not ' . $this->driver() . '.'
        );
    }
}
