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
    private ?int $currentForeignIndex = null;

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
        return $this->column($name, 'id', [
            'autoIncrement' => true,
            'primary' => true,
            'unsigned' => true,
        ]);
    }

    /**
     * Add a foreign identifier column.
     *
     * @param string $name The column name
     * @return self
     */
    public function foreignId(string $name): self
    {
        return $this->column($name, 'foreignId', ['unsigned' => true]);
    }

    /**
     * Add a tiny integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function tinyInteger(string $name): self
    {
        return $this->column($name, 'tinyInteger');
    }

    /**
     * Add a small integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function smallInteger(string $name): self
    {
        return $this->column($name, 'smallInteger');
    }

    /**
     * Add a medium integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function mediumInteger(string $name): self
    {
        return $this->column($name, 'mediumInteger');
    }

    /**
     * Add an integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function integer(string $name): self
    {
        return $this->column($name, 'integer');
    }

    /**
     * Add a big integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function bigInteger(string $name): self
    {
        return $this->column($name, 'bigInteger');
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
        return $this->column($name, 'string', ['length' => $length]);
    }

    /**
     * Add a text column.
     *
     * @param string $name The column name
     * @return self
     */
    public function text(string $name): self
    {
        return $this->column($name, 'text');
    }

    /**
     * Add a long text column.
     *
     * @param string $name The column name
     * @return self
     */
    public function longText(string $name): self
    {
        return $this->column($name, 'longText');
    }

    /**
     * Add a boolean column.
     *
     * @param string $name The column name
     * @return self
     */
    public function boolean(string $name): self
    {
        return $this->column($name, 'boolean');
    }

    /**
     * Add a date column.
     *
     * @param string $name The column name
     * @return self
     */
    public function date(string $name): self
    {
        return $this->column($name, 'date');
    }

    /**
     * Add a time column.
     *
     * @param string $name The column name
     * @return self
     */
    public function time(string $name): self
    {
        return $this->column($name, 'time');
    }

    /**
     * Add a date-time column.
     *
     * @param string $name The column name
     * @return self
     */
    public function dateTime(string $name): self
    {
        return $this->column($name, 'dateTime');
    }

    /**
     * Add a timestamp column.
     *
     * @param string $name The column name
     * @return self
     */
    public function timestamp(string $name): self
    {
        return $this->column($name, 'timestamp');
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
        return $this->column($name, 'decimal', [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    /**
     * Add a float column.
     *
     * @param string $name The column name
     * @return self
     */
    public function float(string $name): self
    {
        return $this->column($name, 'float');
    }

    /**
     * Add a JSON column.
     *
     * @param string $name The column name
     * @return self
     */
    public function json(string $name): self
    {
        return $this->column($name, 'json');
    }

    /**
     * Add a UUID column.
     *
     * @param string $name The column name
     * @return self
     */
    public function uuid(string $name): self
    {
        return $this->column($name, 'uuid');
    }

    /**
     * Add an enum-like column.
     *
     * @param string $name The column name
     * @param array<int, string> $values The allowed values
     * @return self
     */
    public function enum(string $name, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Enum values cannot be empty.');
        }

        return $this->column($name, 'enum', ['values' => array_values($values)]);
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
     * Mark the current column as unsigned.
     *
     * @return self
     */
    public function unsigned(): self
    {
        $column =& $this->currentColumn();
        $column['unsigned'] = true;

        return $this;
    }

    /**
     * Mark the current column as auto incrementing.
     *
     * @return self
     */
    public function autoIncrement(): self
    {
        $column =& $this->currentColumn();
        $column['autoIncrement'] = true;

        return $this;
    }

    /**
     * Mark the current column as needing a type change.
     *
     * @return self
     */
    public function change(): self
    {
        $column =& $this->currentColumn();
        $column['change'] = true;

        return $this;
    }

    /**
     * Position the current column after another column.
     *
     * @param string $column The column to place after
     * @return self
     */
    public function after(string $column): self
    {
        $current =& $this->currentColumn();
        $current['after'] = $column;

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
        $this->pushConstraint('unique', null, $name);

        return $this;
    }

    /**
     * Create an index for one or more columns.
     *
     * @param string|array<int, string>|null $columns The indexed columns or the current column
     * @param string|null $name Optional index name
     * @return self
     */
    public function index(string|array|null $columns = null, ?string $name = null): self
    {
        $this->pushConstraint('index', $this->normalizeColumns($columns), $name);

        return $this;
    }

    /**
     * Create a primary key constraint.
     *
     * @param string|array<int, string>|null $columns The primary key columns or the current column
     * @param string|null $name Optional constraint name
     * @return self
     */
    public function primary(string|array|null $columns = null, ?string $name = null): self
    {
        $this->pushConstraint('primary', $this->normalizeColumns($columns), $name);

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
            'columns' => [$column['name']],
            'table' => $table,
            'references' => [$references],
            'name' => $name ?: $this->foreignKeyName([$column['name']]),
            'onDelete' => null,
            'onUpdate' => null,
        ];
        $this->currentForeignIndex = array_key_last($this->operations);

        return $this;
    }

    /**
     * Add a foreign key constraint for one or more columns.
     *
     * @param string|array<int, string> $columns The local columns
     * @param string|null $name Optional constraint name
     * @return self
     */
    public function foreign(string|array $columns, ?string $name = null): self
    {
        $columns = $this->normalizeColumns($columns);
        $this->operations[] = [
            'type' => 'foreign',
            'columns' => $columns,
            'table' => null,
            'references' => ['id'],
            'name' => $name ?: $this->foreignKeyName($columns),
            'onDelete' => null,
            'onUpdate' => null,
        ];
        $this->currentForeignIndex = array_key_last($this->operations);

        return $this;
    }

    /**
     * Define the referenced table for the current foreign key.
     *
     * @param string $table The referenced table
     * @param string $references The referenced column
     * @return self
     */
    public function references(string $table, string $references = 'id'): self
    {
        $foreign =& $this->currentForeign();
        $foreign['table'] = $table;
        $foreign['references'] = [$references];
        if (($foreign['name'] ?? null) === null) {
            $foreign['name'] = $this->foreignKeyName($foreign['columns'], $table);
        }

        return $this;
    }

    /**
     * Set ON DELETE behavior for the current foreign key.
     *
     * @param string $action The action name
     * @return self
     */
    public function onDelete(string $action): self
    {
        $foreign =& $this->currentForeign();
        $foreign['onDelete'] = strtoupper($action);

        return $this;
    }

    /**
     * Set ON UPDATE behavior for the current foreign key.
     *
     * @param string $action The action name
     * @return self
     */
    public function onUpdate(string $action): self
    {
        $foreign =& $this->currentForeign();
        $foreign['onUpdate'] = strtoupper($action);

        return $this;
    }

    /**
     * Convenience helper for cascading deletes.
     *
     * @return self
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Convenience helper for cascading updates.
     *
     * @return self
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Drop a column from an altered table.
     *
     * @param string|array<int, string> $columns The column name(s)
     * @return self
     */
    public function dropColumn(string|array $columns): self
    {
        foreach ($this->normalizeColumns($columns) as $column) {
            $this->operations[] = [
                'type' => 'dropColumn',
                'name' => $column,
            ];
        }

        return $this;
    }

    /**
     * Drop an index from an altered table.
     *
     * @param string|array<int, string> $columns The index name or columns
     * @param string|null $name Optional explicit index name
     * @return self
     */
    public function dropIndex(string|array $columns, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropIndex',
            'name' => $name ?: $this->indexName($this->normalizeColumns($columns), 'index'),
        ];

        return $this;
    }

    /**
     * Drop a unique constraint from an altered table.
     *
     * @param string|array<int, string> $columns The constraint name or columns
     * @param string|null $name Optional explicit constraint name
     * @return self
     */
    public function dropUnique(string|array $columns, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropUnique',
            'name' => $name ?: $this->indexName($this->normalizeColumns($columns), 'unique'),
        ];

        return $this;
    }

    /**
     * Drop a foreign key from an altered table.
     *
     * @param string|array<int, string> $columns The constraint name or columns
     * @param string|null $name Optional explicit constraint name
     * @return self
     */
    public function dropForeign(string|array $columns, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropForeign',
            'name' => $name ?: $this->foreignKeyName($this->normalizeColumns($columns), 'foreign'),
        ];

        return $this;
    }

    /**
     * Rename a column.
     *
     * @param string $from The current column name
     * @param string $to The new column name
     * @return self
     */
    public function renameColumn(string $from, string $to): self
    {
        $this->operations[] = [
            'type' => 'renameColumn',
            'from' => $from,
            'to' => $to,
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
     * Add a CHECK constraint.
     *
     * @param string $expression The SQL check expression
     * @param string|null $name Optional constraint name
     * @return self
     */
    public function check(string $expression, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'check',
            'expression' => $expression,
            'name' => $name ?: $this->table . '_check_' . count($this->operations),
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
     * @param string $type The logical column type
     * @param array<string, mixed> $attributes The column attributes
     * @return self
     */
    private function column(string $name, string $type, array $attributes = []): self
    {
        $this->columns[] = array_merge([
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'default' => null,
            'unsigned' => false,
            'autoIncrement' => false,
            'change' => false,
            'after' => null,
            'values' => [],
        ], $attributes);
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
     * Get the current foreign key by reference.
     *
     * @return array<string, mixed>
     */
    private function &currentForeign(): array
    {
        if ($this->currentForeignIndex === null) {
            throw new InvalidArgumentException('A foreign key modifier must follow a foreign key definition.');
        }

        return $this->operations[$this->currentForeignIndex];
    }

    /**
     * Add a constraint for one or more columns.
     *
     * @param string $type The constraint type
     * @param array<int, string>|null $columns The affected columns or null for the current column
     * @param string|null $name The optional index name
     * @return void
     */
    private function pushConstraint(string $type, ?array $columns, ?string $name): void
    {
        $columns ??= [$this->currentColumn()['name']];

        $this->operations[] = [
            'type' => $type,
            'columns' => $columns,
            'name' => $name ?: $this->indexName($columns, $type),
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
            . ' (' . implode(', ', array_map([$this, 'compileColumn'], $this->columns)) . ')',
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
            if (!empty($column['change'])) {
                $statements = array_merge($statements, $this->compileChangeColumn($column));
                continue;
            }

            $statement = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ADD COLUMN ' . $this->compileColumn($column);

            if (!empty($column['after']) && $this->driver === 'mysql') {
                $statement .= ' AFTER ' . $this->quoteIdentifier($column['after']);
            }

            $statements[] = $statement;
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
        $sql = $this->quoteIdentifier($column['name']) . ' ' . $this->columnType($column);

        if (!empty($column['unsigned']) && in_array($this->driver, ['mysql', 'sqlsrv', 'dblib'], true)) {
            $sql .= ' UNSIGNED';
        }

        if (!empty($column['autoIncrement'])) {
            $sql .= match ($this->driver) {
                'mysql' => ' AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                'sqlsrv', 'dblib' => ' IDENTITY(1,1) PRIMARY KEY',
                'sqlite' => ' PRIMARY KEY AUTOINCREMENT',
                'firebird', 'oci' => ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                default => ' PRIMARY KEY',
            };
        }

        if (!empty($column['nullable'])) {
            $sql .= ' NULL';
        } elseif (empty($column['autoIncrement'])) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $column) && $column['default'] !== null) {
            $sql .= ' DEFAULT ' . $this->quoteValue($column['default']);
        }

        return $sql;
    }

    /**
     * Compile a change-column statement for the current driver.
     *
     * @param array<string, mixed> $column The column data
     * @return array<int, string>
     */
    private function compileChangeColumn(array $column): array
    {
        $columnName = $this->quoteIdentifier($column['name']);
        $definition = $this->columnType($column);
        $statements = [];

        if ($this->driver === 'mysql') {
            $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' MODIFY COLUMN ' . $this->compileColumn($column);

            return $statements;
        }

        if (in_array($this->driver, ['pgsql', 'sqlite'], true)) {
            $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ALTER COLUMN ' . $columnName . ' TYPE ' . $definition;

            if (!empty($column['nullable'])) {
                $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                    . ' ALTER COLUMN ' . $columnName . ' DROP NOT NULL';
            } elseif (empty($column['autoIncrement'])) {
                $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                    . ' ALTER COLUMN ' . $columnName . ' SET NOT NULL';
            }

            if (array_key_exists('default', $column) && $column['default'] !== null) {
                $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                    . ' ALTER COLUMN ' . $columnName . ' SET DEFAULT ' . $this->quoteValue($column['default']);
            }

            return $statements;
        }

        if (in_array($this->driver, ['sqlsrv', 'dblib'], true)) {
            $statements[] = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ALTER COLUMN ' . $this->compileColumn($column);

            return $statements;
        }

        throw new InvalidArgumentException('Column changes are not supported for driver ' . $this->driver . '.');
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
            'unique' => $this->compileIndexLike('CREATE UNIQUE INDEX', $operation['name'], $operation['columns']),
            'index' => $this->compileIndexLike('CREATE INDEX', $operation['name'], $operation['columns']),
            'primary' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
                . ' PRIMARY KEY (' . $this->quoteColumns($operation['columns']) . ')',
            'foreign' => $this->compileForeignKey($operation),
            'dropColumn' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' DROP COLUMN ' . $this->quoteIdentifier($operation['name']),
            'dropIndex' => $this->compileDropIndex($operation['name']),
            'dropUnique' => $this->compileDropIndex($operation['name']),
            'dropForeign' => $this->compileDropForeign($operation['name']),
            'renameColumn' => $this->compileRenameColumn($operation['from'], $operation['to']),
            'renameTable' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' RENAME TO ' . $this->quoteIdentifier($operation['name']),
            'check' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
                . ' CHECK (' . $operation['expression'] . ')',
            default => throw new InvalidArgumentException('Unsupported schema operation: ' . $operation['type']),
        };
    }

    /**
     * Compile a CREATE INDEX / CREATE UNIQUE INDEX statement.
     *
     * @param string $prefix The statement prefix
     * @param string $name The index name
     * @param array<int, string> $columns The indexed columns
     * @return string
     */
    private function compileIndexLike(string $prefix, string $name, array $columns): string
    {
        return $prefix . ' ' . $this->quoteIdentifier($name)
            . ' ON ' . $this->quoteIdentifier($this->table)
            . ' (' . $this->quoteColumns($columns) . ')';
    }

    /**
     * Compile a foreign key statement.
     *
     * @param array<string, mixed> $operation The operation definition
     * @return string
     */
    private function compileForeignKey(array $operation): string
    {
        if (empty($operation['table'])) {
            throw new InvalidArgumentException('Foreign key constraints require a referenced table.');
        }

        $sql = 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
            . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
            . ' FOREIGN KEY (' . $this->quoteColumns($operation['columns']) . ')'
            . ' REFERENCES ' . $this->quoteIdentifier($operation['table'])
            . ' (' . $this->quoteColumns($operation['references']) . ')';

        if (!empty($operation['onDelete'])) {
            $sql .= ' ON DELETE ' . $operation['onDelete'];
        }

        if (!empty($operation['onUpdate'])) {
            $sql .= ' ON UPDATE ' . $operation['onUpdate'];
        }

        return $sql;
    }

    /**
     * Compile a DROP INDEX statement for the configured driver.
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
     * Compile a DROP FOREIGN KEY statement for the configured driver.
     *
     * @param string $name The constraint name
     * @return string
     */
    private function compileDropForeign(string $name): string
    {
        return match ($this->driver) {
            'mysql' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table) . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($name),
            default => 'ALTER TABLE ' . $this->quoteIdentifier($this->table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($name),
        };
    }

    /**
     * Compile a rename-column statement for the configured driver.
     *
     * @param string $from The current column name
     * @param string $to The new column name
     * @return string
     */
    private function compileRenameColumn(string $from, string $to): string
    {
        return match ($this->driver) {
            'mysql' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' RENAME COLUMN ' . $this->quoteIdentifier($from) . ' TO ' . $this->quoteIdentifier($to),
            'pgsql', 'sqlite', 'sqlsrv', 'dblib', 'firebird', 'oci' => 'ALTER TABLE ' . $this->quoteIdentifier($this->table)
                . ' RENAME COLUMN ' . $this->quoteIdentifier($from) . ' TO ' . $this->quoteIdentifier($to),
            default => throw new InvalidArgumentException('Column rename is not supported for driver ' . $this->driver . '.'),
        };
    }

    /**
     * Build the SQL fragment for a column type.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function columnType(array $column): string
    {
        return match ($column['type']) {
            'id', 'foreignId', 'tinyInteger', 'smallInteger', 'mediumInteger', 'integer' => $this->integerType($column['type']),
            'bigInteger' => 'BIGINT',
            'string' => 'VARCHAR(' . ((int) ($column['length'] ?? 255)) . ')',
            'text' => 'TEXT',
            'longText' => $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'time' => 'TIME',
            'dateTime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'decimal' => 'DECIMAL(' . ((int) ($column['precision'] ?? 10)) . ', ' . ((int) ($column['scale'] ?? 2)) . ')',
            'float' => 'FLOAT',
            'json' => $this->driver === 'sqlite' ? 'TEXT' : 'JSON',
            'uuid' => 'CHAR(36)',
            'enum' => $this->compileEnumType($column['values'] ?? []),
            default => throw new InvalidArgumentException('Unsupported column type: ' . $column['type']),
        };
    }

    /**
     * Compile a database-specific integer type.
     *
     * @param string $type The logical integer type
     * @return string
     */
    private function integerType(string $type): string
    {
        return match ($type) {
            'tinyInteger' => 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => $this->driver === 'mysql' ? 'MEDIUMINT' : 'INTEGER',
            'bigInteger', 'id', 'foreignId' => 'BIGINT',
            default => 'INTEGER',
        };
    }

    /**
     * Compile an enum type for the current driver.
     *
     * @param array<int, string> $values The enum values
     * @return string
     */
    private function compileEnumType(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('Enum values cannot be empty.');
        }

        if ($this->driver === 'mysql') {
            return 'ENUM(' . implode(', ', array_map(fn (string $value): string => $this->quoteValue($value), $values)) . ')';
        }

        return 'TEXT';
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
        if ($value instanceof Expression) {
            return $value->value();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Turn a column argument into a normalized array.
     *
     * @param string|array<int, string>|null $columns The provided columns
     * @return array<int, string>|null
     */
    private function normalizeColumns(string|array|null $columns): ?array
    {
        if ($columns === null) {
            return null;
        }

        if (is_string($columns)) {
            return [$columns];
        }

        if ($columns === []) {
            throw new InvalidArgumentException('At least one column is required.');
        }

        return array_values($columns);
    }

    /**
     * Quote a list of identifiers.
     *
     * @param array<int, string> $columns The identifiers
     * @return string
     */
    private function quoteColumns(array $columns): string
    {
        return implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
    }

    /**
     * Build a deterministic index name.
     *
     * @param array<int, string> $columns The indexed columns
     * @param string $type The index type
     * @return string
     */
    private function indexName(array $columns, string $type): string
    {
        return $this->table . '_' . implode('_', $columns) . '_' . $type;
    }

    /**
     * Build a deterministic foreign key name.
     *
     * @param array<int, string> $columns The local columns
     * @param string $table The referenced table
     * @return string
     */
    private function foreignKeyName(array $columns): string
    {
        return $this->table . '_' . implode('_', $columns) . '_foreign';
    }
}
