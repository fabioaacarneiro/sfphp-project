<?php

/**
 * Schema integration tests against real MySQL and PostgreSQL servers.
 *
 * Point them at a throwaway database:
 *
 *   SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret
 *   SFPHP_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=sf' SFPHP_TEST_PGSQL_USER=postgres SFPHP_TEST_PGSQL_PASS=secret
 *   SFPHP_TEST_REDIS_HOST=127.0.0.1 SFPHP_TEST_REDIS_PORT=6379
 *
 * Every table the tests create starts with "sft_" and is the only thing they drop.
 * The run aborts when the database holds any other table (besides "migrations"),
 * and when the Redis instance is not empty.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/TestRunner.php';

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\MigrationLock;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;


/**
 * A job the queue integration tests push and pop.
 */
final class SftQueueJob extends \SfphpProject\src\Queue\Job
{
    public function __construct(public string $mark = '') {}

    public function handle(): void {}
}

/**
 * Run a query and return every row.
 *
 * @param PDO $pdo The connection
 * @param string $sql The SQL statement
 * @param array<int|string, mixed> $params The bound values
 * @return array<int, array<string, mixed>>
 */
function q(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * List the base tables of the current database/schema.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @return array<int, string>
 */
function dbTables(PDO $pdo, string $driver): array
{
    $current = $driver === 'mysql' ? 'DATABASE()' : 'current_schema()';

    return array_column(q(
        $pdo,
        "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = $current AND table_type = 'BASE TABLE'"
    ), 'name');
}

/**
 * Quote an identifier the way the driver expects.
 *
 * @param string $driver The driver name
 * @param string $name The identifier
 * @return string
 */
function qi(string $driver, string $name): string
{
    return $driver === 'mysql' ? "`$name`" : "\"$name\"";
}

/**
 * Drop every sft_ table and leftovers of earlier runs.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @return void
 */
function dbCleanup(PDO $pdo, string $driver): void
{
    $tables = dbTables($pdo, $driver);

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    foreach ($tables as $table) {
        if (str_starts_with($table, 'sft_')) {
            $pdo->exec('DROP TABLE IF EXISTS ' . qi($driver, $table) . ($driver === 'pgsql' ? ' CASCADE' : ''));
        }
    }

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        $pdo->exec('DROP SCHEMA IF EXISTS sft_schema CASCADE');
    }

    if (in_array('migrations', $tables, true)) {
        $pdo->exec("DELETE FROM migrations WHERE migration LIKE 'sft\\_%'");
    }
}

/**
 * Describe the columns of a table from the catalog.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @param string $table The table name, optionally "schema.table"
 * @return array<string, array<string, mixed>>
 */
function dbColumns(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        [$schema, $name] = str_contains($table, '.') ? explode('.', $table) : [null, $table];
        $rows = q(
            $pdo,
            'SELECT column_name AS name, column_type AS type, is_nullable = \'YES\' AS nullable, column_default AS dflt, '
            . 'column_comment AS comment, extra, collation_name AS collation, ordinal_position AS position '
            . 'FROM information_schema.columns WHERE table_schema = ' . ($schema === null ? 'DATABASE()' : '?')
            . ' AND table_name = ?',
            $schema === null ? [$name] : [$schema, $name]
        );
    } else {
        $rows = q(
            $pdo,
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, NOT a.attnotnull AS nullable, '
            . 'pg_get_expr(d.adbin, d.adrelid) AS dflt, col_description(a.attrelid, a.attnum) AS comment, '
            . 'a.attgenerated AS extra, (SELECT collname FROM pg_collation WHERE oid = a.attcollation) AS collation, '
            . 'a.attnum AS position '
            . 'FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
            . 'WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped',
            /*
             * Quoted, because to_regclass() folds an unquoted name to lower
             * case and the builder quotes identifiers when it creates a table —
             * so "sft_inc_smallIncrements" really is mixed case in the catalog.
             * Without the quotes this found nothing and reported the column
             * type as null, for the three tables whose names have a capital.
             */
            [implode('.', array_map(
                static fn (string $part): string => '"' . $part . '"',
                explode('.', $table)
            ))]
        );
    }

    $columns = [];
    foreach ($rows as $row) {
        $columns[$row['name']] = $row;
    }

    return $columns;
}

/**
 * Insert a row that relies on defaults only.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @param string $table The table name
 * @return void
 */
function dbInsertDefaults(PDO $pdo, string $driver, string $table): void
{
    $pdo->exec($driver === 'mysql'
        ? 'INSERT INTO ' . qi($driver, $table) . ' () VALUES ()'
        : 'INSERT INTO ' . qi($driver, $table) . ' DEFAULT VALUES');
}

/**
 * List the constraint types of a table.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @param string $table The table name
 * @return array<int, string>
 */
function dbConstraintTypes(PDO $pdo, string $driver, string $table): array
{
    $current = $driver === 'mysql' ? 'DATABASE()' : 'current_schema()';

    /*
     * PostgreSQL represents every NOT NULL column as a CHECK constraint, named
     * "<oid>_<relation>_<attribute>_not_null", and information_schema reports
     * it alongside the ones a migration actually declared. Asking for the
     * constraints of a table therefore answers "PRIMARY KEY, CHECK" for a table
     * whose only constraint is its key.
     *
     * Those rows are excluded here rather than expected, because they describe
     * a column's nullability and this helper is asked about constraints. The
     * name pattern is PostgreSQL's own and starts with a digit, which a
     * declared constraint in this suite never does.
     */
    $exclude = $driver === 'mysql'
        ? ''
        : " AND constraint_name !~ '^[0-9]+_[0-9]+_[0-9]+_not_null$'";

    return array_column(q(
        $pdo,
        "SELECT constraint_type AS type FROM information_schema.table_constraints"
        . " WHERE table_schema = $current AND table_name = ?" . $exclude,
        [$table]
    ), 'type');
}

/**
 * Count the rows of a table.
 *
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @param string $table The table name
 * @return int
 */
function dbCount(PDO $pdo, string $driver, string $table): int
{
    return (int) q($pdo, 'SELECT COUNT(*) AS n FROM ' . qi($driver, $table))[0]['n'];
}

/**
 * Assert a statement fails with a database error.
 *
 * @param TestRunner $tests The runner
 * @param callable $callback The statement to run
 * @return void
 */
function assertDbError(TestRunner $tests, callable $callback): void
{
    $tests->assertThrows($callback, PDOException::class);
}

/**
 * Register every schema test for one driver.
 *
 * @param TestRunner $tests The runner
 * @param PDO $pdo The connection
 * @param string $driver The driver name
 * @param callable(): PDO $connect Opens a second connection to the same server
 * @return void
 */
function registerSchemaTests(TestRunner $tests, PDO $pdo, string $driver, callable $connect): void
{
    $schema = new Schema($pdo);
    $mysql = $driver === 'mysql';
    $test = static function (string $name, callable $body) use ($tests, $pdo, $driver): void {
        $tests->run("[$driver] $name", static function () use ($body, $pdo, $driver): void {
            dbCleanup($pdo, $driver);
            $body();
        });
    };

    $test('every column type maps to the expected native type', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        // name => [definition, MySQL type, PostgreSQL type]
        $types = [
            'tinyInteger' => [fn (Blueprint $t) => $t->tinyInteger('c_tinyInteger'), 'tinyint', 'smallint'],
            'smallInteger' => [fn (Blueprint $t) => $t->smallInteger('c_smallInteger'), 'smallint', 'smallint'],
            'mediumInteger' => [fn (Blueprint $t) => $t->mediumInteger('c_mediumInteger'), 'mediumint', 'integer'],
            'integer' => [fn (Blueprint $t) => $t->integer('c_integer'), 'int', 'integer'],
            'bigInteger' => [fn (Blueprint $t) => $t->bigInteger('c_bigInteger'), 'bigint', 'bigint'],
            'unsignedInteger' => [fn (Blueprint $t) => $t->unsignedInteger('c_unsignedInteger'), 'int unsigned', 'integer'],
            'foreignId' => [fn (Blueprint $t) => $t->foreignId('c_foreignId'), 'bigint unsigned', 'bigint'],
            'string' => [fn (Blueprint $t) => $t->string('c_string', 50), 'varchar(50)', 'character varying(50)'],
            'char' => [fn (Blueprint $t) => $t->char('c_char', 4), 'char(4)', 'character(4)'],
            'text' => [fn (Blueprint $t) => $t->text('c_text'), 'text', 'text'],
            'mediumText' => [fn (Blueprint $t) => $t->mediumText('c_mediumText'), 'mediumtext', 'text'],
            'longText' => [fn (Blueprint $t) => $t->longText('c_longText'), 'longtext', 'text'],
            'binary' => [fn (Blueprint $t) => $t->binary('c_binary'), 'blob', 'bytea'],
            'boolean' => [fn (Blueprint $t) => $t->boolean('c_boolean'), 'tinyint(1)', 'boolean'],
            'date' => [fn (Blueprint $t) => $t->date('c_date'), 'date', 'date'],
            'time' => [fn (Blueprint $t) => $t->time('c_time', 3), 'time(3)', 'time(3) without time zone'],
            'timeTz' => [fn (Blueprint $t) => $t->timeTz('c_timeTz'), 'time', 'time with time zone'],
            'dateTime' => [fn (Blueprint $t) => $t->dateTime('c_dateTime'), 'datetime', 'timestamp without time zone'],
            'dateTimeTz' => [fn (Blueprint $t) => $t->dateTimeTz('c_dateTimeTz', 3), 'datetime(3)', 'timestamp(3) with time zone'],
            'timestamp' => [fn (Blueprint $t) => $t->timestamp('c_timestamp'), 'timestamp', 'timestamp without time zone'],
            'timestampTz' => [fn (Blueprint $t) => $t->timestampTz('c_timestampTz'), 'timestamp', 'timestamp with time zone'],
            'decimal' => [fn (Blueprint $t) => $t->decimal('c_decimal', 8, 3), 'decimal(8,3)', 'numeric(8,3)'],
            'unsignedDecimal' => [fn (Blueprint $t) => $t->unsignedDecimal('c_unsignedDecimal'), 'decimal(10,2) unsigned', 'numeric(10,2)'],
            'float' => [fn (Blueprint $t) => $t->float('c_float'), 'float', 'real'],
            'double' => [fn (Blueprint $t) => $t->double('c_double'), 'double', 'double precision'],
            'json' => [fn (Blueprint $t) => $t->json('c_json'), 'json', 'json'],
            'jsonb' => [fn (Blueprint $t) => $t->jsonb('c_jsonb'), 'json', 'jsonb'],
            'uuid' => [fn (Blueprint $t) => $t->uuid('c_uuid'), 'char(36)', 'uuid'],
            'ulid' => [fn (Blueprint $t) => $t->ulid('c_ulid'), 'char(26)', 'character(26)'],
            'ipAddress' => [fn (Blueprint $t) => $t->ipAddress('c_ipAddress'), 'varchar(45)', 'inet'],
            'macAddress' => [fn (Blueprint $t) => $t->macAddress('c_macAddress'), 'varchar(17)', 'macaddr'],
            'year' => [fn (Blueprint $t) => $t->year('c_year'), 'year', 'smallint'],
            'enum' => [fn (Blueprint $t) => $t->enum('c_enum', ['x', 'y']), "enum('x','y')", 'character varying(255)'],
            'rawColumn' => [
                fn (Blueprint $t) => $t->rawColumn('c_rawColumn', $mysql ? 'POINT' : 'INTEGER[]'),
                'point',
                'integer[]',
            ],
        ];

        $schema->create('sft_types', function (Blueprint $table) use ($types): void {
            $table->id();
            foreach ($types as [$define]) {
                $define($table)->nullable();
            }
        });

        $columns = dbColumns($pdo, $driver, 'sft_types');
        foreach ($types as $name => [, $mysqlType, $pgType]) {
            $tests->assertSame([$name, $mysql ? $mysqlType : $pgType], [$name, $columns["c_$name"]['type'] ?? null]);
            $tests->assertSame([$name, true], [$name, (bool) $columns["c_$name"]['nullable']]);
        }

        if ($mysql) {
            $schema->create('sft_sets', function (Blueprint $table): void {
                $table->set('flags', ['a', 'b'])->nullable();
            });
            $tests->assertSame("set('a','b')", dbColumns($pdo, $driver, 'sft_sets')['flags']['type']);
        } else {
            $tests->assertThrows(function () use ($schema): void {
                $schema->create('sft_sets', function (Blueprint $table): void {
                    $table->set('flags', ['a']);
                });
            }, InvalidArgumentException::class);
        }
    });

    $test('auto-incrementing keys generate ids', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $expected = [
            'id' => ['bigint unsigned', 'bigint'],
            'increments' => ['int unsigned', 'integer'],
            'smallIncrements' => ['smallint unsigned', 'smallint'],
            'mediumIncrements' => ['mediumint unsigned', 'integer'],
            'bigIncrements' => ['bigint unsigned', 'bigint'],
        ];

        foreach ($expected as $method => [$mysqlType, $pgType]) {
            $table = "sft_inc_$method";
            $schema->create($table, function (Blueprint $blueprint) use ($method): void {
                $blueprint->$method('id');
            });

            dbInsertDefaults($pdo, $driver, $table);
            dbInsertDefaults($pdo, $driver, $table);

            $tests->assertSame([$method, $mysql ? $mysqlType : $pgType], [$method, dbColumns($pdo, $driver, $table)['id']['type']]);
            $tests->assertSame([$method, [1, 2]], [$method, array_map('intval', array_column(q($pdo, 'SELECT id FROM ' . qi($driver, $table) . ' ORDER BY id'), 'id'))]);
            $tests->assertSame([$method, ['PRIMARY KEY']], [$method, dbConstraintTypes($pdo, $driver, $table)]);
        }
    });

    $test('defaults round-trip through the database', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_defaults', function (Blueprint $table): void {
            $table->boolean('yes')->default(true);
            $table->boolean('no')->default(false);
            $table->integer('n')->default(7);
            $table->decimal('price')->default(1.5);
            $table->string('tricky')->default('a\\b\'c "d" %');
            $table->text('body')->default('hello');
            $table->json('meta')->default(['a' => 1]);
            $table->enum('status', ['x', 'y'])->default('y');
            $table->timestamp('created_at')->useCurrent();
            $table->string('maybe')->nullable();
        });

        dbInsertDefaults($pdo, $driver, 'sft_defaults');
        $row = q($pdo, 'SELECT * FROM ' . qi($driver, 'sft_defaults'))[0];

        $tests->assertSame(true, (bool) $row['yes']);
        $tests->assertSame(false, (bool) $row['no']);
        $tests->assertSame(7, (int) $row['n']);
        $tests->assertSame(1.5, (float) $row['price']);
        $tests->assertSame('a\\b\'c "d" %', $row['tricky']);
        $tests->assertSame('hello', $row['body']);
        $tests->assertSame(['a' => 1], json_decode($row['meta'], true));
        $tests->assertSame('y', $row['status']);
        $tests->assertTrue(strtotime($row['created_at']) > strtotime('2020-01-01'));
        $tests->assertSame(null, $row['maybe']);
    });

    $test('enum columns reject values outside the list and can be changed', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_enums', function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['a', 'b'])->default('a');
        });
        $insert = fn (string $value) => $pdo->exec('INSERT INTO ' . qi($driver, 'sft_enums') . " (status) VALUES ('$value')");

        $insert('b');
        assertDbError($tests, fn () => $insert('c'));

        $schema->table('sft_enums', function (Blueprint $table): void {
            $table->enum('status', ['a', 'b', 'c'])->change()->default('a');
        });
        $insert('c');
        assertDbError($tests, fn () => $insert('d'));

        $schema->table('sft_enums', function (Blueprint $table): void {
            $table->string('status', 20)->change()->default('a');
        });
        $insert('anything');

        /*
         * Three rows: 'b' under the original list, 'c' once the list was
         * widened, and 'anything' once the column stopped being an enum. The
         * two inserts in between were refused, which is what the assertions
         * above check. This asserted four, and had never been green to say so.
         */
        $tests->assertSame(3, dbCount($pdo, $driver, 'sft_enums'));
    });

    $test('indexes, unique keys, algorithms and full-text', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $schema->create('sft_idx', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('a');
            $table->string('b');
            $table->text('body');
            $table->index(['a', 'b']);
            $table->index('a', 'sft_idx_a_hash')->algorithm('hash');
            $table->fullText('body');
        });

        foreach (['sft_idx_email_unique', 'sft_idx_a_b_index', 'sft_idx_a_hash', 'sft_idx_body_fulltext'] as $index) {
            $tests->assertSame([$index, true], [$index, $schema->hasIndex('sft_idx', $index)]);
        }

        $insert = fn (string $email) => $pdo->exec('INSERT INTO ' . qi($driver, 'sft_idx') . " (email, a, b, body) VALUES ('$email', 'a', 'b', 'text')");
        $insert('one@example.com');
        assertDbError($tests, fn () => $insert('one@example.com'));

        $schema->table('sft_idx', function (Blueprint $table): void {
            $table->renameIndex('sft_idx_a_b_index', 'sft_idx_renamed');
            $table->dropUnique('email');
            $table->dropIndex('a', 'sft_idx_a_hash');
            $table->dropFullText('body');
        });

        $tests->assertSame(true, $schema->hasIndex('sft_idx', 'sft_idx_renamed'));
        $tests->assertSame(false, $schema->hasIndex('sft_idx', 'sft_idx_a_b_index'));
        $tests->assertSame(false, $schema->hasIndex('sft_idx', 'sft_idx_email_unique'));
        $tests->assertSame(false, $schema->hasIndex('sft_idx', 'sft_idx_a_hash'));
        $tests->assertSame(false, $schema->hasIndex('sft_idx', 'sft_idx_body_fulltext'));
        $insert('one@example.com');
        $tests->assertSame(2, dbCount($pdo, $driver, 'sft_idx'));

        if ($mysql) {
            $tests->assertThrows(function () use ($schema): void {
                $schema->table('sft_idx', function (Blueprint $table): void {
                    $table->index('a')->where('a IS NOT NULL');
                });
            }, InvalidArgumentException::class);
        }
    });

    if (!$mysql) {
        $test('partial unique indexes only constrain matching rows', function () use ($tests, $pdo, $driver, $schema): void {
            $schema->create('sft_partial', function (Blueprint $table): void {
                $table->id();
                $table->string('code');
                $table->timestamp('deleted_at')->nullable();
                $table->unique(['code'], 'sft_partial_active_code')->where('deleted_at IS NULL');
            });
            $insert = fn (string $deleted) => $pdo->exec("INSERT INTO sft_partial (code, deleted_at) VALUES ('x', $deleted)");

            $insert('NULL');
            $insert("'2020-01-01'");
            $insert("'2021-01-01'");
            assertDbError($tests, fn () => $insert('NULL'));
        });
    }

    $test('foreign keys enforce and apply their referential actions', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_parents', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('sft_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('sft_parents')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('sft_parents')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('guard_id')->nullable()->constrained('sft_parents')->restrictOnDelete();
        });

        $parents = qi($driver, 'sft_parents');
        $children = qi($driver, 'sft_children');
        $pdo->exec("INSERT INTO $parents (id) VALUES (1), (2), (3)");
        $pdo->exec("INSERT INTO $children (parent_id, owner_id, guard_id) VALUES (1, 2, 3)");
        assertDbError($tests, fn () => $pdo->exec("INSERT INTO $children (parent_id) VALUES (99)"));
        assertDbError($tests, fn () => $pdo->exec("DELETE FROM $parents WHERE id = 3"));

        $pdo->exec("DELETE FROM $parents WHERE id = 2");
        $tests->assertSame(null, q($pdo, "SELECT owner_id FROM $children")[0]['owner_id']);

        $pdo->exec("UPDATE $children SET guard_id = NULL");
        $pdo->exec("DELETE FROM $parents WHERE id = 1");
        $tests->assertSame(0, dbCount($pdo, $driver, 'sft_children'));

        $schema->table('sft_children', function (Blueprint $table): void {
            $table->dropForeign('parent_id');
        });
        $pdo->exec("INSERT INTO $children (parent_id) VALUES (99)");
        $tests->assertSame(1, dbCount($pdo, $driver, 'sft_children'));
    });

    $test('foreign key options unsupported by the driver fail loudly', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $schema->create('sft_p', function (Blueprint $table): void {
            $table->id();
        });

        if ($mysql) {
            $tests->assertThrows(function () use ($schema): void {
                $schema->create('sft_c', function (Blueprint $table): void {
                    $table->foreignId('p')->constrained('sft_p')->onDelete('SET DEFAULT');
                });
            }, InvalidArgumentException::class);

            return;
        }

        $schema->create('sft_c', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('p')->constrained('sft_p')->deferrable(true);
        });
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO sft_c (p) VALUES (5)');
        $pdo->exec('INSERT INTO sft_p (id) VALUES (5)');
        $pdo->commit();
        $tests->assertSame(1, dbCount($pdo, $driver, 'sft_c'));
    });

    $test('altering a table keeps existing data', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $schema->create('sft_alter', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 20);
            $table->string('qty_text', 10);
            $table->integer('qty');
            $table->string('code', 10);
            $table->string('gone')->nullable();
        });
        $table = qi($driver, 'sft_alter');
        $pdo->exec("INSERT INTO $table (name, qty_text, qty, code) VALUES ('first', '123', 5, 'abc')");

        $schema->table('sft_alter', function (Blueprint $blueprint): void {
            $blueprint->string('name', 100)->change()->nullable()->default('anon')->comment('Display name');
            $blueprint->integer('qty_text')->change();
            $blueprint->bigInteger('qty')->change();
            $blueprint->renameColumn('code', 'sku');
            $blueprint->dropColumn('gone');
            $blueprint->boolean('active')->default(true);
            $blueprint->string('sku', 10)->change()->unique();
        });

        $columns = dbColumns($pdo, $driver, 'sft_alter');
        $tests->assertSame($mysql ? 'varchar(100)' : 'character varying(100)', $columns['name']['type']);
        $tests->assertSame(true, (bool) $columns['name']['nullable']);
        $tests->assertSame('Display name', $columns['name']['comment']);
        $tests->assertSame($mysql ? 'int' : 'integer', $columns['qty_text']['type']);
        $tests->assertSame('bigint', $columns['qty']['type']);
        $tests->assertSame(false, isset($columns['gone']) || isset($columns['code']));
        $tests->assertSame(true, isset($columns['sku'], $columns['active']));

        $row = q($pdo, "SELECT * FROM $table")[0];
        $tests->assertSame('first', $row['name']);
        $tests->assertSame(123, (int) $row['qty_text']);
        $tests->assertSame(5, (int) $row['qty']);
        $tests->assertSame('abc', $row['sku']);
        $tests->assertSame(true, (bool) $row['active']);

        $pdo->exec("INSERT INTO $table (qty_text, qty, sku) VALUES (1, 1, 'z')");
        $tests->assertSame('anon', q($pdo, "SELECT name FROM $table WHERE sku = 'z'")[0]['name']);
        assertDbError($tests, fn () => $pdo->exec("INSERT INTO $table (qty_text, qty, sku) VALUES (1, 1, 'z')"));

        $schema->rename('sft_alter', 'sft_alter_renamed');
        $tests->assertSame([false, true], [$schema->hasTable('sft_alter'), $schema->hasTable('sft_alter_renamed')]);
    });

    $test('check constraints can be added and dropped', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_checks', function (Blueprint $table): void {
            $table->integer('qty');
        });
        $schema->table('sft_checks', function (Blueprint $table): void {
            $table->check('qty >= 0', 'sft_checks_positive');
        });
        assertDbError($tests, fn () => $pdo->exec('INSERT INTO sft_checks (qty) VALUES (-1)'));

        $schema->table('sft_checks', function (Blueprint $table): void {
            $table->dropCheck('sft_checks_positive');
        });
        $pdo->exec('INSERT INTO sft_checks (qty) VALUES (-1)');
        $tests->assertSame(1, dbCount($pdo, $driver, 'sft_checks'));
    });

    $test('ON UPDATE timestamps refresh on update and stop when the column is changed', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_touch', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('a');
            $table->timestamp('touched', 6)->default('2000-01-01 00:00:00')->useCurrentOnUpdate();
        });
        $table = qi($driver, 'sft_touch');
        $touched = fn () => strtotime(q($pdo, "SELECT touched FROM $table")[0]['touched']);

        dbInsertDefaults($pdo, $driver, 'sft_touch');
        $tests->assertSame(strtotime('2000-01-01 00:00:00'), $touched());
        $pdo->exec("UPDATE $table SET name = 'b'");
        $tests->assertTrue($touched() > strtotime('2020-01-01'));

        $schema->table('sft_touch', function (Blueprint $blueprint): void {
            $blueprint->timestamp('touched', 6)->change()->default('2000-01-01 00:00:00');
        });
        $pdo->exec("UPDATE $table SET touched = '2001-01-01 00:00:00'");
        $pdo->exec("UPDATE $table SET name = 'c'");
        $tests->assertSame(strtotime('2001-01-01 00:00:00'), $touched());

        $schema->table('sft_touch', function (Blueprint $blueprint): void {
            $blueprint->timestamp('touched', 6)->change()->default('2000-01-01 00:00:00')->useCurrentOnUpdate();
        });
        $pdo->exec("UPDATE $table SET name = 'd'");
        $tests->assertTrue($touched() > strtotime('2020-01-01'));
    });

    $test('generated columns are computed by the database', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $schema->create('sft_generated', function (Blueprint $table) use ($mysql): void {
            $table->integer('a');
            $table->integer('b');
            $table->integer('total')->storedAs('a + b');
            if ($mysql) {
                $table->integer('half')->virtualAs('a / 2');
            }
        });
        $pdo->exec('INSERT INTO sft_generated (a, b) VALUES (4, 6)');
        $row = q($pdo, 'SELECT * FROM sft_generated')[0];

        $tests->assertSame(10, (int) $row['total']);
        if ($mysql) {
            $tests->assertSame(2, (int) $row['half']);
        } else {
            $tests->assertThrows(function () use ($schema): void {
                $schema->create('sft_virtual', function (Blueprint $table): void {
                    $table->integer('a')->virtualAs('1');
                });
            }, InvalidArgumentException::class);
        }
    });

    $test('comments, collations and table options are stored', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $schema->create('sft_meta', function (Blueprint $table) use ($mysql): void {
            $table->id();
            $table->string('slug')->comment("Owner's slug")->collation($mysql ? 'utf8mb4_bin' : 'C');
            $table->tableComment('About metadata');
            if ($mysql) {
                $table->engine('InnoDB')->tableCollation('utf8mb4_bin');
            }
        });
        $columns = dbColumns($pdo, $driver, 'sft_meta');

        $tests->assertSame("Owner's slug", $columns['slug']['comment']);
        $tests->assertSame($mysql ? 'utf8mb4_bin' : 'C', $columns['slug']['collation']);

        if ($mysql) {
            $table = q($pdo, "SELECT table_comment AS comment, engine, table_collation AS collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sft_meta'")[0];
            $tests->assertSame(['About metadata', 'InnoDB', 'utf8mb4_bin'], [$table['comment'], $table['ENGINE'] ?? $table['engine'], $table['collation']]);
        } else {
            $tests->assertSame('About metadata', q($pdo, "SELECT obj_description(to_regclass('sft_meta'), 'pg_class') AS comment")[0]['comment']);
        }
    });

    if ($mysql) {
        $test('MySQL column positions and unsigned integers', function () use ($tests, $pdo, $driver, $schema): void {
            $schema->create('sft_position', function (Blueprint $table): void {
                $table->integer('a');
                $table->integer('c');
                $table->unsignedInteger('n')->nullable();
            });
            $schema->table('sft_position', function (Blueprint $table): void {
                $table->integer('b')->after('a');
                $table->integer('z')->first();
            });

            $columns = dbColumns($pdo, $driver, 'sft_position');
            uasort($columns, fn (array $left, array $right): int => $left['position'] <=> $right['position']);
            $tests->assertSame(['z', 'a', 'b', 'c', 'n'], array_keys($columns));
            assertDbError($tests, fn () => $pdo->exec('INSERT INTO sft_position (z, a, b, c, n) VALUES (1, 1, 1, 1, -1)'));
        });
    }

    $test('timestamps, soft deletes, tokens and morph helpers work end to end', function () use ($tests, $pdo, $driver, $schema): void {
        foreach (['sft_notes', 'sft_tags'] as $name) {
            $schema->create($name, function (Blueprint $table): void {
                $table->id();
                $table->uuidMorphs('owner');
                $table->nullableMorphs('subject');
                $table->ulidMorphs('author');
                $table->rememberToken();
                $table->softDeletes();
                $table->timestamps(3);
            });
        }

        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $pdo->exec('INSERT INTO sft_notes (owner_id, owner_type, author_id, author_type) VALUES (\'' . $uuid . '\', \'App\\\\User\', \'01ARZ3NDEKTSV4RRFFQ69G5FAV\', \'x\')');
        $row = q($pdo, 'SELECT * FROM sft_notes')[0];
        $tests->assertSame($uuid, $row['owner_id']);
        $tests->assertSame(null, $row['subject_id']);
        $tests->assertTrue(strtotime($row['created_at']) > strtotime('2020-01-01'));
        $tests->assertSame(true, $schema->hasIndex('sft_notes', 'sft_notes_owner_id_owner_type_index'));
        $tests->assertSame(true, $schema->hasIndex('sft_tags', 'sft_tags_owner_id_owner_type_index'));

        $schema->table('sft_notes', function (Blueprint $table): void {
            $table->dropTimestamps();
            $table->dropSoftDeletes();
            $table->dropRememberToken();
            $table->dropMorphs('owner');
        });
        $columns = array_keys(dbColumns($pdo, $driver, 'sft_notes'));
        sort($columns);
        $tests->assertSame(['author_id', 'author_type', 'id', 'subject_id', 'subject_type'], $columns);
    });

    $test('primary keys can be added, made composite and dropped', function () use ($tests, $pdo, $driver, $schema): void {
        $schema->create('sft_pk', function (Blueprint $table): void {
            $table->integer('a');
            $table->integer('b');
            $table->primary(['a', 'b']);
        });
        $tests->assertSame(['PRIMARY KEY'], dbConstraintTypes($pdo, $driver, 'sft_pk'));
        $pdo->exec('INSERT INTO sft_pk (a, b) VALUES (1, 1), (1, 2)');
        assertDbError($tests, fn () => $pdo->exec('INSERT INTO sft_pk (a, b) VALUES (1, 1)'));

        $schema->table('sft_pk', function (Blueprint $table): void {
            $table->dropPrimary();
        });
        $tests->assertSame([], dbConstraintTypes($pdo, $driver, 'sft_pk'));
        $pdo->exec('INSERT INTO sft_pk (a, b) VALUES (1, 1)');
    });

    $test('long generated names are shortened and still droppable by column', function () use ($tests, $pdo, $driver, $schema): void {
        $table = 'sft_' . str_repeat('t', 40);
        $first = 'a_rather_long_column_name_for_the_first_key';
        $second = 'a_rather_long_column_name_for_the_second_key';

        $schema->create('sft_long_parent', function (Blueprint $blueprint): void {
            $blueprint->id();
        });
        $schema->create($table, function (Blueprint $blueprint) use ($first, $second): void {
            $blueprint->id();
            $blueprint->foreignId($first)->constrained('sft_long_parent');
            $blueprint->string($second);
            $blueprint->index([$first, $second]);
            $blueprint->unique([$second]);
        });
        $schema->table($table, function (Blueprint $blueprint) use ($first, $second): void {
            $blueprint->dropForeign($first);
            $blueprint->dropIndex([$first, $second]);
            $blueprint->dropUnique([$second]);
        });

        $tests->assertSame(true, $schema->hasTable($table));
    });

    $test('schema helpers report tables, columns and indexes and qualify names', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $qualifier = $mysql ? q($pdo, 'SELECT DATABASE() AS name')[0]['name'] : 'sft_schema';
        if (!$mysql) {
            $pdo->exec('CREATE SCHEMA IF NOT EXISTS sft_schema');
        }
        $qualified = $qualifier . '.sft_qualified';

        $tests->assertSame(false, $schema->hasTable($qualified));
        $schema->create('sft_parent_q', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create($qualified, function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->foreignId('parent_id')->constrained('sft_parent_q');
        });
        $tests->assertSame([true, true, false], [
            $schema->hasTable($qualified),
            $schema->hasColumn($qualified, 'email'),
            $schema->hasColumn($qualified, 'missing'),
        ]);
        $tests->assertSame(true, $schema->hasIndex($qualified, 'sft_qualified_email_unique'));

        $schema->table($qualified, function (Blueprint $table): void {
            $table->renameIndex('sft_qualified_email_unique', 'sft_qualified_mail');
        });
        $tests->assertSame([true, false], [
            $schema->hasIndex($qualified, 'sft_qualified_mail'),
            $schema->hasIndex($qualified, 'sft_qualified_email_unique'),
        ]);

        $schema->table($qualified, function (Blueprint $table): void {
            $table->dropIndex('email', 'sft_qualified_mail');
        });
        $tests->assertSame(false, $schema->hasIndex($qualified, 'sft_qualified_mail'));

        $schema->drop($qualified);
        $tests->assertSame(false, $schema->hasTable($qualified));
        $tests->assertThrows(fn () => $schema->drop($qualified), PDOException::class);
        $schema->dropIfExists($qualified);
    });


    $test('the database queue survives a real server', function () use ($tests, $pdo, $driver, $schema): void {
        /*
         * The queue had no integration test at all, and it did not work on
         * either server: reserved_at was a timestamp column receiving time(),
         * and insert() asked PostgreSQL for a sequence value on a table whose
         * key the application supplies. Both failed on the first pop.
         */
        $queue = new \SfphpProject\src\Queue\DatabaseDriver(900, 'sft_jobs', 'sft_failed_jobs', $pdo);
        $queue->flush();

        $id = $queue->push(new SftQueueJob('primeiro'));
        $tests->assertSame(true, is_string($id) && $id !== '');
        $tests->assertSame(1, $queue->size());

        $job = $queue->pop();
        $tests->assertSame(true, $job instanceof SftQueueJob);
        $tests->assertSame('primeiro', $job->mark);

        /*
         * The id has to survive the round trip. Job's own properties used to be
         * serialised with the payload, so unserialising put back the null id
         * captured at push time and every later call addressed nothing.
         */
        $tests->assertSame($id, $job->getId());

        // Reserved, so it is not handed out twice.
        $tests->assertSame(null, $queue->pop());

        $queue->release($job, 0);
        $tests->assertSame(true, $queue->pop() instanceof SftQueueJob);

        $queue->delete($job);
        $tests->assertSame(0, $queue->size());

        // A failure is recorded and leaves the main queue.
        $queue->push(new SftQueueJob('falho'));
        $queue->failed($queue->pop(), new RuntimeException('deu ruim'));
        $tests->assertSame(1, count($queue->failedJobs()));
        $tests->assertSame('deu ruim', $queue->failedJobs()[0]['exception']);
        $tests->assertSame(0, $queue->size());

        // A delayed job waits.
        $queue->flush();
        $queue->push(new SftQueueJob('futuro'), 3600);
        $tests->assertSame(null, $queue->pop());

        $queue->flush();
        $schema->dropIfExists('sft_jobs');
        $schema->dropIfExists('sft_failed_jobs');
    });

    $test('two workers never claim the same job', function () use ($tests, $pdo, $driver, $schema): void {
        /*
         * The reason this matters: selecting a row and then updating it is not
         * a reservation. Two workers read the same row, both write their own
         * reserved_at, and both run the job — which for a queue is not a
         * slowdown but a duplicated side effect. It only happens with more than
         * one worker, which is when nobody is watching.
         *
         * Simulated here by interleaving two drivers against the same table,
         * which is what two workers racing looks like from the database's side.
         */
        $first = new \SfphpProject\src\Queue\DatabaseDriver(900, 'sft_jobs', 'sft_failed_jobs', $pdo);
        $second = new \SfphpProject\src\Queue\DatabaseDriver(900, 'sft_jobs', 'sft_failed_jobs', $pdo);
        $first->flush();

        for ($i = 0; $i < 5; $i++) {
            $first->push(new SftQueueJob('job-' . $i));
        }

        $claimed = [];

        while (($job = $first->pop()) !== null) {
            $claimed[] = $job->getId();
            $other = $second->pop();

            if ($other !== null) {
                $claimed[] = $other->getId();
            }
        }

        $tests->assertSame(5, count($claimed));
        $tests->assertSame(5, count(array_unique($claimed)));

        $first->flush();
        $schema->dropIfExists('sft_jobs');
        $schema->dropIfExists('sft_failed_jobs');
    });

    $test('a job reserved by a worker that died comes back', function () use ($tests, $pdo, $driver, $schema): void {
        /*
         * A worker killed between reserving a job and finishing it leaves
         * reserved_at set with nobody working on it. With one worker that is
         * rare; with instances being deployed, restarted and scaled it is
         * routine, and the job vanished silently — the worst way for work to be
         * lost.
         */
        $queue = new \SfphpProject\src\Queue\DatabaseDriver(1, 'sft_jobs', 'sft_failed_jobs', $pdo);
        $queue->flush();
        $queue->push(new SftQueueJob('abandonado'));

        $tests->assertSame(true, $queue->pop() instanceof SftQueueJob);
        $tests->assertSame(null, $queue->pop());

        // The reservation window passes and the job is available again.
        sleep(2);
        $tests->assertSame(true, $queue->pop() instanceof SftQueueJob);

        $queue->flush();
        $schema->dropIfExists('sft_jobs');
        $schema->dropIfExists('sft_failed_jobs');
    });

    $test('the runner applies, reports and rolls back migrations', function () use ($tests, $pdo, $driver, $schema, $mysql): void {
        $directory = sys_get_temp_dir() . '/sfphp-db-' . bin2hex(random_bytes(4));
        mkdir($directory);

        $write = static function (string $name, string $up, string $down = '') use ($directory): void {
            file_put_contents("$directory/$name.php", <<<PHP
<?php

use SfphpProject\\src\\Migrations\\Blueprint;
use SfphpProject\\src\\Migrations\\Migration;
use SfphpProject\\src\\Migrations\\Schema;

return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        $up
    }

    public function down(Schema \$schema): void
    {
        $down
    }
};
PHP);
        };

        $write(
            'sft_20260101_000001_create_ok',
            "\$schema->create('sft_rt_ok', function (Blueprint \$table): void { \$table->id(); \$table->timestamps(); });",
            "\$schema->dropIfExists('sft_rt_ok');"
        );
        $write(
            'sft_20260101_000002_fails_halfway',
            "\$schema->create('sft_rt_half', function (Blueprint \$table): void { \$table->id(); }); throw new RuntimeException('boom');"
        );

        try {
            $runner = new MigrationRunner($pdo, $directory);
            $tests->assertSame(['sft_20260101_000001_create_ok.php'], $runner->migrate(1));
            $tests->assertSame(true, $schema->hasTable('sft_rt_ok'));

            $tests->assertThrows(fn () => $runner->migrate(), RuntimeException::class);
            $tests->assertSame(
                [['sft_20260101_000001_create_ok.php', 'applied'], ['sft_20260101_000002_fails_halfway.php', 'pending']],
                array_map(fn (array $row): array => [$row['migration'], $row['status']], $runner->status())
            );

            // PostgreSQL rolls the half-applied DDL back; MySQL cannot (implicit commits).
            $tests->assertSame(!$mysql ? false : true, $schema->hasTable('sft_rt_half'));

            unlink("$directory/sft_20260101_000002_fails_halfway.php");
            $tests->assertSame(['sft_20260101_000001_create_ok.php'], $runner->rollback());
            $tests->assertSame(false, $schema->hasTable('sft_rt_ok'));
        } finally {
            array_map('unlink', glob("$directory/*.php") ?: []);
            rmdir($directory);
        }
    });

    $test('the migration lock is held against a second connection', function () use ($tests, $pdo, $connect): void {
        /*
         * The lock is the whole answer to two instances migrating on deploy, and
         * it is exactly the kind of code that reads correctly and does nothing:
         * every statement in it is server-side, so nothing but a real server
         * proves it works. One connection takes it, a second must be refused.
         */
        $mine = new MigrationLock($pdo, 60);
        $tests->assertSame(true, $mine->acquire());
        $tests->assertSame(true, $mine->isHeld());

        $other = $connect();
        // A one-second timeout, so being refused costs a second rather than a minute.
        $theirs = new MigrationLock($other, 1);

        $tests->assertThrows(fn () => $theirs->acquire(), RuntimeException::class);
        $tests->assertSame(false, $theirs->isHeld());

        $mine->release();
        $tests->assertSame(false, $mine->isHeld());

        // Released, so the next process gets it.
        $tests->assertSame(true, $theirs->acquire());
        $theirs->release();
    });

    $test('a lock dies with the connection that held it', function () use ($tests, $pdo, $connect): void {
        /*
         * The reason an advisory lock was the right tool rather than a row in a
         * table: a deploy killed mid-migration must not leave a lock nobody can
         * clear. Dropping the connection has to be enough.
         */
        $abandoned = $connect();
        $lost = new MigrationLock($abandoned, 60);
        $tests->assertSame(true, $lost->acquire());

        unset($lost, $abandoned);
        gc_collect_cycles();

        $mine = new MigrationLock($pdo, 5);
        $tests->assertSame(true, $mine->acquire());
        $mine->release();
    });
}


/**
 * Register the tests that need a real Redis server.
 *
 * These subsystems had no integration test at all: the cache, the session
 * handler over it and the queue driver were all written against a real server's
 * API and never run against one. The queue driver was losing every job id.
 *
 * @param TestRunner $tests The runner
 * @param \Redis $redis The connection
 * @return void
 */
function registerRedisTests(TestRunner $tests, \Redis $redis): void
{
    $test = static function (string $name, callable $body) use ($tests, $redis): void {
        $tests->run("[redis] $name", static function () use ($body, $redis): void {
            $redis->flushAll();
            $body();
        });
    };

    $test('the cache driver stores, counts and expires', function () use ($tests, $redis): void {
        $cache = new \SfphpProject\src\Cache\CacheManager(
            new \SfphpProject\src\Cache\RedisDriver($redis)
        );

        $cache->put('k', ['a' => 1], 60);
        $tests->assertSame(['a' => 1], $cache->get('k'));
        $tests->assertSame(true, $cache->has('k'));
        $tests->assertSame('fallback', $cache->get('absent', 'fallback'));

        /*
         * The counter is stored as a bare integer, because that is the only
         * thing Redis can add to. get() has to recognise it rather than hand a
         * non-serialised value to unserialize().
         */
        $tests->assertSame(1, $cache->increment('n', 1, 60));
        $tests->assertSame(6, $cache->increment('n', 5, 60));
        $tests->assertSame(6, (int) $cache->get('n'));

        $ttl = $cache->ttl('n');
        $tests->assertSame(true, is_int($ttl) && $ttl > 0 && $ttl <= 60);

        // The lifetime belongs to the counter that was created.
        $cache->increment('n', 1, 3600);
        $tests->assertSame(true, $cache->ttl('n') <= 60);
        $tests->assertSame(null, $cache->ttl('never-set'));

        $cache->forget('k');
        $tests->assertSame(false, $cache->has('k'));
    });

    $test('the session handler shares sessions through the cache', function () use ($tests, $redis): void {
        $cache = new \SfphpProject\src\Cache\CacheManager(
            new \SfphpProject\src\Cache\RedisDriver($redis)
        );

        // Two handlers over one store is what two application instances are.
        $first = new \SfphpProject\src\Session\CacheHandler($cache, 'session:');
        $second = new \SfphpProject\src\Session\CacheHandler($cache, 'session:');

        $id = str_repeat('a', 32);
        $tests->assertSame(true, $first->write($id, 'user_id|i:7;'));
        $tests->assertSame('user_id|i:7;', $second->read($id));

        $tests->assertSame(true, $second->validateId($id));
        $tests->assertSame(false, $second->validateId('an-id-nobody-issued'));

        $second->destroy($id);
        $tests->assertSame('', $first->read($id));
    });

    $test('the queue driver keeps the job id across the round trip', function () use ($tests, $redis): void {
        $queue = new \SfphpProject\src\Queue\RedisDriver($redis);

        $id = $queue->push(new SftQueueJob('primeiro'));
        $tests->assertSame(1, $queue->size());

        $job = $queue->pop();
        $tests->assertSame(true, $job instanceof SftQueueJob);
        $tests->assertSame('primeiro', $job->mark);

        /*
         * This is what was broken. Job's own properties were serialised with
         * the payload, so restoring them put back the null id captured at push
         * time and overwrote the one the driver had just assigned. Both drivers
         * had their own copy of that code and only one was fixed, which is why
         * it now lives on Job.
         */
        $tests->assertSame($id, $job->getId());

        // Taken, so it is not handed out twice.
        $tests->assertSame(null, $queue->pop());
        $tests->assertSame(0, $queue->size());
    });

    $test('CACHE_DRIVER and QUEUE_DRIVER reach a real server', function () use ($tests, $redis): void {
        /*
         * The unit suite proves the right class is chosen. Only this proves the
         * chosen thing works: everything up to here built its drivers by hand,
         * so nothing had ever gone from a setting in .env through the helper to
         * a server and back.
         */
        \SfphpProject\src\Config::set('CACHE_DRIVER', 'redis');
        \SfphpProject\src\Config::set('CACHE_PREFIX', 'sft:cache:');
        \SfphpProject\src\Config::set('QUEUE_DRIVER', 'redis');
        \SfphpProject\src\RedisConnection::use($redis);

        try {
            $cache = \SfphpProject\src\Cache\CacheManager::fromConfig();
            $cache->put('configured', ['through' => 'config'], 60);

            $tests->assertSame(['through' => 'config'], $cache->get('configured'));

            // The prefix is the setting's, not the driver's default, so two
            // applications can share one instance without colliding.
            $tests->assertSame(true, $redis->exists('sft:cache:configured') > 0);

            $queue = \SfphpProject\src\Queue\QueueManager::fromConfig();
            $queue->flush();
            $id = $queue->push(new SftQueueJob('configurado'));

            $tests->assertSame(1, $queue->size());

            $job = $queue->pop();
            $tests->assertSame(true, $job instanceof SftQueueJob);
            $tests->assertSame($id, $job->getId());
        } finally {
            \SfphpProject\src\RedisConnection::use(null);
            \SfphpProject\src\Config::forget('CACHE_DRIVER');
            \SfphpProject\src\Config::forget('CACHE_PREFIX');
            \SfphpProject\src\Config::forget('QUEUE_DRIVER');
        }
    });
}

$targets = [
    'mysql' => ['SFPHP_TEST_MYSQL_DSN', 'SFPHP_TEST_MYSQL_USER', 'SFPHP_TEST_MYSQL_PASS'],
    'pgsql' => ['SFPHP_TEST_PGSQL_DSN', 'SFPHP_TEST_PGSQL_USER', 'SFPHP_TEST_PGSQL_PASS'],
];

$tests = new TestRunner();
$connections = [];

foreach ($targets as $driver => [$dsnVariable, $userVariable, $passVariable]) {
    $dsn = getenv($dsnVariable);
    if ($dsn === false || $dsn === '') {
        echo "SKIP $driver: set $dsnVariable to run these tests.\n";
        continue;
    }

    $connect = static fn (): PDO => new PDO(
        $dsn,
        getenv($userVariable) ?: null,
        getenv($passVariable) ?: null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo = $connect();

    $foreign = array_values(array_filter(
        dbTables($pdo, $driver),
        static fn (string $table): bool => !str_starts_with($table, 'sft_') && $table !== 'migrations'
    ));

    if ($foreign !== []) {
        fwrite(STDERR, "Refusing to run against $driver: the database holds tables that are not test tables ("
            . implode(', ', $foreign) . "). Use a throwaway database.\n");
        exit(2);
    }

    $hadMigrations = in_array('migrations', dbTables($pdo, $driver), true);
    $connections[] = [$pdo, $driver, $hadMigrations];
    registerSchemaTests($tests, $pdo, $driver, $connect);
}

/*
 * Redis is registered separately: it is not a database connection, and the
 * subsystems that use it — the cache, the session handler over it and the queue
 * driver — had no integration test of their own.
 */
$redisHost = getenv('SFPHP_TEST_REDIS_HOST');

if ($redisHost === false || $redisHost === '') {
    echo "SKIP redis: set SFPHP_TEST_REDIS_HOST to run these tests.\n";
} elseif (!extension_loaded('redis')) {
    echo "SKIP redis: ext-redis is not installed.\n";
} else {
    $redis = new \Redis();
    $redis->connect($redisHost, (int) (getenv('SFPHP_TEST_REDIS_PORT') ?: 6379));

    if ($redis->dbSize() > 0) {
        fwrite(STDERR, "Refusing to run against Redis: the database is not empty. Use a throwaway instance.\n");
        exit(2);
    }

    registerRedisTests($tests, $redis);
    $hasRedis = true;
}

if ($connections === [] && !($hasRedis ?? false)) {
    echo "No database configured; nothing to run.\n";
    exit(0);
}

register_shutdown_function(static function () use ($connections): void {
    foreach ($connections as [$pdo, $driver, $hadMigrations]) {
        dbCleanup($pdo, $driver);

        if (!$hadMigrations) {
            $pdo->exec('DROP TABLE IF EXISTS migrations');
        }
    }
});

$tests->finish();
