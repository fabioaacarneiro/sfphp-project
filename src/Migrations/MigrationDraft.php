<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;

/**
 * Turns a migration's name and a list of fields into the file to write.
 *
 * The name is the instruction. `create_users` creates a table,
 * `add_phone_to_users` alters one, `drop_users_table` drops one — the same
 * words somebody would use to describe the change out loud, which is why they
 * are worth reading rather than asking for again as a separate argument.
 *
 *     ./sfphp make:migration create_users name:string email:string:unique timestamps
 *
 * A field is `name:type`, with numbers after it as the type's arguments and
 * words after it as modifiers:
 *
 *     surname:string:255            $table->string('surname', 255)
 *     email:string:unique           $table->string('email')->unique()
 *     price:decimal:8,2             $table->decimal('price', 8, 2)
 *     active:boolean:default=true   $table->boolean('active')->default(true)
 *
 * A bare word is a shorthand that takes no name of its own: `timestamps`,
 * `softDeletes`, `rememberToken`, `id`.
 *
 * Colons rather than parentheses on purpose: `surname:varchar(255)` is a syntax
 * error in bash unless it is quoted, and an argument that only works in quotes
 * is an argument people get wrong.
 *
 * **What this deliberately does not cover:** foreign keys with their own
 * `onDelete`, composite indexes, check constraints, generated columns. On a
 * command line they are longer and harder to read than the PHP they produce,
 * and the file is open in front of you. This exists to save typing, not to
 * become a second schema language — the Blueprint already is one, and better.
 */
final class MigrationDraft
{
    /** Column types that take no argument. */
    private const PLAIN_TYPES = [
        'id', 'increments', 'smallIncrements', 'mediumIncrements', 'bigIncrements',
        'foreignId', 'foreignUuid', 'foreignUlid',
        'tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger',
        'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger',
        'unsignedInteger', 'unsignedBigInteger',
        'text', 'mediumText', 'longText', 'binary', 'boolean',
        'date', 'time', 'timeTz', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz',
        'json', 'jsonb', 'uuid', 'ulid', 'ipAddress', 'macAddress', 'year',
        'float', 'double',
    ];

    /** Column types whose numeric argument is a length. */
    private const SIZED_TYPES = ['string', 'char'];

    /** Column types whose numeric arguments are precision and scale. */
    private const PRECISION_TYPES = ['decimal', 'unsignedDecimal'];

    /** Modifiers that take no value. */
    private const FLAGS = [
        'nullable', 'unique', 'index', 'unsigned', 'primary', 'autoIncrement',
        'useCurrent', 'useCurrentOnUpdate', 'constrained', 'first',
    ];

    /** Modifiers written as name=value. */
    private const VALUED = ['default', 'comment', 'after'];

    /** Fields that are a call of their own, with no column name. */
    private const SHORTHANDS = [
        'id', 'timestamps', 'timestampsTz', 'softDeletes', 'softDeletesTz', 'rememberToken',
    ];

    /** @var list<string> */
    private array $fields;

    private string $name;

    /**
     * Read a migration's intent.
     *
     * @param string $name The migration name, such as create_users
     * @param list<string> $fields The field definitions
     */
    public function __construct(string $name, array $fields = [])
    {
        $this->name = strtolower(trim($name));
        $this->fields = $fields;
    }

    /**
     * What this migration does: create, table, drop or plain.
     *
     * @return string The action
     */
    public function action(): string
    {
        if (preg_match('/^create_.+$/', $this->name) === 1) {
            return 'create';
        }

        if (preg_match('/^(?:add|update|change|alter|modify)_.+?_(?:to|from|in|on)_.+$/', $this->name) === 1) {
            return 'table';
        }

        if (preg_match('/^(?:drop|delete|remove)_.+?_(?:to|from|in|on)_.+$/', $this->name) === 1) {
            return 'table';
        }

        if (preg_match('/^(?:drop|delete|remove)_.+$/', $this->name) === 1) {
            return 'drop';
        }

        return 'plain';
    }

    /**
     * The table the name is talking about.
     *
     * @return string|null The table, or null when the name does not say
     */
    public function table(): ?string
    {
        $patterns = [
            '/^create_(.+?)(?:_table)?$/',
            '/^(?:add|update|change|alter|modify|drop|delete|remove)_.+?_(?:to|from|in|on)_(.+?)(?:_table)?$/',
            '/^(?:drop|delete|remove)_(.+?)(?:_table)?$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->name, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * The file to write.
     *
     * @return string The PHP
     * @throws InvalidArgumentException When a field cannot be read
     */
    public function body(): string
    {
        $action = $this->action();
        $table = $this->table();

        if ($action === 'plain' || $table === null) {
            return $this->plain();
        }

        if ($action === 'drop') {
            return $this->dropped($table);
        }

        return $this->schema($table, $action);
    }

    /**
     * A migration that creates or alters a table.
     *
     * @param string $table The table
     * @param string $action Either create or table
     * @return string The PHP
     */
    private function schema(string $table, string $action): string
    {
        $lines = [];

        /*
         * A table being created gets a key whether or not one was asked for.
         * Every other column is the caller's business, but a table with no
         * primary key is a mistake nobody makes on purpose.
         */
        if ($action === 'create' && !$this->mentions('id')) {
            $lines[] = '            $table->id();';
        }

        foreach ($this->fields as $field) {
            $lines[] = '            ' . $this->compile($field);
        }

        if ($lines === []) {
            $lines[] = "            // \$table->string('name');";
        }

        $columns = implode(PHP_EOL, $lines);
        $method = $action === 'create' ? 'create' : 'table';

        $up = "        \$schema->{$method}('{$table}', function (Blueprint \$table): void {"
            . PHP_EOL . $columns . PHP_EOL . '        });';

        $down = $action === 'create'
            ? "        \$schema->dropIfExists('{$table}');"
            : $this->reverse($table);

        return $this->file($up, $down);
    }

    /**
     * A migration that drops a table.
     *
     * @param string $table The table
     * @return string The PHP
     */
    private function dropped(string $table): string
    {
        $down = "        /*" . PHP_EOL
            . "         * Write what the table was, so this can be rolled back. Nothing" . PHP_EOL
            . "         * else knows: by the time anybody asks, the columns are gone." . PHP_EOL
            . "         */" . PHP_EOL
            . "        // \$schema->create('{$table}', function (Blueprint \$table): void {" . PHP_EOL
            . "        //     \$table->id();" . PHP_EOL
            . "        // });";

        return $this->file("        \$schema->dropIfExists('{$table}');", $down);
    }

    /**
     * Undo the columns an alter added.
     *
     * @param string $table The table
     * @return string The PHP
     */
    private function reverse(string $table): string
    {
        $names = [];

        foreach ($this->fields as $field) {
            $column = explode(':', $field)[0];

            if (in_array($column, self::SHORTHANDS, true)) {
                continue;
            }

            $names[] = "'" . $column . "'";
        }

        if ($names === []) {
            return "        // \$schema->table('{$table}', function (Blueprint \$table): void {});";
        }

        return "        \$schema->table('{$table}', function (Blueprint \$table): void {" . PHP_EOL
            . '            $table->dropColumn([' . implode(', ', $names) . ']);' . PHP_EOL
            . '        });';
    }

    /**
     * A migration whose name says nothing about a table.
     *
     * @return string The PHP
     */
    private function plain(): string
    {
        return $this->file(
            "        // \$schema->create('table', function (Blueprint \$table): void {});",
            "        // \$schema->dropIfExists('table');"
        );
    }

    /**
     * Wrap up and down in the migration file.
     *
     * @param string $up What up() does
     * @param string $down What down() does
     * @return string The PHP
     */
    private function file(string $up, string $down): string
    {
        $lines = [
            '<?php',
            '',
            'use SfphpProject\src\Migrations\Blueprint;',
            'use SfphpProject\src\Migrations\Migration;',
            'use SfphpProject\src\Migrations\Schema;',
            '',
            'return new class extends Migration',
            '{',
            '    public function up(Schema $schema): void',
            '    {',
            $up,
            '    }',
            '',
            '    public function down(Schema $schema): void',
            '    {',
            $down,
            '    }',
            '};',
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * Turn one field definition into a line of Blueprint.
     *
     * @param string $field The definition
     * @return string The PHP
     * @throws InvalidArgumentException When the field cannot be read
     */
    private function compile(string $field): string
    {
        $parts = explode(':', $field);
        $head = array_shift($parts);

        if ($parts === [] && in_array($head, self::SHORTHANDS, true)) {
            return '$table->' . $head . '();';
        }

        $type = array_shift($parts);

        if ($type === null || $type === '') {
            throw new InvalidArgumentException(sprintf(
                '"%s" has no type. Write it as name:type, such as %s:string.',
                $field,
                $head
            ));
        }

        $type = $this->resolveType($type, $field);

        // Pasted into PHP source, so it has to be a column name and nothing else.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $head) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a column name. Use letters, digits and underscores, starting with a letter.',
                $head
            ));
        }

        $arguments = [var_export($head, true)];
        $modifiers = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^[0-9]+(,[0-9]+)*$/', $part) === 1) {
                foreach (explode(',', $part) as $number) {
                    $arguments[] = $number;
                }

                continue;
            }

            $modifiers[] = $this->modifier($part, $field);
        }

        return '$table->' . $type . '(' . implode(', ', $arguments) . ')' . implode('', $modifiers) . ';';
    }

    /**
     * Check a type, and say what is available when it is wrong.
     *
     * @param string $type The type
     * @param string $field The whole definition, for the message
     * @return string The type as the Blueprint spells it
     * @throws InvalidArgumentException When the type is not one of ours
     */
    private function resolveType(string $type, string $field): string
    {
        $known = array_merge(self::PLAIN_TYPES, self::SIZED_TYPES, self::PRECISION_TYPES);

        foreach ($known as $candidate) {
            if (strtolower($candidate) === strtolower($type)) {
                return $candidate;
            }
        }

        /*
         * The SQL spelling is the one people reach for, and being told
         * "varchar is not a type" without being told what is would be useless
         * twice over.
         */
        $sql = [
            'varchar' => 'string', 'int' => 'integer', 'bool' => 'boolean',
            'datetime' => 'dateTime', 'bigint' => 'bigInteger', 'smallint' => 'smallInteger',
            'tinyint' => 'tinyInteger', 'blob' => 'binary', 'real' => 'float',
        ];

        if (isset($sql[strtolower($type)])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" uses the SQL name "%s". This takes the schema builder\'s names, so write %s.',
                $field,
                $type,
                $sql[strtolower($type)]
            ));
        }

        throw new InvalidArgumentException(sprintf(
            '"%s" is not a column type. The ones that exist are: %s.',
            $type,
            implode(', ', $known)
        ));
    }

    /**
     * Turn a modifier into the call that applies it.
     *
     * @param string $part The modifier
     * @param string $field The whole definition, for the message
     * @return string The PHP
     * @throws InvalidArgumentException When the modifier is not one of ours
     */
    private function modifier(string $part, string $field): string
    {
        if (str_contains($part, '=')) {
            [$name, $value] = explode('=', $part, 2);

            if (!in_array($name, self::VALUED, true)) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" in "%s" is not a modifier that takes a value. Those are: %s.',
                    $name,
                    $field,
                    implode(', ', self::VALUED)
                ));
            }

            return '->' . $name . '(' . $this->literal($value) . ')';
        }

        if (!in_array($part, self::FLAGS, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" in "%s" is not a modifier. The ones that exist are: %s, and %s=value.',
                $part,
                $field,
                implode(', ', self::FLAGS),
                implode('=value, ', self::VALUED)
            ));
        }

        return '->' . $part . '()';
    }

    /**
     * Write a value the way PHP would.
     *
     * @param string $value The value as typed
     * @return string The PHP literal
     */
    private function literal(string $value): string
    {
        $lower = strtolower($value);

        if ($lower === 'true' || $lower === 'false' || $lower === 'null') {
            return $lower;
        }

        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1) {
            return $value;
        }

        return var_export($value, true);
    }

    /**
     * Whether a field of this name was asked for.
     *
     * @param string $name The field name
     * @return bool True when it is in the list
     */
    private function mentions(string $name): bool
    {
        foreach ($this->fields as $field) {
            if (explode(':', $field)[0] === $name) {
                return true;
            }
        }

        return false;
    }
}
