<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;

/**
 * Collects schema operations for a migration.
 */
final class Blueprint
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $columns = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $operations = [];

    private ?int $currentColumnIndex = null;

    /**
     * Create a blueprint for the given table.
     *
     * @param string $table The table name
     * @param string $driver The PDO driver name
     * @param string $mode Either "create" or "alter"
     */
    public function __construct(
        private string $table,
        private string $driver,
        private string $mode = 'create'
    ) {}

    /**
     * Add an auto-incrementing identifier column.
     *
     * @param string $name The column name
     * @return self
     */
    public function id(string $name = 'id'): self
    {
        return $this->column($name, $this->integerDefinition(true, true));
    }

    /**
     * Add an unsigned integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function foreignId(string $name): self
    {
        return $this->column($name, $this->integerDefinition(true, false));
    }

    /**
     * Add a big integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function bigInteger(string $name): self
    {
        return $this->column($name, $this->typeDefinition('BIGINT'));
    }

    /**
     * Add a string column.
     *
     * @param string $name The column name
     * @param int $length The maximum length
     * @return self
     */
    public function string(string $name, int $length = 255): self
    {
        return $this->column($name, $this->typeDefinition('VARCHAR(' . $length . ')'));
    }

    /**
     * Add a text column.
     *
     * @param string $name The column name
     * @return self
     */
    public function text(string $name): self
    {
        return $this->column($name, $this->typeDefinition('TEXT'));
    }

    /**
     * Add an integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function integer(string $name): self
    {
        return $this->column($name, $this->integerDefinition(false, false));
    }

    /**
     * Add a boolean column.
     *
     * @param string $name The column name
     * @return self
     */
    public function boolean(string $name): self
    {
        return $this->column($name, $this->typeDefinition('BOOLEAN'));
    }

    /**
     * Add a date column.
     *
     * @param string $name The column name
     * @return self
     */
    public function date(string $name): self
    {
        return $this->column($name, $this->typeDefinition('DATE'));
    }

    /**
     * Add a date-time column.
     *
     * @param string $name The column name
     * @return self
     */
    public function dateTime(string $name): self
    {
        return $this->column($name, $this->typeDefinition('DATETIME'));
    }

    /**
     * Add a timestamp column.
     *
     * @param string $name The column name
     * @return self
     */
    public function timestamp(string $name): self
    {
        return $this->column($name, $this->typeDefinition('TIMESTAMP'));
    }

    /**
     * Add created_at and updated_at columns.
     *
     * @return self
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->default($this->raw('CURRENT_TIMESTAMP'));
        $this->timestamp('updated_at')->default($this->raw('CURRENT_TIMESTAMP'));

        return $this;
    }

    /**
     * Add a decimal column.
     *
     * @param string $name The column name
     * @param int $precision The total precision
     * @param int $scale The decimal scale
     * @return self
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->column($name, $this->typeDefinition("DECIMAL($precision, $scale)"));
    }

    /**
     * Add a JSON column.
     *
     * @param string $name The column name
     * @return self
     */
    public function json(string $name): self
    {
        return $this->column($name, $this->typeDefinition('JSON'));
    }

    /**
     * Add a UUID column.
     *
     * @param string $name The column name
     * @return self
     */
    public function uuid(string $name): self
    {
        return $this->column($name, $this->typeDefinition('CHAR(36)'));
    }

    /**
     * Make the current column nullable.
     *
     * @return self
     */
    public function nullable(): self
    {
        $column =& $this->currentColumn();
        $column['nullable'] = true;

        return $this;
    }

    /**
     * Set a default value on the current column.
     *
     * @param mixed $value The default value
     * @return self
     */
    public function default(mixed $value): self
    {
        $column =& $this->currentColumn();
        $column['default'] = $value;

        return $this;
    }

    /**
     * Build a raw SQL expression.
     *
     * @param string $sql The SQL fragment
     * @return Expression
     */
    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * Mark the current column as unique.
     *
     * @param string|null $name Optional index name
     * @return self
     */
    public function unique(?string $name = null): self
    {
        $this->pushConstraint('unique', $name);

        return $this;
    }

    /**
     * Create an index for the current column.
     *
     * @param string|null $name Optional index name
     * @return self
     */
    public function index(?string $name = null): self
    {
        $this->pushConstraint('index', $name);

        return $this;
    }

    /**
     * Add a foreign key constraint for the current column.
     *
     * @param string $table The referenced table
     * @param string $references The referenced column
     * @param string|null $name Optional constraint name
     * @return self
     */
    public function constrained(string $table, string $references = 'id', ?string $name = null): self
    {
        $column = $this->currentColumn();
        $this->operations[] = [
            'type' => 'foreign',
            'column' => $column['name'],
            'table' => $table,
            'references' => $references,
            'name' => $name ?: $this->foreignKeyName($column['name'], $table),
        ];

        return $this;
    }

    /**
     * Drop a column from an altered table.
     *
     * @param string $name The column name
     * @return self
     */
    public function dropColumn(string $name): self
    {
        $this->operations[] = [
            'type' => 'dropColumn',
            'name' => $name,
        ];

        return $this;
    }

    /**
     * Drop an index from an altered table.
     *
     * @param string $name The index name
     * @return self
     */
    public function dropIndex(string $name): self
    {
        $this->operations[] = [
            'type' => 'dropIndex',
            'name' => $name,
        ];

        return $this;
    }

    /**
     * Rename the current table.
     *
     * @param string $newName The new table name
     * @return self
     */
    public function renameTable(string $newName): self
    {
        $this->operations[] = [
            'type' => 'renameTable',
            'name' => $newName,
        ];

        return $this;
    }

    /**
     * Compile schema statements.
     *
     * @return array<int, string>
     */
    public function compileStatements(): array
    {
        if ($this->mode === 'create') {
            return $this->compileCreateStatements();
        }

        return $this->compileAlterStatements();
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Get the driver name.
     *
     * @return string
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Register a column and make it the current column.
     *
     * @param string $name The column name
     * @param string $definition The SQL definition
     * @return self
     */
    private function column(string $name, string $definition): self
    {
        $this->columns[] = [
            'name' => $name,
            'definition' => $definition,
            'nullable' => false,
            'default' => null,
        ];
        $this->currentColumnIndex = array_key_last($this->columns);

        return $this;
    }

    /**
     * Get the current column by reference.
     *
     * @return array<string, mixed>
     */
    private function &currentColumn(): array
    {
        if ($this->currentColumnIndex === null) {
            throw new InvalidArgumentException('A column modifier must follow a column definition.');
        }

        return $this->columns[$this->currentColumnIndex];
    }

    /**
     * Add a constraint for the current column.
     *
     * @param string $type The constraint type
     * @param string|null $name The optional index name
     * @return void
     */
    private function pushConstraint(string $type, ?string $name): void
    {
        $column = $this->currentColumn();
        $this->operations[] = [
            'type' => $type,
            'column' => $column['name'],
            'name' => $name ?: $this->indexName($column['name'], $type),
        ];
    }

    /**
     * Compile create-table statements.
     *
     * @return array<int, string>
     */
    private function compileCreateStatements(): array
    {
        if ($this->columns === []) {
            throw new InvalidArgumentException('A table must contain at least one column.');
        }

        $statements = [
            'CREATE TABLE ' . $this->quoteIdentifier($this->table)
            . ' (' . implode(', ', array_map($this->compileColumn(...), $this->columns)) . ')',
        ];

        foreach ($this->operations as $operation) {
            $statements[] = $this->compileOperation($operation);
        }

        return $statements;
    }

    /**
     * Compile alter-table statements.
     *
     * @return array<int, string>
     */
    private function compileAlterStatements(): array
    {
        $statements = [];

        foreach ($this->columns as $column) {
            $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ADD COLUMN ' . $this->compileColumn($column);
        }

        foreach ($this->operations as $operation) {
            $statements[] = $this->compileOperation($operation);
        }

        return $statements;
    }

    /**
     * Compile a single column definition.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function compileColumn(array $column): string
    {
        $sql = $this->quoteIdentifier($column['name']) . ' ' . $column['definition'];

        if (!empty($column['nullable'])) {
            $sql .= ' NULL';
        } elseif (!str_contains($column['definition'], 'PRIMARY KEY')) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $column) && $column['default'] !== null) {
            $sql .= ' DEFAULT ' . $this->quoteValue($column['default']);
        }

        return $sql;
    }

    /**
     * Compile an operation statement.
     *
     * @param array<string, mixed> $operation The operation definition
     * @return string
     */
    private function compileOperation(array $operation): string
    {
        return match ($operation['type']) {
            'unique' => 'CREATE UNIQUE INDEX ' . $this->quoteIdentifier($operation['name'])
                . ' ON ' . $this->quoteIdentifier($this->table)
                . ' (' . $this->quoteIdentifier($operation['column']) . ')',
            'index' => 'CREATE INDEX ' . $this->quoteIdentifier($operation['name'])
                . ' ON ' . $this->quoteIdentifier($this->table)
                . ' (' . $this->quoteIdentifier($operation['column']) . ')',
            'foreign' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
                . ' FOREIGN KEY (' . $this->quoteIdentifier($operation['column']) . ')'
                . ' REFERENCES ' . $this->quoteIdentifier($operation['table'])
                . ' (' . $this->quoteIdentifier($operation['references']) . ')',
            'dropColumn' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' DROP COLUMN ' . $this->quoteIdentifier($operation['name']),
            'dropIndex' => $this->compileDropIndex($operation['name']),
            'renameTable' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' RENAME TO ' . $this->quoteIdentifier($operation['name']),
            default => throw new InvalidArgumentException('Unsupported schema operation: ' . $operation['type']),
        };
    }

    /**
     * Build a DROP INDEX statement for the configured driver.
     *
     * @param string $name The index name
     * @return string
     */
    private function compileDropIndex(string $name): string
    {
        return match ($this->driver) {
            'mysql' => 'DROP INDEX ' . $this->quoteIdentifier($name) . ' ON ' . $this->quoteIdentifier($this->table),
            default => 'DROP INDEX ' . $this->quoteIdentifier($name),
        };
    }

    /**
     * Build the SQL fragment for a type definition.
     *
     * @param string $type The SQL type
     * @return string
     */
    private function typeDefinition(string $type): string
    {
        return $type;
    }

    /**
     * Build an integer definition.
     *
     * @param bool $unsigned Whether the integer should be unsigned
     * @param bool $autoIncrement Whether the column auto increments
     * @return string
     */
    private function integerDefinition(bool $unsigned, bool $autoIncrement): string
    {
        $definition = 'INTEGER';

        if ($unsigned && in_array($this->driver, ['mysql', 'sqlsrv', 'dblib'], true)) {
            $definition = 'BIGINT';
        }

        if ($unsigned && $this->driver === 'mysql') {
            $definition .= ' UNSIGNED';
        }

        if ($autoIncrement) {
            $definition .= match ($this->driver) {
                'mysql' => ' AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                'sqlsrv', 'dblib' => ' IDENTITY(1,1) PRIMARY KEY',
                'sqlite' => ' PRIMARY KEY AUTOINCREMENT',
                'firebird', 'oci' => ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                default => ' PRIMARY KEY',
            };
        }

        return $definition;
    }

    /**
     * Quote an identifier for the current driver.
     *
     * @param string $identifier The identifier name
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid schema identifier: $identifier");
        }

        return match ($this->driver) {
            'mysql' => '`' . $identifier . '`',
            'sqlsrv', 'dblib' => '[' . $identifier . ']',
            default => '"' . $identifier . '"',
        };
    }

    /**
     * Quote a scalar value for SQL output.
     *
     * @param mixed $value The value to quote
     * @return string
     */
    private function quoteValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof Expression) {
            return $value->value();
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Build a deterministic index name.
     *
     * @param string $column The column name
     * @param string $type The index type
     * @return string
     */
    private function indexName(string $column, string $type): string
    {
        return $this->table . '_' . $column . '_' . $type;
    }

    /**
     * Build a deterministic foreign key name.
     *
     * @param string $column The column name
     * @param string $table The referenced table
     * @return string
     */
    private function foreignKeyName(string $column, string $table): string
    {
        return $this->table . '_' . $column . '_' . $table . '_foreign';
    }
}
