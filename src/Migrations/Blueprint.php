<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;

/**
 * Collects schema operations for a migration.
 *
 * MySQL (8.0.19+) and PostgreSQL (12+) are first-class targets; other PDO
 * drivers receive the common ANSI-ish SQL and may need Schema::statement().
 */
final class Blueprint
{
    private const FOREIGN_ACTIONS = ['CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT', 'NO ACTION'];

    /**
     * Column types that MySQL only accepts a default for as an expression.
     */
    private const MYSQL_EXPRESSION_DEFAULT_TYPES = ['text', 'mediumText', 'longText', 'binary', 'json', 'jsonb'];

    /**
     * Shared PostgreSQL trigger function that emulates ON UPDATE CURRENT_TIMESTAMP.
     */
    private const PG_TOUCH_FUNCTION = 'CREATE OR REPLACE FUNCTION sfphp_set_current_timestamp() RETURNS TRIGGER AS $$ '
        . 'BEGIN NEW := jsonb_populate_record(NEW, jsonb_build_object(TG_ARGV[0], CURRENT_TIMESTAMP)); RETURN NEW; END; '
        . '$$ LANGUAGE plpgsql';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $columns = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $operations = [];

    /**
     * @var array<string, string|null>
     */
    private array $tableOptions = [
        'engine' => null,
        'charset' => null,
        'collation' => null,
        'comment' => null,
    ];

    private ?int $currentColumnIndex = null;
    private ?int $currentForeignIndex = null;
    private ?int $currentIndexIndex = null;

    /**
     * Create a blueprint for the given table.
     *
     * @param string $table The table name, optionally qualified as "schema.table"
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
     * Add an auto-incrementing integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function increments(string $name = 'id'): self
    {
        return $this->column($name, 'increments', [
            'autoIncrement' => true,
            'primary' => true,
            'unsigned' => true,
        ]);
    }

    /**
     * Add an auto-incrementing small integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function smallIncrements(string $name = 'id'): self
    {
        return $this->column($name, 'smallIncrements', [
            'autoIncrement' => true,
            'primary' => true,
            'unsigned' => true,
        ]);
    }

    /**
     * Add an auto-incrementing medium integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function mediumIncrements(string $name = 'id'): self
    {
        return $this->column($name, 'mediumIncrements', [
            'autoIncrement' => true,
            'primary' => true,
            'unsigned' => true,
        ]);
    }

    /**
     * Add an auto-incrementing big integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function bigIncrements(string $name = 'id'): self
    {
        return $this->column($name, 'bigIncrements', [
            'autoIncrement' => true,
            'primary' => true,
            'unsigned' => true,
        ]);
    }

    /**
     * Add an unsigned big integer column meant to reference another table.
     *
     * @param string $name The column name
     * @return self
     */
    public function foreignId(string $name): self
    {
        return $this->column($name, 'foreignId', ['unsigned' => true]);
    }

    /**
     * Add a UUID column meant to reference another table.
     *
     * @param string $name The column name
     * @return self
     */
    public function foreignUuid(string $name): self
    {
        return $this->uuid($name);
    }

    /**
     * Add a ULID column meant to reference another table.
     *
     * @param string $name The column name
     * @return self
     */
    public function foreignUlid(string $name): self
    {
        return $this->ulid($name);
    }

    /**
     * Add a tiny integer column (SMALLINT on PostgreSQL).
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
     * Add a medium integer column (INTEGER on PostgreSQL).
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
     * Add an unsigned tiny integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function unsignedTinyInteger(string $name): self
    {
        return $this->tinyInteger($name)->unsigned();
    }

    /**
     * Add an unsigned small integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function unsignedSmallInteger(string $name): self
    {
        return $this->smallInteger($name)->unsigned();
    }

    /**
     * Add an unsigned medium integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function unsignedMediumInteger(string $name): self
    {
        return $this->mediumInteger($name)->unsigned();
    }

    /**
     * Add an unsigned integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function unsignedInteger(string $name): self
    {
        return $this->integer($name)->unsigned();
    }

    /**
     * Add an unsigned big integer column.
     *
     * @param string $name The column name
     * @return self
     */
    public function unsignedBigInteger(string $name): self
    {
        return $this->bigInteger($name)->unsigned();
    }

    /**
     * Add a variable-length string column.
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
     * Add a fixed-length string column.
     *
     * @param string $name The column name
     * @param int $length The length
     * @return self
     */
    public function char(string $name, int $length = 255): self
    {
        return $this->column($name, 'char', ['length' => $length]);
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
     * Add a medium text column (TEXT on PostgreSQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function mediumText(string $name): self
    {
        return $this->column($name, 'mediumText');
    }

    /**
     * Add a long text column (TEXT on PostgreSQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function longText(string $name): self
    {
        return $this->column($name, 'longText');
    }

    /**
     * Add a binary column (BLOB on MySQL, BYTEA on PostgreSQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function binary(string $name): self
    {
        return $this->column($name, 'binary');
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
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function time(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'time', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add a time column with time zone (TIME on MySQL).
     *
     * @param string $name The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function timeTz(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'timeTz', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add a date-time column (DATETIME on MySQL, TIMESTAMP on PostgreSQL).
     *
     * @param string $name The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function dateTime(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'dateTime', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add a date-time column with time zone (DATETIME on MySQL, TIMESTAMPTZ on PostgreSQL).
     *
     * @param string $name The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function dateTimeTz(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'dateTimeTz', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add a timestamp column.
     *
     * @param string $name The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function timestamp(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'timestamp', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add a timestamp column with time zone (TIMESTAMPTZ on PostgreSQL).
     *
     * @param string $name The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function timestampTz(string $name, ?int $precision = null): self
    {
        return $this->column($name, 'timestampTz', ['precision' => $this->assertPrecision($precision)]);
    }

    /**
     * Add created_at and updated_at timestamp columns.
     *
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function timestamps(?int $precision = null): self
    {
        $this->timestamp('created_at', $precision)->useCurrent();
        $this->timestamp('updated_at', $precision)->useCurrent();

        return $this;
    }

    /**
     * Add created_at and updated_at timestamp columns with time zone.
     *
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function timestampsTz(?int $precision = null): self
    {
        $this->timestampTz('created_at', $precision)->useCurrent();
        $this->timestampTz('updated_at', $precision)->useCurrent();

        return $this;
    }

    /**
     * Add a decimal column.
     *
     * @param string $name The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
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
     * Add an unsigned decimal column.
     *
     * @param string $name The column name
     * @param int $precision The total number of digits
     * @param int $scale The number of digits after the decimal point
     * @return self
     */
    public function unsignedDecimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->column($name, 'decimal', [
            'precision' => $precision,
            'scale' => $scale,
            'unsigned' => true,
        ]);
    }

    /**
     * Add a single-precision floating point column (FLOAT on MySQL, REAL on PostgreSQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function float(string $name): self
    {
        return $this->column($name, 'float');
    }

    /**
     * Add a double-precision floating point column.
     *
     * @param string $name The column name
     * @return self
     */
    public function double(string $name): self
    {
        return $this->column($name, 'double');
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
     * Add a binary JSON column (JSONB on PostgreSQL, JSON on MySQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function jsonb(string $name): self
    {
        return $this->column($name, 'jsonb');
    }

    /**
     * Add a UUID column (UUID on PostgreSQL, CHAR(36) on MySQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function uuid(string $name): self
    {
        return $this->column($name, 'uuid');
    }

    /**
     * Add a ULID column.
     *
     * @param string $name The column name
     * @return self
     */
    public function ulid(string $name): self
    {
        return $this->column($name, 'ulid');
    }

    /**
     * Add an IP address column (INET on PostgreSQL, VARCHAR(45) on MySQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function ipAddress(string $name): self
    {
        return $this->column($name, 'ipAddress');
    }

    /**
     * Add a MAC address column (MACADDR on PostgreSQL, VARCHAR(17) on MySQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function macAddress(string $name): self
    {
        return $this->column($name, 'macAddress');
    }

    /**
     * Add a year column (YEAR on MySQL, SMALLINT on PostgreSQL).
     *
     * @param string $name The column name
     * @return self
     */
    public function year(string $name): self
    {
        return $this->column($name, 'year');
    }

    /**
     * Add an enum column.
     *
     * Native ENUM on MySQL; VARCHAR plus a CHECK constraint on PostgreSQL.
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
     * Add a set column (MySQL only).
     *
     * @param string $name The column name
     * @param array<int, string> $values The allowed values
     * @return self
     */
    public function set(string $name, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Set values cannot be empty.');
        }

        return $this->column($name, 'set', ['values' => array_values($values)]);
    }

    /**
     * Add a column with a driver-specific type written as raw SQL.
     *
     * The definition is emitted as-is, so never build it from user input.
     *
     * @param string $name The column name
     * @param string $definition The raw SQL type, such as "INET" or "INTEGER[]"
     * @return self
     */
    public function rawColumn(string $name, string $definition): self
    {
        if (trim($definition) === '') {
            throw new InvalidArgumentException('A raw column definition cannot be empty.');
        }

        return $this->column($name, 'raw', ['rawType' => trim($definition)]);
    }

    /**
     * Mark the current column as nullable.
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
     * Set the default value for the current column.
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
     * Default the current column to the current timestamp.
     *
     * @return self
     */
    public function useCurrent(): self
    {
        $precision = $this->currentColumn()['precision'] ?? null;

        return $this->default($this->raw('CURRENT_TIMESTAMP' . ($precision ? "($precision)" : '')));
    }

    /**
     * Refresh the current column with the current timestamp on every update.
     *
     * Native ON UPDATE on MySQL; a BEFORE UPDATE trigger on PostgreSQL.
     *
     * @return self
     */
    public function useCurrentOnUpdate(): self
    {
        $column =& $this->currentColumn();
        $column['useCurrentOnUpdate'] = true;

        return $this;
    }

    /**
     * Mark the current numeric column as unsigned.
     *
     * Ignored on PostgreSQL, which has no unsigned integers.
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
     * Mark the current column as auto-incrementing.
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
     * Change an existing column instead of adding it.
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
     * Place the current column after another one (MySQL only).
     *
     * @param string $column The existing column name
     * @return self
     */
    public function after(string $column): self
    {
        $current =& $this->currentColumn();
        $current['after'] = $column;

        return $this;
    }

    /**
     * Place the current column first in the table (MySQL only).
     *
     * @return self
     */
    public function first(): self
    {
        $current =& $this->currentColumn();
        $current['first'] = true;

        return $this;
    }

    /**
     * Set the comment for the current column.
     *
     * @param string $comment The comment text
     * @return self
     */
    public function comment(string $comment): self
    {
        $current =& $this->currentColumn();
        $current['comment'] = $comment;

        return $this;
    }

    /**
     * Set the character set for the current column (MySQL only).
     *
     * @param string $charset The character set name
     * @return self
     */
    public function charset(string $charset): self
    {
        $current =& $this->currentColumn();
        $current['charset'] = $charset;

        return $this;
    }

    /**
     * Set the collation for the current column.
     *
     * @param string $collation The collation name
     * @return self
     */
    public function collation(string $collation): self
    {
        $current =& $this->currentColumn();
        $current['collation'] = $collation;

        return $this;
    }

    /**
     * Make the current column a virtual generated column (MySQL only).
     *
     * @param string $expression The raw SQL expression
     * @return self
     */
    public function virtualAs(string $expression): self
    {
        $current =& $this->currentColumn();
        $current['virtualAs'] = $expression;

        return $this;
    }

    /**
     * Make the current column a stored generated column.
     *
     * @param string $expression The raw SQL expression
     * @return self
     */
    public function storedAs(string $expression): self
    {
        $current =& $this->currentColumn();
        $current['storedAs'] = $expression;

        return $this;
    }

    /**
     * Set the storage engine for the table (MySQL only).
     *
     * @param string $engine The engine name, such as "InnoDB"
     * @return self
     */
    public function engine(string $engine): self
    {
        $this->tableOptions['engine'] = $engine;

        return $this;
    }

    /**
     * Set the default character set for the table (MySQL only).
     *
     * @param string $charset The character set name
     * @return self
     */
    public function tableCharset(string $charset): self
    {
        $this->tableOptions['charset'] = $charset;

        return $this;
    }

    /**
     * Set the default collation for the table (MySQL only).
     *
     * @param string $collation The collation name
     * @return self
     */
    public function tableCollation(string $collation): self
    {
        $this->tableOptions['collation'] = $collation;

        return $this;
    }

    /**
     * Set the comment for the table.
     *
     * @param string $comment The comment text
     * @return self
     */
    public function tableComment(string $comment): self
    {
        $this->tableOptions['comment'] = $comment;

        return $this;
    }

    /**
     * Create a raw SQL expression.
     *
     * @param string $sql The SQL fragment
     * @return Expression
     */
    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * Add a unique index.
     *
     * Takes its columns the same way index(), primary() and fullText() do. It
     * used to take a name as its only argument and always apply to the column
     * being defined, which made the composite form everyone reaches for —
     * `unique(['email', 'tenant_id'])` — a type error. That form was in the
     * documentation and in the integration tests before it was in the code.
     *
     * @param string|array<int, string>|null $columns The columns or null for the current column
     * @param string|null $name The optional index name
     * @return self
     */
    public function unique(string|array|null $columns = null, ?string $name = null): self
    {
        $this->pushConstraint('unique', $this->normalizeColumns($columns), $name);

        return $this;
    }

    /**
     * Add an index.
     *
     * @param string|array<int, string>|null $columns The indexed columns or null for the current column
     * @param string|null $name The optional index name
     * @return self
     */
    public function index(string|array|null $columns = null, ?string $name = null): self
    {
        $this->pushConstraint('index', $this->normalizeColumns($columns), $name);

        return $this;
    }

    /**
     * Add a primary key.
     *
     * @param string|array<int, string>|null $columns The key columns or null for the current column
     * @param string|null $name The optional constraint name
     * @return self
     */
    public function primary(string|array|null $columns = null, ?string $name = null): self
    {
        $this->pushConstraint('primary', $this->normalizeColumns($columns), $name);

        return $this;
    }

    /**
     * Add a full-text index.
     *
     * FULLTEXT on MySQL; a GIN index over to_tsvector() on PostgreSQL.
     *
     * @param string|array<int, string>|null $columns The indexed columns or null for the current column
     * @param string|null $name The optional index name
     * @param string $language The text search configuration used on PostgreSQL
     * @return self
     */
    public function fullText(string|array|null $columns = null, ?string $name = null, string $language = 'english'): self
    {
        $this->pushConstraint('fullText', $this->normalizeColumns($columns), $name);
        $index =& $this->currentIndex();
        $index['language'] = $language;

        return $this;
    }

    /**
     * Set the access method of the last index (btree, hash, gin, gist, spgist, brin).
     *
     * @param string $algorithm The index algorithm
     * @return self
     */
    public function algorithm(string $algorithm): self
    {
        $index =& $this->currentIndex();
        $index['algorithm'] = strtolower($algorithm);

        return $this;
    }

    /**
     * Restrict the last index to matching rows (partial index, PostgreSQL only).
     *
     * @param string $expression The raw SQL predicate
     * @return self
     */
    public function where(string $expression): self
    {
        $index =& $this->currentIndex();
        $index['where'] = $expression;

        return $this;
    }

    /**
     * Add a foreign key that starts from the current column.
     *
     * Without a table, it is inferred from the column's name: user_id points
     * at users, category_id at categories. `make:migration` writes
     * `author_id:foreignId:constrained`, which has no table to pass, and
     * used to generate a call that could not run.
     *
     * @param string|null $table The referenced table, or null to infer it
     * @param string $references The referenced column
     * @param string|null $name The optional constraint name
     * @return self
     */
    public function constrained(?string $table = null, string $references = 'id', ?string $name = null): self
    {
        $column = $this->currentColumn();

        if ($table === null) {
            if (!str_ends_with($column['name'], '_id')) {
                throw new \InvalidArgumentException(sprintf(
                    'constrained() cannot infer a table from "%s": name it, as in constrained(\'users\').',
                    $column['name']
                ));
            }

            $table = \SfphpProject\src\Str::plural(substr($column['name'], 0, -3));
        }

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
     * Start a foreign key definition.
     *
     * @param string|array<int, string> $columns The local columns
     * @param string|null $name The optional constraint name
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
     * Set the referenced table and column of the current foreign key.
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

        return $this;
    }

    /**
     * Set the ON DELETE action of the current foreign key.
     *
     * @param string $action CASCADE, SET NULL, SET DEFAULT, RESTRICT or NO ACTION
     * @return self
     */
    public function onDelete(string $action): self
    {
        $foreign =& $this->currentForeign();
        $foreign['onDelete'] = $this->assertForeignAction($action);

        return $this;
    }

    /**
     * Set the ON UPDATE action of the current foreign key.
     *
     * @param string $action CASCADE, SET NULL, SET DEFAULT, RESTRICT or NO ACTION
     * @return self
     */
    public function onUpdate(string $action): self
    {
        $foreign =& $this->currentForeign();
        $foreign['onUpdate'] = $this->assertForeignAction($action);

        return $this;
    }

    /**
     * Make the current foreign key deferrable (PostgreSQL only).
     *
     * @param bool $initiallyDeferred Whether the check waits until commit by default
     * @return self
     */
    public function deferrable(bool $initiallyDeferred = false): self
    {
        $foreign =& $this->currentForeign();
        $foreign['deferrable'] = true;
        $foreign['initiallyDeferred'] = $initiallyDeferred;

        return $this;
    }

    /**
     * Cascade deletes to the referencing rows.
     *
     * @return self
     */
    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Set the referencing column to NULL on delete.
     *
     * @return self
     */
    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Reject deletes that would orphan referencing rows.
     *
     * @return self
     */
    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Use NO ACTION on delete.
     *
     * @return self
     */
    public function noActionOnDelete(): self
    {
        return $this->onDelete('NO ACTION');
    }

    /**
     * Cascade key updates to the referencing rows.
     *
     * @return self
     */
    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Set the referencing column to NULL on update.
     *
     * @return self
     */
    public function nullOnUpdate(): self
    {
        return $this->onUpdate('SET NULL');
    }

    /**
     * Reject updates that would orphan referencing rows.
     *
     * @return self
     */
    public function restrictOnUpdate(): self
    {
        return $this->onUpdate('RESTRICT');
    }

    /**
     * Use NO ACTION on update.
     *
     * @return self
     */
    public function noActionOnUpdate(): self
    {
        return $this->onUpdate('NO ACTION');
    }

    /**
     * Drop one or more columns.
     *
     * @param string|array<int, string> $columns The column names
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
     * Drop an index.
     *
     * @param string|array<int, string> $columns The indexed columns
     * @param string|null $name The optional index name
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
     * Drop a unique index.
     *
     * @param string|array<int, string> $columns The indexed columns
     * @param string|null $name The optional index name
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
     * Drop a full-text index.
     *
     * @param string|array<int, string> $columns The indexed columns
     * @param string|null $name The optional index name
     * @return self
     */
    public function dropFullText(string|array $columns, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropIndex',
            'name' => $name ?: $this->indexName($this->normalizeColumns($columns), 'fullText'),
        ];

        return $this;
    }

    /**
     * Drop the primary key.
     *
     * @param string|null $name The constraint name (PostgreSQL); defaults to "<table>_pkey"
     * @return self
     */
    public function dropPrimary(?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropPrimary',
            'name' => $name ?: $this->indexName([], 'primary'),
        ];

        return $this;
    }

    /**
     * Drop a foreign key.
     *
     * @param string|array<int, string> $columns The local columns
     * @param string|null $name The optional constraint name
     * @return self
     */
    public function dropForeign(string|array $columns, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'dropForeign',
            'name' => $name ?: $this->foreignKeyName($this->normalizeColumns($columns)),
        ];

        return $this;
    }

    /**
     * Drop a CHECK constraint by name.
     *
     * @param string $name The constraint name
     * @return self
     */
    public function dropCheck(string $name): self
    {
        $this->operations[] = [
            'type' => 'dropCheck',
            'name' => $name,
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
     * Rename an index.
     *
     * @param string $from The current index name
     * @param string $to The new index name
     * @return self
     */
    public function renameIndex(string $from, string $to): self
    {
        $this->operations[] = [
            'type' => 'renameIndex',
            'from' => $from,
            'to' => $to,
        ];

        return $this;
    }

    /**
     * Rename the table.
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
     * The expression is emitted as-is, so never build it from user input.
     *
     * @param string $expression The raw SQL condition
     * @param string|null $name The optional constraint name
     * @return self
     */
    public function check(string $expression, ?string $name = null): self
    {
        $this->operations[] = [
            'type' => 'check',
            'expression' => $expression,
            'name' => $name ?: $this->shortenName(Identifier::bareTable($this->table) . '_check_' . count($this->operations)),
        ];

        return $this;
    }

    /**
     * Add polymorphic relation columns and their index.
     *
     * @param string $name The relation name
     * @return self
     */
    public function morphs(string $name): self
    {
        return $this->addMorphs($name, 'morphId', ['unsigned' => true]);
    }

    /**
     * Add nullable polymorphic relation columns and their index.
     *
     * @param string $name The relation name
     * @return self
     */
    public function nullableMorphs(string $name): self
    {
        return $this->addMorphs($name, 'morphId', ['unsigned' => true], true);
    }

    /**
     * Add UUID polymorphic relation columns and their index.
     *
     * @param string $name The relation name
     * @return self
     */
    public function uuidMorphs(string $name): self
    {
        return $this->addMorphs($name, 'morphUuid');
    }

    /**
     * Add ULID polymorphic relation columns and their index.
     *
     * @param string $name The relation name
     * @return self
     */
    public function ulidMorphs(string $name): self
    {
        return $this->addMorphs($name, 'morphUlid');
    }

    /**
     * Add a remember_token column.
     *
     * @return self
     */
    public function rememberToken(): self
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Add a nullable deleted_at timestamp column.
     *
     * @param string $column The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function softDeletes(string $column = 'deleted_at', ?int $precision = null): self
    {
        return $this->timestamp($column, $precision)->nullable();
    }

    /**
     * Add a nullable deleted_at timestamp column with time zone.
     *
     * @param string $column The column name
     * @param int|null $precision The fractional seconds precision (0-6)
     * @return self
     */
    public function softDeletesTz(string $column = 'deleted_at', ?int $precision = null): self
    {
        return $this->timestampTz($column, $precision)->nullable();
    }

    /**
     * Drop the created_at and updated_at columns.
     *
     * @return self
     */
    public function dropTimestamps(): self
    {
        return $this->dropColumn(['created_at', 'updated_at']);
    }

    /**
     * Drop the soft-delete column.
     *
     * @param string $column The column name
     * @return self
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): self
    {
        return $this->dropColumn($column);
    }

    /**
     * Drop the remember_token column.
     *
     * @return self
     */
    public function dropRememberToken(): self
    {
        return $this->dropColumn('remember_token');
    }

    /**
     * Drop polymorphic relation columns (and their index with them).
     *
     * @param string $name The relation name
     * @return self
     */
    public function dropMorphs(string $name): self
    {
        return $this->dropColumn([$name . '_id', $name . '_type']);
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
            /*
             * How many operations were already declared when this column was.
             * It is what lets an alter be emitted in the order it was written
             * — see compileAlterStatements(). Columns are appended here and
             * nowhere else, so recording it once is enough.
             */
            'afterOperation' => array_key_last($this->operations) ?? -1,
            'nullable' => false,
            'default' => null,
            'unsigned' => false,
            'autoIncrement' => false,
            'change' => false,
            'after' => null,
            'first' => false,
            'values' => [],
        ], $attributes);
        $this->currentColumnIndex = array_key_last($this->columns);

        return $this;
    }

    /**
     * Register the id and type columns of a polymorphic relation.
     *
     * @param string $name The relation name
     * @param string $idType The logical type of the id column
     * @param array<string, mixed> $idAttributes Extra attributes of the id column
     * @param bool $nullable Whether both columns are nullable
     * @return self
     */
    private function addMorphs(string $name, string $idType, array $idAttributes = [], bool $nullable = false): self
    {
        $this->column($name . '_id', $idType, $idAttributes + ['nullable' => $nullable]);
        $this->column($name . '_type', 'morphType', ['length' => 255, 'nullable' => $nullable]);
        $this->pushConstraint('index', [$name . '_id', $name . '_type'], null);

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
     * Get the current index by reference.
     *
     * @return array<string, mixed>
     */
    private function &currentIndex(): array
    {
        if ($this->currentIndexIndex === null) {
            throw new InvalidArgumentException('An index modifier must follow an index definition.');
        }

        return $this->operations[$this->currentIndexIndex];
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
        $this->currentIndexIndex = array_key_last($this->operations);
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
            'CREATE TABLE ' . $this->quoteTable($this->table)
            . ' (' . implode(', ', array_map([$this, 'compileColumn'], $this->columns)) . ')'
            . $this->compileCreateTableOptions(),
        ];

        foreach ($this->columns as $column) {
            $statements = array_merge($statements, $this->compileColumnExtras($column, false));
        }

        if ($this->isPostgres() && $this->tableOptions['comment'] !== null) {
            $statements[] = $this->compileTableComment();
        }

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
        $operationsEmitted = 0;

        /*
         * Emitted in the order the blueprint was written, rather than all
         * columns and then all operations.
         *
         * Grouping them looked harmless and was not: renaming a column and then
         * modifying it under its new name — which is how anyone would write it,
         * and how the documentation shows it — produced the modification first
         * and failed with "unknown column". Any fixed grouping is wrong for
         * somebody, because a rename changes what every later statement has to
         * call the column. Declaration order is the only rule that is right in
         * both directions.
         */
        foreach ($this->columns as $column) {
            $declaredAfter = ($column['afterOperation'] ?? -1) + 1;

            while ($operationsEmitted < $declaredAfter) {
                $statements[] = $this->compileOperation($this->operations[$operationsEmitted]);
                $operationsEmitted++;
            }

            $changing = !empty($column['change']);

            $statements = array_merge(
                $statements,
                $changing ? $this->compileChangeColumn($column) : [$this->compileAddColumn($column)],
                $this->compileColumnExtras($column, $changing)
            );
        }

        while ($operationsEmitted < count($this->operations)) {
            $statements[] = $this->compileOperation($this->operations[$operationsEmitted]);
            $operationsEmitted++;
        }

        return array_merge($statements, $this->compileAlterTableOptions());
    }

    /**
     * Compile an ADD COLUMN statement.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function compileAddColumn(array $column): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($this->table)
            . ' ADD COLUMN ' . $this->compileColumn($column)
            . $this->compileColumnPosition($column);
    }

    /**
     * Compile the AFTER / FIRST clause of a column (MySQL only).
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function compileColumnPosition(array $column): string
    {
        if (!$this->isMySql()) {
            return '';
        }

        if (!empty($column['first'])) {
            return ' FIRST';
        }

        return !empty($column['after']) ? ' AFTER ' . $this->quoteIdentifier($column['after']) : '';
    }

    /**
     * Compile the table options that go at the end of CREATE TABLE (MySQL only).
     *
     * @return string
     */
    private function compileCreateTableOptions(): string
    {
        $options = $this->mysqlTableOptions();

        return $options === [] ? '' : ' ' . implode(' ', $options);
    }

    /**
     * Compile the statements for table options changed by an alter blueprint.
     *
     * @return array<int, string>
     */
    private function compileAlterTableOptions(): array
    {
        if ($this->isPostgres()) {
            return $this->tableOptions['comment'] !== null ? [$this->compileTableComment()] : [];
        }

        $options = $this->mysqlTableOptions();

        return $options === [] ? [] : ['ALTER TABLE ' . $this->quoteTable($this->table) . ' ' . implode(' ', $options)];
    }

    /**
     * Build the MySQL table option fragments.
     *
     * @return array<int, string>
     */
    private function mysqlTableOptions(): array
    {
        if (!$this->isMySql()) {
            return [];
        }

        $options = [];

        if ($this->tableOptions['engine'] !== null) {
            $options[] = 'ENGINE=' . $this->assertSqlWord($this->tableOptions['engine']);
        }

        if ($this->tableOptions['charset'] !== null) {
            $options[] = 'DEFAULT CHARSET=' . $this->assertSqlWord($this->tableOptions['charset']);
        }

        if ($this->tableOptions['collation'] !== null) {
            $options[] = 'COLLATE=' . $this->assertSqlWord($this->tableOptions['collation']);
        }

        if ($this->tableOptions['comment'] !== null) {
            $options[] = 'COMMENT=' . $this->quoteValue($this->tableOptions['comment']);
        }

        return $options;
    }

    /**
     * Compile a COMMENT ON TABLE statement (PostgreSQL).
     *
     * @return string
     */
    private function compileTableComment(): string
    {
        return 'COMMENT ON TABLE ' . $this->quoteTable($this->table) . ' IS ' . $this->quoteValue($this->tableOptions['comment']);
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

        if ($this->isMySql()) {
            if (!empty($column['unsigned'])) {
                $sql .= ' UNSIGNED';
            }

            if (!empty($column['charset'])) {
                $sql .= ' CHARACTER SET ' . $this->assertSqlWord((string) $column['charset']);
            }

            if (!empty($column['collation'])) {
                $sql .= ' COLLATE ' . $this->assertSqlWord((string) $column['collation']);
            }
        } elseif ($this->isPostgres()) {
            if (!empty($column['collation'])) {
                $sql .= ' COLLATE ' . $this->quoteCollation((string) $column['collation']);
            }
        } elseif (!empty($column['unsigned']) && in_array($this->driver, ['sqlsrv', 'dblib'], true)) {
            $sql .= ' UNSIGNED';
        }

        $generated = $this->compileGeneratedColumn($column);
        if ($generated !== '') {
            $sql .= $generated;
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

        if ($generated === '' && array_key_exists('default', $column) && $column['default'] !== null) {
            $sql .= ' DEFAULT ' . $this->compileDefault($column);
        }

        if (!empty($column['useCurrentOnUpdate']) && $this->isMySql()) {
            $precision = $column['precision'] ?? null;
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP' . ($precision ? "($precision)" : '');
        }

        if (!empty($column['comment']) && $this->isMySql()) {
            $sql .= ' COMMENT ' . $this->quoteValue($column['comment']);
        }

        if ($this->isPostgres() && $column['type'] === 'enum') {
            $sql .= ' CONSTRAINT ' . $this->quoteIdentifier($this->enumCheckName($column['name']))
                . ' CHECK (' . $this->enumCheckExpression($column) . ')';
        }

        return $sql;
    }

    /**
     * Compile the generated-column clause of a column.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function compileGeneratedColumn(array $column): string
    {
        $virtual = $column['virtualAs'] ?? null;
        $stored = $column['storedAs'] ?? null;

        if ($virtual === null && $stored === null) {
            return '';
        }

        if ($virtual !== null && $stored !== null) {
            throw new InvalidArgumentException('A column cannot be both virtualAs() and storedAs().');
        }

        if ($virtual !== null && !$this->isMySql()) {
            throw new InvalidArgumentException('Virtual generated columns are only supported by MySQL; use storedAs().');
        }

        if (!$this->isMySql() && !$this->isPostgres()) {
            throw new InvalidArgumentException('Generated columns are not supported for driver ' . $this->driver . '.');
        }

        return ' GENERATED ALWAYS AS (' . ($virtual ?? $stored) . ') ' . ($virtual !== null ? 'VIRTUAL' : 'STORED');
    }

    /**
     * Compile the default value of a column.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function compileDefault(array $column): string
    {
        $default = $this->quoteValue($column['default']);

        if (
            $this->isMySql()
            && !($column['default'] instanceof Expression)
            && in_array($column['type'], self::MYSQL_EXPRESSION_DEFAULT_TYPES, true)
        ) {
            return '(' . $default . ')';
        }

        return $default;
    }

    /**
     * Compile the extra PostgreSQL statements a column needs (comments, triggers, enum checks).
     *
     * @param array<string, mixed> $column The column data
     * @param bool $changing Whether the column replaces an existing one
     * @return array<int, string>
     */
    private function compileColumnExtras(array $column, bool $changing): array
    {
        if (!$this->isPostgres()) {
            return [];
        }

        $statements = [];
        $table = $this->quoteTable($this->table);
        $name = $this->quoteIdentifier($column['name']);

        if ($changing) {
            $constraint = $this->quoteIdentifier($this->enumCheckName($column['name']));
            $statements[] = 'ALTER TABLE ' . $table . ' DROP CONSTRAINT IF EXISTS ' . $constraint;

            if ($column['type'] === 'enum') {
                $statements[] = 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $constraint
                    . ' CHECK (' . $this->enumCheckExpression($column) . ')';
            }
        }

        if (!empty($column['comment'])) {
            $statements[] = 'COMMENT ON COLUMN ' . $table . '.' . $name . ' IS ' . $this->quoteValue($column['comment']);
        } elseif ($changing) {
            $statements[] = 'COMMENT ON COLUMN ' . $table . '.' . $name . ' IS NULL';
        }

        $trigger = $this->quoteIdentifier($this->shortenName(Identifier::bareTable($this->table) . '_' . $column['name'] . '_on_update'));

        if (!empty($column['useCurrentOnUpdate'])) {
            $statements[] = self::PG_TOUCH_FUNCTION;

            if ($changing) {
                $statements[] = 'DROP TRIGGER IF EXISTS ' . $trigger . ' ON ' . $table;
            }

            $statements[] = 'CREATE TRIGGER ' . $trigger . ' BEFORE UPDATE ON ' . $table
                . ' FOR EACH ROW EXECUTE FUNCTION sfphp_set_current_timestamp(' . $this->quoteValue($column['name']) . ')';
        } elseif ($changing) {
            $statements[] = 'DROP TRIGGER IF EXISTS ' . $trigger . ' ON ' . $table;
        }

        return $statements;
    }

    /**
     * Compile a change-column statement for the current driver.
     *
     * @param array<string, mixed> $column The column data
     * @return array<int, string>
     */
    private function compileChangeColumn(array $column): array
    {
        $table = $this->quoteTable($this->table);

        if ($this->isMySql()) {
            return [
                'ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $this->compileColumn($column)
                . $this->compileColumnPosition($column),
            ];
        }

        if ($this->isPostgres()) {
            return $this->compilePostgresChangeColumn($column);
        }

        if (in_array($this->driver, ['sqlsrv', 'dblib'], true)) {
            return ['ALTER TABLE ' . $table . ' ALTER COLUMN ' . $this->compileColumn($column)];
        }

        throw new InvalidArgumentException('Column changes are not supported for driver ' . $this->driver . '.');
    }

    /**
     * Compile the statements that redefine a column on PostgreSQL.
     *
     * @param array<string, mixed> $column The column data
     * @return array<int, string>
     */
    private function compilePostgresChangeColumn(array $column): array
    {
        if (!empty($column['virtualAs']) || !empty($column['storedAs'])) {
            throw new InvalidArgumentException('Changing a generated column is not supported on PostgreSQL.');
        }

        $alter = 'ALTER TABLE ' . $this->quoteTable($this->table) . ' ALTER COLUMN ' . $this->quoteIdentifier($column['name']);
        $type = $this->columnType($column);
        $statements = [];

        if (empty($column['autoIncrement'])) {
            $statements[] = $alter . ' DROP DEFAULT';
        }

        $statements[] = $alter . ' TYPE ' . $type . ' USING ' . $this->quoteIdentifier($column['name']) . '::' . $type;

        if (!empty($column['nullable'])) {
            $statements[] = $alter . ' DROP NOT NULL';
        } elseif (empty($column['autoIncrement'])) {
            $statements[] = $alter . ' SET NOT NULL';
        }

        if (array_key_exists('default', $column) && $column['default'] !== null) {
            $statements[] = $alter . ' SET DEFAULT ' . $this->compileDefault($column);
        }

        return $statements;
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
            'unique', 'index' => $this->compileIndex($operation),
            'fullText' => $this->compileFullText($operation),
            'primary' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
                . ' PRIMARY KEY (' . $this->quoteColumns($operation['columns']) . ')',
            'foreign' => $this->compileForeignKey($operation),
            'dropColumn' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' DROP COLUMN ' . $this->quoteIdentifier($operation['name']),
            'dropIndex', 'dropUnique' => $this->compileDropIndex($operation['name']),
            'dropPrimary' => $this->compileDropPrimary($operation['name']),
            'dropForeign' => $this->compileDropForeign($operation['name']),
            'dropCheck' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' DROP CONSTRAINT ' . $this->quoteIdentifier($operation['name']),
            'renameColumn' => $this->compileRenameColumn($operation['from'], $operation['to']),
            'renameIndex' => $this->compileRenameIndex($operation['from'], $operation['to']),
            'renameTable' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' RENAME TO ' . $this->quoteIdentifier($operation['name']),
            'check' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
                . ' CHECK (' . $operation['expression'] . ')',
            default => throw new InvalidArgumentException('Unsupported schema operation: ' . $operation['type']),
        };
    }

    /**
     * Compile a CREATE INDEX / CREATE UNIQUE INDEX statement.
     *
     * @param array<string, mixed> $operation The operation definition
     * @return string
     */
    private function compileIndex(array $operation): string
    {
        $algorithm = isset($operation['algorithm']) ? $this->assertIndexAlgorithm($operation['algorithm']) : null;

        $sql = 'CREATE ' . ($operation['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX '
            . $this->quoteIdentifier($operation['name']);

        if ($algorithm !== null && $this->isMySql()) {
            $sql .= ' USING ' . strtoupper($algorithm);
        }

        $sql .= ' ON ' . $this->quoteTable($this->table);

        if ($algorithm !== null && !$this->isMySql()) {
            $sql .= ' USING ' . $algorithm;
        }

        $sql .= ' (' . $this->quoteColumns($operation['columns']) . ')';

        if (isset($operation['where'])) {
            if (!$this->isPostgres()) {
                throw new InvalidArgumentException('Partial indexes are only supported by PostgreSQL.');
            }

            $sql .= ' WHERE ' . $operation['where'];
        }

        return $sql;
    }

    /**
     * Compile a full-text index statement.
     *
     * @param array<string, mixed> $operation The operation definition
     * @return string
     */
    private function compileFullText(array $operation): string
    {
        $name = $this->quoteIdentifier($operation['name']);
        $table = $this->quoteTable($this->table);

        if ($this->isMySql()) {
            return 'CREATE FULLTEXT INDEX ' . $name . ' ON ' . $table . ' (' . $this->quoteColumns($operation['columns']) . ')';
        }

        if ($this->isPostgres()) {
            $language = $this->assertSqlWord($operation['language'] ?? 'english');
            $vectors = array_map(
                fn (string $column): string => "to_tsvector('" . $language . "', " . $this->quoteIdentifier($column) . ')',
                $operation['columns']
            );

            return 'CREATE INDEX ' . $name . ' ON ' . $table . ' USING gin ((' . implode(' || ', $vectors) . '))';
        }

        throw new InvalidArgumentException('Full-text indexes are not supported for driver ' . $this->driver . '.');
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

        $sql = 'ALTER TABLE ' . $this->quoteTable($this->table)
            . ' ADD CONSTRAINT ' . $this->quoteIdentifier($operation['name'])
            . ' FOREIGN KEY (' . $this->quoteColumns($operation['columns']) . ')'
            . ' REFERENCES ' . $this->quoteTable($operation['table'])
            . ' (' . $this->quoteColumns($operation['references']) . ')';

        foreach (['onDelete' => 'ON DELETE', 'onUpdate' => 'ON UPDATE'] as $key => $clause) {
            if (empty($operation[$key])) {
                continue;
            }

            if ($this->isMySql() && $operation[$key] === 'SET DEFAULT') {
                throw new InvalidArgumentException('MySQL (InnoDB) does not support SET DEFAULT in foreign keys.');
            }

            $sql .= ' ' . $clause . ' ' . $operation[$key];
        }

        if (!empty($operation['deferrable'])) {
            if (!$this->isPostgres()) {
                throw new InvalidArgumentException('Deferrable foreign keys are only supported by PostgreSQL.');
            }

            $sql .= ' DEFERRABLE INITIALLY ' . (!empty($operation['initiallyDeferred']) ? 'DEFERRED' : 'IMMEDIATE');
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
        if ($this->isMySql()) {
            return 'DROP INDEX ' . $this->quoteIdentifier($name) . ' ON ' . $this->quoteTable($this->table);
        }

        return 'DROP INDEX ' . $this->qualifyWithTableSchema($name);
    }

    /**
     * Compile a DROP PRIMARY KEY statement for the configured driver.
     *
     * @param string $name The constraint name
     * @return string
     */
    private function compileDropPrimary(string $name): string
    {
        $table = $this->quoteTable($this->table);

        return $this->isMySql()
            ? 'ALTER TABLE ' . $table . ' DROP PRIMARY KEY'
            : 'ALTER TABLE ' . $table . ' DROP CONSTRAINT ' . $this->quoteIdentifier($name);
    }

    /**
     * Compile a DROP FOREIGN KEY statement for the configured driver.
     *
     * @param string $name The constraint name
     * @return string
     */
    private function compileDropForeign(string $name): string
    {
        $table = $this->quoteTable($this->table);

        return match ($this->driver) {
            'mysql' => 'ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($name),
            default => 'ALTER TABLE ' . $table . ' DROP CONSTRAINT ' . $this->quoteIdentifier($name),
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
            'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'dblib', 'firebird', 'oci' => 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' RENAME COLUMN ' . $this->quoteIdentifier($from) . ' TO ' . $this->quoteIdentifier($to),
            default => throw new InvalidArgumentException('Column rename is not supported for driver ' . $this->driver . '.'),
        };
    }

    /**
     * Compile a rename-index statement for the configured driver.
     *
     * @param string $from The current index name
     * @param string $to The new index name
     * @return string
     */
    private function compileRenameIndex(string $from, string $to): string
    {
        if ($this->isMySql()) {
            return 'ALTER TABLE ' . $this->quoteTable($this->table)
                . ' RENAME INDEX ' . $this->quoteIdentifier($from) . ' TO ' . $this->quoteIdentifier($to);
        }

        if ($this->isPostgres()) {
            return 'ALTER INDEX ' . $this->qualifyWithTableSchema($from) . ' RENAME TO ' . $this->quoteIdentifier($to);
        }

        throw new InvalidArgumentException('Index rename is not supported for driver ' . $this->driver . '.');
    }

    /**
     * Quote an index-like name, prefixing the table schema when there is one.
     *
     * @param string $name The unqualified name
     * @return string
     */
    private function qualifyWithTableSchema(string $name): string
    {
        [$schema] = Identifier::split($this->table);

        return ($schema !== null ? $this->quoteIdentifier($schema) . '.' : '') . $this->quoteIdentifier($name);
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
            'increments', 'smallIncrements', 'mediumIncrements', 'bigIncrements',
            'id', 'foreignId', 'tinyInteger', 'smallInteger', 'mediumInteger', 'integer' => $this->integerType($column['type']),
            'bigInteger' => 'BIGINT',
            'string' => 'VARCHAR(' . ((int) ($column['length'] ?? 255)) . ')',
            'char' => 'CHAR(' . ((int) ($column['length'] ?? 255)) . ')',
            'text' => 'TEXT',
            'mediumText' => $this->isMySql() ? 'MEDIUMTEXT' : 'TEXT',
            'longText' => $this->isMySql() ? 'LONGTEXT' : 'TEXT',
            'binary' => $this->isPostgres() ? 'BYTEA' : 'BLOB',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'time', 'timeTz' => $this->timeType($column['type'] === 'timeTz', $column['precision'] ?? null),
            'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => $this->dateTimeType($column['type'], $column['precision'] ?? null),
            'decimal' => 'DECIMAL(' . ((int) ($column['precision'] ?? 10)) . ', ' . ((int) ($column['scale'] ?? 2)) . ')',
            'float' => $this->isPostgres() ? 'REAL' : 'FLOAT',
            'double' => $this->isPostgres() ? 'DOUBLE PRECISION' : 'DOUBLE',
            'json' => $this->driver === 'sqlite' ? 'TEXT' : 'JSON',
            'jsonb' => match ($this->driver) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'JSON',
            },
            'uuid', 'morphUuid' => $this->isPostgres() ? 'UUID' : 'CHAR(36)',
            'ulid' => 'CHAR(26)',
            'ipAddress' => $this->isPostgres() ? 'INET' : 'VARCHAR(45)',
            'macAddress' => $this->isPostgres() ? 'MACADDR' : 'VARCHAR(17)',
            'year' => $this->isMySql() ? 'YEAR' : 'SMALLINT',
            'morphId' => 'BIGINT',
            'morphUlid' => 'CHAR(26)',
            'morphType' => 'VARCHAR(' . ((int) ($column['length'] ?? 255)) . ')',
            'enum' => $this->compileEnumType($column['values'] ?? []),
            'set' => $this->compileSetType($column['values'] ?? []),
            'raw' => (string) $column['rawType'],
            default => throw new InvalidArgumentException('Unsupported column type: ' . $column['type']),
        };
    }

    /**
     * Build a temporal SQL type.
     *
     * @param string $type The logical type: dateTime, dateTimeTz, timestamp or timestampTz
     * @param int|null $precision The fractional seconds precision
     * @return string
     */
    private function dateTimeType(string $type, ?int $precision): string
    {
        $fraction = $precision !== null ? '(' . $precision . ')' : '';
        $timezone = in_array($type, ['dateTimeTz', 'timestampTz'], true);

        return match (true) {
            $this->isPostgres() => ($timezone ? 'TIMESTAMPTZ' : 'TIMESTAMP') . $fraction,
            $this->isMySql() => (in_array($type, ['dateTime', 'dateTimeTz'], true) ? 'DATETIME' : 'TIMESTAMP') . $fraction,
            default => 'TIMESTAMP',
        };
    }

    /**
     * Build a time SQL type.
     *
     * @param bool $timezone Whether the column is timezone-aware
     * @param int|null $precision The fractional seconds precision
     * @return string
     */
    private function timeType(bool $timezone, ?int $precision): string
    {
        $fraction = $precision !== null ? '(' . $precision . ')' : '';

        if ($this->isPostgres()) {
            return ($timezone ? 'TIMETZ' : 'TIME') . $fraction;
        }

        return $this->isMySql() ? 'TIME' . $fraction : 'TIME';
    }

    /**
     * Compile a database-specific integer type.
     *
     * @param string $type The logical integer type
     * @return string
     */
    private function integerType(string $type): string
    {
        // SQLite requires INTEGER PRIMARY KEY (not BIGINT) for AUTOINCREMENT to work
        if ($this->driver === 'sqlite' && in_array($type, ['id', 'bigIncrements'], true)) {
            return 'INTEGER';
        }

        return match ($type) {
            'tinyInteger' => $this->isPostgres() ? 'SMALLINT' : 'TINYINT',
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => $this->isMySql() ? 'MEDIUMINT' : 'INTEGER',
            'increments' => 'INTEGER',
            'smallIncrements' => 'SMALLINT',
            'mediumIncrements' => $this->isMySql() ? 'MEDIUMINT' : 'INTEGER',
            'bigIncrements', 'bigInteger', 'id', 'foreignId' => 'BIGINT',
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

        if ($this->isMySql()) {
            return 'ENUM(' . $this->quoteValueList($values) . ')';
        }

        return $this->isPostgres() ? 'VARCHAR(255)' : 'TEXT';
    }

    /**
     * Compile a set type (MySQL only).
     *
     * @param array<int, string> $values The set values
     * @return string
     */
    private function compileSetType(array $values): string
    {
        if (!$this->isMySql()) {
            throw new InvalidArgumentException('Set columns are only supported by MySQL.');
        }

        return 'SET(' . $this->quoteValueList($values) . ')';
    }

    /**
     * Quote a list of string values.
     *
     * @param array<int, string> $values The values
     * @return string
     */
    private function quoteValueList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => $this->quoteValue($value), $values));
    }

    /**
     * Build the CHECK expression that emulates an enum on PostgreSQL.
     *
     * @param array<string, mixed> $column The column data
     * @return string
     */
    private function enumCheckExpression(array $column): string
    {
        return $this->quoteIdentifier($column['name']) . ' IN (' . $this->quoteValueList($column['values']) . ')';
    }

    /**
     * Build the CHECK constraint name that emulates an enum on PostgreSQL.
     *
     * @param string $column The column name
     * @return string
     */
    private function enumCheckName(string $column): string
    {
        return $this->shortenName(Identifier::bareTable($this->table) . '_' . $column . '_enum');
    }

    /**
     * Check whether the driver is MySQL.
     *
     * @return bool
     */
    private function isMySql(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * Check whether the driver is PostgreSQL.
     *
     * @return bool
     */
    private function isPostgres(): bool
    {
        return $this->driver === 'pgsql';
    }

    /**
     * Quote an identifier for the current driver.
     *
     * @param string $identifier The identifier name
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        return Identifier::quote($this->driver, $identifier);
    }

    /**
     * Quote a table name for the current driver.
     *
     * @param string $table The table name, optionally qualified as "schema.table"
     * @return string
     */
    private function quoteTable(string $table): string
    {
        return Identifier::quoteTable($this->driver, $table);
    }

    /**
     * Assert a word is safe for SQL keywords/options.
     *
     * @param string $value The raw SQL word
     * @return string
     */
    private function assertSqlWord(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid schema option: $value");
        }

        return $value;
    }

    /**
     * Quote a collation name for PostgreSQL, which takes it as an identifier.
     *
     * @param string $collation The collation name, such as "C" or "en_US.utf8"
     * @return string
     */
    private function quoteCollation(string $collation): string
    {
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $collation)) {
            throw new InvalidArgumentException("Invalid schema option: $collation");
        }

        return '"' . $collation . '"';
    }

    /**
     * Assert an index algorithm is valid for the current driver.
     *
     * @param string $algorithm The index algorithm
     * @return string
     */
    private function assertIndexAlgorithm(string $algorithm): string
    {
        $allowed = match ($this->driver) {
            'mysql' => ['btree', 'hash'],
            'pgsql' => ['btree', 'hash', 'gin', 'gist', 'spgist', 'brin'],
            default => throw new InvalidArgumentException('Index algorithms are not supported for driver ' . $this->driver . '.'),
        };

        if (!in_array($algorithm, $allowed, true)) {
            throw new InvalidArgumentException("Index algorithm \"$algorithm\" is not supported by {$this->driver}.");
        }

        return $algorithm;
    }

    /**
     * Assert a foreign key action is one of the SQL-standard referential actions.
     *
     * @param string $action The referential action
     * @return string
     */
    private function assertForeignAction(string $action): string
    {
        $normalized = strtoupper(trim($action));

        if (!in_array($normalized, self::FOREIGN_ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid foreign key action: $action");
        }

        return $normalized;
    }

    /**
     * Assert a fractional seconds precision is valid.
     *
     * @param int|null $precision The precision
     * @return int|null
     */
    private function assertPrecision(?int $precision): ?int
    {
        if ($precision !== null && ($precision < 0 || $precision > 6)) {
            throw new InvalidArgumentException('Time precision must be between 0 and 6.');
        }

        return $precision;
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
            return $this->isPostgres() ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Schema values cannot be INF or NAN.');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return $this->quoteString(json_encode($value, JSON_THROW_ON_ERROR));
        }

        return $this->quoteString((string) $value);
    }

    /**
     * Quote a string literal for the current driver.
     *
     * @param string $value The string value
     * @return string
     */
    private function quoteString(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Schema string values cannot contain NUL bytes.');
        }

        if ($this->isMySql()) {
            $value = str_replace('\\', '\\\\', $value);
        }

        return "'" . str_replace("'", "''", $value) . "'";
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
        $table = Identifier::bareTable($this->table);

        if ($type === 'primary') {
            return $this->shortenName($table . '_pkey');
        }

        return $this->shortenName($table . '_' . implode('_', $columns) . '_' . strtolower($type));
    }

    /**
     * Build a deterministic foreign key name.
     *
     * @param array<int, string> $columns The local columns
     * @return string
     */
    private function foreignKeyName(array $columns): string
    {
        return $this->shortenName(Identifier::bareTable($this->table) . '_' . implode('_', $columns) . '_foreign');
    }

    /**
     * Shorten a generated name so it fits the driver identifier limit.
     *
     * @param string $name The generated name
     * @return string
     */
    private function shortenName(string $name): string
    {
        return Identifier::shorten($this->driver, $name);
    }
}
