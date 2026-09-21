<?php

require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\app\controllers\BaseAPIController;
use SfphpProject\src\Csrf;
use SfphpProject\src\Container;
use SfphpProject\src\JWT;
use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Identifier;
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Console\Application;
use SfphpProject\src\Cache\FileDriver;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Database\Factory;
use SfphpProject\src\Database\Seeder;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\QueryBuilder;
use SfphpProject\src\Queue\DatabaseDriver;
use SfphpProject\src\Queue\QueueManager;
use SfphpProject\src\Queue\RedisDriver;
use SfphpProject\src\RawQuery;
use SfphpProject\src\Route;
use SfphpProject\src\Router;
use SfphpProject\src\Str;
use SfphpProject\src\Validator;
use SfphpProject\src\View;
use SfphpProject\src\View\SfhtEngine;

require __DIR__ . '/TestRunner.php';

final class QueryBuilderStatementTest extends PDOStatement
{
    public string $sql;
    public array $bindings = [];
    public mixed $row = ['aggregate' => 0];

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->row;
    }

    public function bindValue(
        string|int $param,
        mixed $value,
        int $type = PDO::PARAM_STR
    ): bool {
        $this->bindings[$param] = $value;

        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function rowCount(): int
    {
        return 1;
    }
}

final class QueryBuilderPdoTest extends PDO
{
    public array $statements = [];

    public function __construct() {}

    public function getAttribute(int $attribute): mixed
    {
        return 'mysql';
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $statement = new QueryBuilderStatementTest();
        $statement->sql = $query;
        $this->statements[] = $statement;

        return $statement;
    }

    public function lastInsertId(?string $name = null): string
    {
        return '1';
    }
}

final class MigrationStatementTest extends PDOStatement
{
    public string $sql;
    public array $bindings = [];
    public array $rows = [];
    public mixed $value = null;

    public function __construct(private MigrationPdoTest $pdo) {}

    public function bindValue(
        string|int $param,
        mixed $value,
        int $type = PDO::PARAM_STR
    ): bool {
        $this->bindings[$param] = $value;

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bindings = $params;
        }

        $this->pdo->executeStatement($this->sql, $this->bindings, $this);

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }

    public function rowCount(): int
    {
        return is_array($this->rows) ? count($this->rows) : 0;
    }
}

final class MigrationPdoTest extends PDO
{
    public array $migrations = [];
    public array $tables = [];
    public int $sequence = 0;

    public function __construct() {}

    public function getAttribute(int $attribute): mixed
    {
        return 'sqlite';
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS migrations')) {
            return 0;
        }

        return 0;
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $statement = new MigrationStatementTest($this);
        $statement->sql = $query;
        $statement->execute();

        return $statement;
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $statement = new MigrationStatementTest($this);
        $statement->sql = $query;

        return $statement;
    }

    public function lastInsertId(?string $name = null): string
    {
        return (string) count($this->migrations);
    }

    public function executeStatement(
        string $sql,
        array $bindings,
        MigrationStatementTest $statement
    ): void {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS migrations/i', $sql)) {
            return;
        }

        if (preg_match('/^CREATE TABLE\s+[\"`\[]?(?<table>[A-Za-z_][A-Za-z0-9_]*)[\"`\]]?/i', $sql, $matches)) {
            $this->tables[$matches['table']] = true;
            return;
        }

        if (preg_match('/^DROP TABLE IF EXISTS\s+[\"`\[]?(?<table>[A-Za-z_][A-Za-z0-9_]*)[\"`\]]?/i', $sql, $matches)) {
            unset($this->tables[$matches['table']]);
            return;
        }

        if (preg_match('/^INSERT INTO migrations/i', $sql)) {
            $migration = $bindings['migration'];
            $this->migrations[$migration] = [
                'migration' => $migration,
                'batch' => (int) $bindings['batch'],
                'applied_at' => sprintf('2026-09-19 12:00:%02d', $this->sequence++),
            ];

            return;
        }

        if (preg_match('/^DELETE FROM migrations WHERE migration = :migration/i', $sql)) {
            unset($this->migrations[$bindings['migration']]);
            return;
        }

        if (preg_match('/^SELECT COUNT\\(\\*\\) FROM migrations WHERE migration = :migration/i', $sql)) {
            $statement->value = isset($this->migrations[$bindings['migration']]) ? 1 : 0;
            return;
        }

        if (preg_match('/^SELECT COALESCE\\(MAX\\(batch\\), 0\\) \\+ 1 AS batch FROM migrations/i', $sql)) {
            $statement->value = $this->migrations === []
                ? 1
                : max(array_column($this->migrations, 'batch')) + 1;

            return;
        }

        if (preg_match('/^SELECT migration, batch, applied_at FROM migrations ORDER BY applied_at ASC, migration ASC/i', $sql)) {
            $statement->rows = array_values($this->sortedMigrations());
            return;
        }

        if (preg_match('/^SELECT migration, batch, applied_at FROM migrations ORDER BY applied_at DESC, migration DESC LIMIT :limit/i', $sql)) {
            $limit = (int) $bindings[':limit'];
            $statement->rows = array_slice(array_values($this->sortedMigrationsDesc()), 0, $limit);
            return;
        }
    }

    private function sortedMigrations(): array
    {
        $rows = array_values($this->migrations);
        usort($rows, static function (array $left, array $right): int {
            return [$left['applied_at'], $left['migration']] <=> [$right['applied_at'], $right['migration']];
        });

        return $rows;
    }

    private function sortedMigrationsDesc(): array
    {
        $rows = array_values($this->migrations);
        usort($rows, static function (array $left, array $right): int {
            return [$right['applied_at'], $right['migration']] <=> [$left['applied_at'], $left['migration']];
        });

        return $rows;
    }
}

final class SchemaStatementTest extends PDOStatement
{
    public string $sql;

    public function __construct(private SchemaPdoTest $pdo) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->statements[] = $this->sql;

        return true;
    }
}

final class SchemaPdoTest extends PDO
{
    public array $statements = [];

    public function __construct() {}

    public function getAttribute(int $attribute): mixed
    {
        return 'mysql';
    }

    public function prepare(
        string $query,
        array $options = []
    ): PDOStatement|false {
        $statement = new SchemaStatementTest($this);
        $statement->sql = $query;

        return $statement;
    }
}

final class ContainerDependencyTest {}

final class ContainerDefaultTest
{
    public function __construct(
        public ContainerDependencyTest $dependency,
        public string $label = 'default'
    ) {}
}

final class ContainerCycleATest
{
    public function __construct(public ContainerCycleBTest $dependency) {}
}

final class ContainerCycleBTest
{
    public function __construct(public ContainerCycleATest $dependency) {}
}

function writeMigrationFixture(string $directory, string $fileName, string $table): string
{
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
    file_put_contents($path, <<<PHP
<?php

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Migration;
use SfphpProject\src\Migrations\Schema;

return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        \$schema->create('$table', function (Blueprint \$table): void {
            \$table->id();
            \$table->string('name');
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->dropIfExists('$table');
    }
};
PHP);

    return $path;
}

$tests = new TestRunner();

/*
 * SFHT fixtures. Templates are written to a throwaway directory and rendered
 * through a real engine, so the tests exercise the parser, the compiler and
 * the on-disk compilation cache together.
 */
$sfhtDirectory = sys_get_temp_dir() . '/sfphp-sfht-tests-' . bin2hex(random_bytes(6));
mkdir($sfhtDirectory, 0755, true);

$sfhtEngine = new SfhtEngine([$sfhtDirectory], $sfhtDirectory . '/cache');

$sfht = static function (string $template, array $data = []) use ($sfhtDirectory, $sfhtEngine): string {
    $name = 'tpl_' . bin2hex(random_bytes(6));
    file_put_contents($sfhtDirectory . '/' . $name . '.sfht', $template);

    return $sfhtEngine->render($name, $data);
};

register_shutdown_function(static function () use ($sfhtDirectory): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sfhtDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($sfhtDirectory);
});

$tests->run('csrf tokens persist and validate requests', function () use ($tests): void {
    $savedServer = $_SERVER;
    $savedPost = $_POST;
    $savedSession = $_SESSION ?? [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_id('csrf-test');
    $_SESSION = [];
    $_POST = [];
    $_SERVER = [];

    $token = csrf_token();
    $tests->assertSame($token, csrf_token());
    $tests->assertTrue(str_contains(csrf_field(), $token));
    $tests->assertTrue(str_contains(csrf_meta(), $token));

    $_POST['_token'] = $token;
    $tests->assertTrue(Csrf::validateRequest());

    $_POST['_token'] = 'invalid';
    $tests->assertSame(false, csrf_verify());

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $_SERVER = $savedServer;
    $_POST = $savedPost;
    $_SESSION = $savedSession;
});

$tests->run('named routes generate validated URLs', function () use ($tests): void {
    Router::get('/tests/id:number', 'TestController', 'show')->name('tests.show');
    $tests->assertSame('/tests/7?page=2', Router::url(
        'tests.show',
        ['id' => 7],
        ['page' => 2]
    ));
    $tests->assertThrows(
        fn () => Router::url('tests.show', ['id' => 'invalid']),
        InvalidArgumentException::class
    );
});

$tests->run('container resolves defaults and rejects cycles', function () use ($tests): void {
    $container = new Container();
    $resolved = $container->get(ContainerDefaultTest::class);
    $tests->assertTrue($resolved->dependency instanceof ContainerDependencyTest);
    $tests->assertSame('default', $resolved->label);
    $tests->assertThrows(
        fn () => $container->get(ContainerCycleATest::class),
        RuntimeException::class
    );
});

$tests->run('query builder binds filtered deletes', function () use ($tests): void {
    $pdo = new QueryBuilderPdoTest();
    (new QueryBuilder($pdo))->from('records')->where('id', 1)->delete();
    $tests->assertSame([':binding_0' => 1], $pdo->statements[0]->bindings);
});

$tests->run('API headers accept redirected bearer tokens', function () use ($tests): void {
    $_SERVER = ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-token'];
    $tests->assertSame('test-token', (new BaseAPIController())->getBearerToken());
});

$tests->run('JWT rejects tampered tokens', function () use ($tests): void {
    $_ENV['JWT_KEY'] = str_repeat('a', 32);
    $token = JWT::generate(['id' => 1, 'email' => 'test@example.com']);
    $tests->assertTrue(JWT::validate($token));
    $tests->assertSame(false, JWT::validate($token . 'x'));
});

$tests->run('validator rejects malformed rules', function () use ($tests): void {
    $tests->assertThrows(
        fn () => Validator::validate(['name' => 'Ada'], ['name' => 'min:abc']),
        InvalidArgumentException::class
    );
});

$tests->run('views escape output and protect view names', function () use ($tests): void {
    $tests->assertSame('&lt;script&gt;', e('<script>'));
    $tests->assertSame('/assets/images/logo.png', asset('images/logo.png'));
    $tests->assertThrows(
        fn () => asset('../.env'),
        InvalidArgumentException::class
    );

    ob_start();
    View::partial('header', ['title' => '<script>']);
    $output = ob_get_clean();
    $tests->assertTrue(str_contains($output, '&lt;script&gt;'));
});

$tests->run('migration creator builds timestamped stubs', function () use ($tests): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sfphp-migrations-' . uniqid('', true);
    mkdir($directory, 0775, true);

    $creator = new MigrationCreator($directory);
    $path = $creator->create('create_users_table');

    $tests->assertTrue(is_file($path));
    $tests->assertTrue((bool) preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_users_table\.php$/', $path));

    $contents = file_get_contents($path);
    $tests->assertTrue(str_contains($contents, 'return new class extends Migration'));
});

$tests->run('migration runner applies and rolls back files', function () use ($tests): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sfphp-migrations-' . uniqid('', true);
    mkdir($directory, 0775, true);

    writeMigrationFixture($directory, '2026_09_19_120000_create_users_table.php', 'users');
    writeMigrationFixture($directory, '2026_09_19_120001_create_posts_table.php', 'posts');

    $pdo = new MigrationPdoTest();

    $runner = new MigrationRunner($pdo, $directory);
    $tests->assertSame(
        [
            '2026_09_19_120000_create_users_table.php',
            '2026_09_19_120001_create_posts_table.php',
        ],
        $runner->migrate()
    );

    $tests->assertTrue(isset($pdo->tables['users']));
    $tests->assertTrue(isset($pdo->tables['posts']));

    $tests->assertSame(
        ['2026_09_19_120001_create_posts_table.php'],
        $runner->rollback(1)
    );

    $tests->assertTrue(isset($pdo->tables['users']));
    $tests->assertTrue(!isset($pdo->tables['posts']));
});

$tests->run('schema builder creates tables and alters columns', function () use ($tests): void {
    $pdo = new SchemaPdoTest();
    $schema = new Schema($pdo);

    $schema->create('users', function (\SfphpProject\src\Migrations\Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $schema->table('users', function (\SfphpProject\src\Migrations\Blueprint $table): void {
        $table->string('nickname')->nullable()->index();
        $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete()->cascadeOnUpdate();
        $table->renameColumn('nickname', 'display_name');
        $table->string('email', 320)->change()->nullable()->after('name');
        $table->dropColumn('obsolete_field');
        $table->dropUnique('email');
        $table->dropIndex(['display_name']);
        $table->dropForeign('company_id');
        $table->check('display_name <> ""', 'users_display_name_check');
    });

    $tests->assertSame(
        [
            'CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `email` VARCHAR(255) NOT NULL, `name` VARCHAR(255) NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`)',
            'ALTER TABLE `users` ADD COLUMN `nickname` VARCHAR(255) NULL',
            'ALTER TABLE `users` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL',
            'ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(320) NULL AFTER `name`',
            'CREATE INDEX `users_nickname_index` ON `users` (`nickname`)',
            'ALTER TABLE `users` ADD CONSTRAINT `users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
            'ALTER TABLE `users` RENAME COLUMN `nickname` TO `display_name`',
            'ALTER TABLE `users` DROP COLUMN `obsolete_field`',
            'DROP INDEX `users_email_unique` ON `users`',
            'DROP INDEX `users_display_name_index` ON `users`',
            'ALTER TABLE `users` DROP FOREIGN KEY `users_company_id_foreign`',
            'ALTER TABLE `users` ADD CONSTRAINT `users_display_name_check` CHECK (display_name <> "")',
        ],
        $pdo->statements
    );
});

$tests->run('schema builder covers common column helpers', function () use ($tests): void {
    $pdo = new SchemaPdoTest();
    $schema = new Schema($pdo);

    $schema->create('media', function (\SfphpProject\src\Migrations\Blueprint $table): void {
        $table->increments('id');
        $table->char('code', 32)->unique();
        $table->binary('payload')->nullable();
        $table->ulid('public_id')->index();
        $table->rememberToken();
        $table->softDeletes();
        $table->timestampsTz();
    });

    $schema->table('media', function (\SfphpProject\src\Migrations\Blueprint $table): void {
        $table->string('slug', 80)->change()->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->comment('Slug');
        $table->timestamp('published_at')->nullable()->useCurrent()->useCurrentOnUpdate();
    });

    $tests->assertSame(
        [
            'CREATE TABLE `media` (`id` INTEGER UNSIGNED AUTO_INCREMENT PRIMARY KEY, `code` CHAR(32) NOT NULL, `payload` BLOB NULL, `public_id` CHAR(26) NOT NULL, `remember_token` VARCHAR(100) NULL, `deleted_at` TIMESTAMP NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE UNIQUE INDEX `media_code_unique` ON `media` (`code`)',
            'CREATE INDEX `media_public_id_index` ON `media` (`public_id`)',
            'ALTER TABLE `media` MODIFY COLUMN `slug` VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT \'Slug\'',
            'ALTER TABLE `media` ADD COLUMN `published_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        $pdo->statements
    );
});

$compileSchema = static function (string $driver, string $mode, string $table, callable $define): array {
    $blueprint = new Blueprint($table, $driver, $mode);
    $define($blueprint);

    return $blueprint->compileStatements();
};

$tests->run('postgres schema uses native column types', function () use ($tests, $compileSchema): void {
    $statements = $compileSchema('pgsql', 'create', 'samples', function (Blueprint $table): void {
        $table->id();
        $table->tinyInteger('tiny')->nullable();
        $table->binary('payload')->nullable();
        $table->uuid('ref');
        $table->boolean('active')->default(true);
        $table->boolean('archived')->default(false);
        $table->jsonb('meta')->nullable();
        $table->float('ratio')->nullable();
        $table->double('precise')->nullable();
        $table->ipAddress('ip')->nullable();
        $table->macAddress('mac')->nullable();
        $table->year('born')->nullable();
        $table->timeTz('opens', 3)->nullable();
        $table->timestampTz('seen_at', 3)->useCurrent();
        $table->dateTime('at')->nullable();
    });

    $tests->assertSame(
        ['CREATE TABLE "samples" ("id" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "tiny" SMALLINT NULL, "payload" BYTEA NULL, "ref" UUID NOT NULL, "active" BOOLEAN NOT NULL DEFAULT TRUE, "archived" BOOLEAN NOT NULL DEFAULT FALSE, "meta" JSONB NULL, "ratio" REAL NULL, "precise" DOUBLE PRECISION NULL, "ip" INET NULL, "mac" MACADDR NULL, "born" SMALLINT NULL, "opens" TIMETZ(3) NULL, "seen_at" TIMESTAMPTZ(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), "at" TIMESTAMP NULL)'],
        $statements
    );
});

$tests->run('mysql schema uses native column types and expression defaults', function () use ($tests, $compileSchema): void {
    $statements = $compileSchema('mysql', 'create', 'samples', function (Blueprint $table): void {
        $table->dateTime('at')->nullable();
        $table->timestamp('seen_at', 6)->useCurrent()->useCurrentOnUpdate();
        $table->text('body')->default('x');
        $table->json('meta')->default([]);
        $table->boolean('active')->default(true);
        $table->string('note')->default('a\\b\'c');
        $table->double('precise')->nullable();
        $table->mediumText('summary')->nullable();
        $table->set('flags', ['a', 'b'])->nullable();
        $table->year('born')->nullable();
        $table->uuid('ref');
        $table->integer('a');
        $table->integer('b');
        $table->integer('total')->storedAs('a + b');
        $table->integer('half')->virtualAs('a / 2')->nullable();
        $table->engine('InnoDB')->tableCharset('utf8mb4')->tableCollation('utf8mb4_unicode_ci')->tableComment('Samples');
    });

    $tests->assertSame(
        ["CREATE TABLE `samples` (`at` DATETIME NULL, `seen_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), `body` TEXT NOT NULL DEFAULT ('x'), `meta` JSON NOT NULL DEFAULT ('[]'), `active` BOOLEAN NOT NULL DEFAULT 1, `note` VARCHAR(255) NOT NULL DEFAULT 'a\\\\b''c', `precise` DOUBLE NULL, `summary` MEDIUMTEXT NULL, `flags` SET('a', 'b') NULL, `born` YEAR NULL, `ref` CHAR(36) NOT NULL, `a` INTEGER NOT NULL, `b` INTEGER NOT NULL, `total` INTEGER GENERATED ALWAYS AS (a + b) STORED NOT NULL, `half` INTEGER GENERATED ALWAYS AS (a / 2) VIRTUAL NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Samples'"],
        $statements
    );
});

$tests->run('postgres emulates enum, comments and ON UPDATE with constraints and triggers', function () use ($tests, $compileSchema): void {
    $statements = $compileSchema('pgsql', 'create', 'posts', function (Blueprint $table): void {
        $table->id();
        $table->enum('status', ['draft', 'it\'s'])->default('draft');
        $table->string('slug')->comment('URL slug')->collation('C');
        $table->timestamp('touched_at')->useCurrent()->useCurrentOnUpdate();
        $table->tableComment('Posts');
        $table->integer('a');
        $table->integer('total')->storedAs('a * 2');
    });

    $tests->assertSame(
        [
            'CREATE TABLE "posts" ("id" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "status" VARCHAR(255) NOT NULL DEFAULT \'draft\' CONSTRAINT "posts_status_enum" CHECK ("status" IN (\'draft\', \'it\'\'s\')), "slug" VARCHAR(255) COLLATE "C" NOT NULL, "touched_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, "a" INTEGER NOT NULL, "total" INTEGER GENERATED ALWAYS AS (a * 2) STORED NOT NULL)',
            'COMMENT ON COLUMN "posts"."slug" IS \'URL slug\'',
            'CREATE OR REPLACE FUNCTION sfphp_set_current_timestamp() RETURNS TRIGGER AS $$ BEGIN NEW := jsonb_populate_record(NEW, jsonb_build_object(TG_ARGV[0], CURRENT_TIMESTAMP)); RETURN NEW; END; $$ LANGUAGE plpgsql',
            'CREATE TRIGGER "posts_touched_at_on_update" BEFORE UPDATE ON "posts" FOR EACH ROW EXECUTE FUNCTION sfphp_set_current_timestamp(\'touched_at\')',
            'COMMENT ON TABLE "posts" IS \'Posts\'',
        ],
        $statements
    );
});

$tests->run('postgres change column casts the type and resets constraints, comment and trigger', function () use ($tests, $compileSchema): void {
    $statements = $compileSchema('pgsql', 'alter', 'app.posts', function (Blueprint $table): void {
        $table->string('title', 80)->change()->nullable()->default('x')->comment('Title');
        $table->enum('status', ['a', 'b'])->change();
    });

    $tests->assertSame(
        [
            'ALTER TABLE "app"."posts" ALTER COLUMN "title" DROP DEFAULT',
            'ALTER TABLE "app"."posts" ALTER COLUMN "title" TYPE VARCHAR(80) USING "title"::VARCHAR(80)',
            'ALTER TABLE "app"."posts" ALTER COLUMN "title" DROP NOT NULL',
            'ALTER TABLE "app"."posts" ALTER COLUMN "title" SET DEFAULT \'x\'',
            'ALTER TABLE "app"."posts" DROP CONSTRAINT IF EXISTS "posts_title_enum"',
            'COMMENT ON COLUMN "app"."posts"."title" IS \'Title\'',
            'DROP TRIGGER IF EXISTS "posts_title_on_update" ON "app"."posts"',
            'ALTER TABLE "app"."posts" ALTER COLUMN "status" DROP DEFAULT',
            'ALTER TABLE "app"."posts" ALTER COLUMN "status" TYPE VARCHAR(255) USING "status"::VARCHAR(255)',
            'ALTER TABLE "app"."posts" ALTER COLUMN "status" SET NOT NULL',
            'ALTER TABLE "app"."posts" DROP CONSTRAINT IF EXISTS "posts_status_enum"',
            'ALTER TABLE "app"."posts" ADD CONSTRAINT "posts_status_enum" CHECK ("status" IN (\'a\', \'b\'))',
            'COMMENT ON COLUMN "app"."posts"."status" IS NULL',
            'DROP TRIGGER IF EXISTS "posts_status_on_update" ON "app"."posts"',
        ],
        $statements
    );
});

$tests->run('index, key and drop operations follow each dialect', function () use ($tests, $compileSchema): void {
    $define = function (Blueprint $table): void {
        $table->string('title');
        $table->text('body');
        $table->index(['title', 'id'])->algorithm('hash');
        $table->fullText('body');
        $table->primary(['id']);
        $table->renameIndex('old_idx', 'new_idx');
        $table->dropPrimary();
        $table->dropFullText('body');
        $table->dropCheck('posts_positive');
    };

    $tests->assertSame(
        [
            'ALTER TABLE `app`.`posts` ADD COLUMN `title` VARCHAR(255) NOT NULL',
            'ALTER TABLE `app`.`posts` ADD COLUMN `body` TEXT NOT NULL',
            'CREATE INDEX `posts_title_id_index` USING HASH ON `app`.`posts` (`title`, `id`)',
            'CREATE FULLTEXT INDEX `posts_body_fulltext` ON `app`.`posts` (`body`)',
            'ALTER TABLE `app`.`posts` ADD CONSTRAINT `posts_pkey` PRIMARY KEY (`id`)',
            'ALTER TABLE `app`.`posts` RENAME INDEX `old_idx` TO `new_idx`',
            'ALTER TABLE `app`.`posts` DROP PRIMARY KEY',
            'DROP INDEX `posts_body_fulltext` ON `app`.`posts`',
            'ALTER TABLE `app`.`posts` DROP CONSTRAINT `posts_positive`',
        ],
        $compileSchema('mysql', 'alter', 'app.posts', $define)
    );

    $tests->assertSame(
        [
            'ALTER TABLE "app"."posts" ADD COLUMN "title" VARCHAR(255) NOT NULL',
            'ALTER TABLE "app"."posts" ADD COLUMN "body" TEXT NOT NULL',
            'CREATE INDEX "posts_title_id_index" ON "app"."posts" USING hash ("title", "id")',
            'CREATE INDEX "posts_body_fulltext" ON "app"."posts" USING gin ((to_tsvector(\'english\', "body")))',
            'ALTER TABLE "app"."posts" ADD CONSTRAINT "posts_pkey" PRIMARY KEY ("id")',
            'ALTER INDEX "app"."old_idx" RENAME TO "new_idx"',
            'ALTER TABLE "app"."posts" DROP CONSTRAINT "posts_pkey"',
            'DROP INDEX "app"."posts_body_fulltext"',
            'ALTER TABLE "app"."posts" DROP CONSTRAINT "posts_positive"',
        ],
        $compileSchema('pgsql', 'alter', 'app.posts', $define)
    );

    $tests->assertSame(
        ['CREATE INDEX "posts_slug_index" ON "posts" ("slug") WHERE deleted_at IS NULL'],
        $compileSchema('pgsql', 'alter', 'posts', function (Blueprint $table): void {
            $table->index('slug')->where('deleted_at IS NULL');
        })
    );

    $tests->assertSame(
        ['ALTER TABLE `posts` ADD CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'],
        $compileSchema('mysql', 'alter', 'posts', function (Blueprint $table): void {
            $table->foreign('user_id')->references('users')->nullOnDelete();
        })
    );

    $tests->assertSame(
        ['ALTER TABLE "posts" ADD CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET DEFAULT DEFERRABLE INITIALLY DEFERRED'],
        $compileSchema('pgsql', 'alter', 'posts', function (Blueprint $table): void {
            $table->foreign('user_id')->references('users')->onDelete('set default')->deferrable(true);
        })
    );
});

$tests->run('features one dialect cannot honor fail instead of silently changing meaning', function () use ($tests, $compileSchema): void {
    $throws = static fn (string $driver, callable $define) => static fn () => $compileSchema($driver, 'create', 't', $define);

    $tests->assertThrows($throws('mysql', function (Blueprint $table): void {
        $table->string('a');
        $table->index('a')->where('a IS NOT NULL');
    }), InvalidArgumentException::class);
    $tests->assertThrows($throws('mysql', function (Blueprint $table): void {
        $table->foreignId('a');
        $table->foreign('a')->references('x')->deferrable();
    }), InvalidArgumentException::class);
    $tests->assertThrows($throws('mysql', function (Blueprint $table): void {
        $table->foreignId('a');
        $table->foreign('a')->references('x')->onDelete('SET DEFAULT');
    }), InvalidArgumentException::class);
    $tests->assertThrows($throws('pgsql', function (Blueprint $table): void {
        $table->integer('a')->virtualAs('1');
    }), InvalidArgumentException::class);
    $tests->assertThrows($throws('pgsql', function (Blueprint $table): void {
        $table->set('a', ['x']);
    }), InvalidArgumentException::class);
    $tests->assertThrows($throws('pgsql', function (Blueprint $table): void {
        $table->string('a');
        $table->index('a')->algorithm('fulltext');
    }), InvalidArgumentException::class);
    $tests->assertThrows(function (): void {
        (new Blueprint('t', 'mysql'))->foreign('a')->onDelete('DROP EVERYTHING');
    }, InvalidArgumentException::class);
    $tests->assertThrows(function (): void {
        (new Blueprint('t', 'mysql'))->timestamp('a', 9);
    }, InvalidArgumentException::class);
    $tests->assertThrows($throws('pgsql', function (Blueprint $table): void {
        $table->string('a')->default("nul\0byte");
    }), InvalidArgumentException::class);
});

$tests->run('polymorphic helpers compile on both dialects with table scoped index names', function () use ($tests, $compileSchema): void {
    $define = function (Blueprint $table): void {
        $table->id();
        $table->uuidMorphs('owner');
        $table->nullableMorphs('taggable');
    };

    $tests->assertSame(
        [
            'CREATE TABLE `notes` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `owner_id` CHAR(36) NOT NULL, `owner_type` VARCHAR(255) NOT NULL, `taggable_id` BIGINT UNSIGNED NULL, `taggable_type` VARCHAR(255) NULL)',
            'CREATE INDEX `notes_owner_id_owner_type_index` ON `notes` (`owner_id`, `owner_type`)',
            'CREATE INDEX `notes_taggable_id_taggable_type_index` ON `notes` (`taggable_id`, `taggable_type`)',
        ],
        $compileSchema('mysql', 'create', 'notes', $define)
    );

    $tests->assertSame(
        [
            'CREATE TABLE "notes" ("id" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "owner_id" UUID NOT NULL, "owner_type" VARCHAR(255) NOT NULL, "taggable_id" BIGINT NULL, "taggable_type" VARCHAR(255) NULL)',
            'CREATE INDEX "notes_owner_id_owner_type_index" ON "notes" ("owner_id", "owner_type")',
            'CREATE INDEX "notes_taggable_id_taggable_type_index" ON "notes" ("taggable_id", "taggable_type")',
        ],
        $compileSchema('pgsql', 'create', 'notes', $define)
    );
});

$tests->run('generated names respect the driver identifier limit and stay deterministic', function () use ($tests, $compileSchema): void {
    $table = 'a_table_with_a_rather_long_name_for_testing_purposes';
    $columns = ['first_long_column_name', 'second_long_column_name'];

    foreach (['mysql' => 64, 'pgsql' => 63] as $driver => $limit) {
        $created = $compileSchema($driver, 'alter', $table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        })[0];
        $dropped = $compileSchema($driver, 'alter', $table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->dropIndex($columns);
        })[0];

        preg_match('/INDEX [`"]([^`"]+)[`"]/', $created, $create);
        preg_match('/INDEX [`"]([^`"]+)[`"]/', $dropped, $drop);

        $tests->assertTrue(strlen($create[1]) <= $limit);
        $tests->assertSame($create[1], $drop[1]);
    }

    $tests->assertThrows(function () use ($table): void {
        Identifier::quote('pgsql', str_repeat('a', 64));
    }, InvalidArgumentException::class);
    $tests->assertSame('"app"."users"', Identifier::quoteTable('pgsql', 'app.users'));
    $tests->assertThrows(function (): void {
        Identifier::quoteTable('pgsql', 'a.b.c');
    }, InvalidArgumentException::class);
});

$tests->run('raw columns and convenience drops', function () use ($tests, $compileSchema): void {
    $tests->assertSame(
        ['ALTER TABLE "posts" ADD COLUMN "tags" TEXT[] NULL'],
        $compileSchema('pgsql', 'alter', 'posts', function (Blueprint $table): void {
            $table->rawColumn('tags', 'TEXT[]')->nullable();
        })
    );

    $tests->assertSame(
        [
            'ALTER TABLE `posts` DROP COLUMN `created_at`',
            'ALTER TABLE `posts` DROP COLUMN `updated_at`',
            'ALTER TABLE `posts` DROP COLUMN `deleted_at`',
            'ALTER TABLE `posts` DROP COLUMN `remember_token`',
            'ALTER TABLE `posts` DROP COLUMN `owner_id`',
            'ALTER TABLE `posts` DROP COLUMN `owner_type`',
        ],
        $compileSchema('mysql', 'alter', 'posts', function (Blueprint $table): void {
            $table->dropTimestamps();
            $table->dropSoftDeletes();
            $table->dropRememberToken();
            $table->dropMorphs('owner');
        })
    );
});

$tests->run('unicode aware string helpers count characters, not bytes', function () use ($tests): void {
    $tests->assertSame(3, Str::length('日本語'));
    $tests->assertSame(4, Str::length('José'));
    $tests->assertSame('日本...', Str::truncate('日本語テキスト', 5));
    $tests->assertSame('本', Str::substr('日本語', 1, 1));
    $tests->assertSame('語本日', Str::reverse('日本語'));
    $tests->assertTrue(Str::isUtf8(Str::truncate('日本語テキスト', 5)));

    $tests->assertTrue(Str::isAlpha('José'));
    $tests->assertTrue(Str::isAlpha('Владимир'));
    $tests->assertTrue(Str::isAlpha('北京'));
    $tests->assertSame(false, Str::isAlpha('abc123'));
    $tests->assertTrue(Str::isAlphanumeric('José99'));

    // ASCII-only on purpose: the value is meant to survive an (int) cast.
    $tests->assertTrue(Str::isNumeric('123'));
    $tests->assertSame(false, Str::isNumeric('١٢٣'));
});

$tests->run('validator measures characters and accepts every alphabet', function () use ($tests): void {
    $tests->assertTrue(Validator::validate(
        ['name' => 'José'],
        ['name' => 'alpha']
    )->passes());

    $tests->assertTrue(Validator::validate(
        ['name' => '北京'],
        ['name' => 'alpha']
    )->passes());

    // "日本語" is 3 characters but 9 bytes; a byte-based max:5 rejected it.
    $tests->assertTrue(Validator::validate(
        ['bio' => '日本語'],
        ['bio' => 'max:5']
    )->passes());

    $tests->assertSame(false, Validator::validate(
        ['bio' => '日本語テキスト'],
        ['bio' => 'max:5']
    )->passes());

    $tests->assertSame(false, Validator::validate(
        ['name' => 'abc123'],
        ['name' => 'alpha']
    )->passes());
});

$tests->run('routes match non-ascii paths and refuse synthesized separators', function () use ($tests): void {
    $route = new Route('GET', '/produtos/nome:alpha', 'MainController', 'show');

    $tests->assertSame(['nome' => 'café'], $route->match('/produtos/café'));
    $tests->assertSame(['nome' => '北京'], $route->match('/produtos/北京'));
    $tests->assertSame(null, $route->match('/produtos/abc123'));
    $tests->assertSame('/produtos/caf%C3%A9', $route->generateUrl(['nome' => 'café']));

    $decode = new ReflectionMethod(Router::class, 'decodePath');
    $decode->setAccessible(true);

    $tests->assertSame('/produtos/café', $decode->invoke(null, '/produtos/caf%C3%A9'));

    // An encoded separator must not become a real one, or "/a%2Fb" would
    // reach a route registered as "/a/b".
    $tests->assertSame('/a%2Fb', $decode->invoke(null, '/a%2Fb'));
    $tests->assertSame('/a%5Cb', $decode->invoke(null, '/a%5Cb'));
});

$tests->run('the framework error page makes no external requests', function () use ($tests): void {
    $render = new ReflectionMethod(Router::class, 'renderErrorPage');
    $render->setAccessible(true);

    ob_start();
    $render->invoke(null, HTTP_NOT_FOUND, '404', 'Nao encontrada');
    $html = ob_get_clean();

    $tests->assertSame(0, preg_match_all('#https?://#', $html));
    $tests->assertTrue(str_contains($html, '<style>'));
});

$tests->run('sfht keeps literal text that is not syntax', function () use ($tests, $sfht): void {
    // "@300" used to be read as a directive, which erased the whole line.
    $tests->assertSame(
        '<link href="?family=Inter:wght@300;400">',
        $sfht('<link href="?family=Inter:wght@300;400">')
    );

    // Text sharing a line with a directive used to be dropped.
    $tests->assertSame('<p>Ola sim</p>', $sfht('<p>Ola @if($x)sim@endif</p>', ['x' => true]));

    // addslashes() does not escape "$", so page text was interpolated.
    $tests->assertSame('Total: $valor', $sfht('Total: $valor', ['valor' => 'LEAKED']));

    $tests->assertSame('a@b.com', $sfht('a@b.com'));
    $tests->assertSame('AB', $sfht('A{{-- hidden --}}B'));
});

$tests->run('sfht escapes output unless raw is asked for', function () use ($tests, $sfht): void {
    $tests->assertSame(
        '&lt;script&gt;alert(1)&lt;/script&gt;',
        $sfht('{{ $v }}', ['v' => '<script>alert(1)</script>'])
    );

    $tests->assertSame('<b>', $sfht('{!! $v !!}', ['v' => '<b>']));

    // Expressions are compiled as PHP, not quoted into a literal string.
    $tests->assertSame('3', $sfht('{{ count($items) }}', ['items' => [1, 2, 3]]));
    $tests->assertSame('HELL...', $sfht('{{ $s | upper | truncate(7) }}', ['s' => 'hello world']));
    $tests->assertSame('日本...', $sfht('{{ $s | truncate(5) }}', ['s' => '日本語テキスト']));
});

$tests->run('sfht resolves template inheritance', function () use ($tests, $sfhtDirectory, $sfhtEngine): void {
    mkdir($sfhtDirectory . '/layouts', 0755, true);
    file_put_contents(
        $sfhtDirectory . '/layouts/base.sfht',
        "<title>@block('title')Default@endblock</title><body>@block('content')empty@endblock</body>"
    );

    file_put_contents(
        $sfhtDirectory . '/child.sfht',
        "@extends('layouts/base')@block('title')Page@endblock@block('content')<p>Hi</p>@endblock"
    );
    $tests->assertSame(
        '<title>Page</title><body><p>Hi</p></body>',
        $sfhtEngine->render('child')
    );

    // A block the child leaves alone falls back to the layout's version.
    file_put_contents(
        $sfhtDirectory . '/partial-child.sfht',
        "@extends('layouts/base')@block('content')only content@endblock"
    );
    $tests->assertSame(
        '<title>Default</title><body>only content</body>',
        $sfhtEngine->render('partial-child')
    );

    $tests->assertSame(
        '<title>Default</title><body>empty</body>',
        $sfhtEngine->render('layouts/base')
    );
});

$tests->run('sfht reports unbalanced directives instead of failing silently', function () use ($tests, $sfht): void {
    $tests->assertThrows(fn () => $sfht('@if(true)no end'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@endif'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@foreach($a as $b)@endif', ['a' => []]), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('{{ $unclosed'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('{{ $x | nosuchfilter }}', ['x' => 1]), RuntimeException::class);
});

$tests->run('sfht compiles to an includable file rather than eval', function () use ($tests, $sfht, $sfhtDirectory): void {
    $sfht('<p>{{ $a }}</p>', ['a' => 1]);

    $compiled = glob($sfhtDirectory . '/cache/*.php');
    $tests->assertTrue($compiled !== [] && $compiled !== false);
    $tests->assertTrue(str_starts_with(file_get_contents($compiled[0]), '<?php'));
});

$tests->run('cache and queue classes are autoloadable', function () use ($tests): void {
    // These lived under a "SfPhp\" namespace the PSR-4 map never covered, so
    // every one of them was a fatal error at runtime.
    foreach ([
        CacheManager::class,
        FileDriver::class,
        MemoryDriver::class,
        QueueManager::class,
        DatabaseDriver::class,
        Seeder::class,
        Factory::class,
    ] as $class) {
        $tests->assertTrue(class_exists($class));
    }

    $tests->assertTrue(function_exists('cache'));
    $tests->assertTrue(function_exists('dispatch'));

    // Every queue driver has to be able to list what failed; the manager used
    // to return a hardcoded empty array.
    foreach ([DatabaseDriver::class, RedisDriver::class] as $driver) {
        $tests->assertTrue((new ReflectionClass($driver))->hasMethod('failedJobs'));
    }
});

$tests->run('memory cache driver honours the cache contract', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());

    $cache->put('key', ['a' => 1]);
    $tests->assertSame(['a' => 1], $cache->get('key'));
    $tests->assertTrue($cache->has('key'));
    $tests->assertSame('computed', $cache->remember('lazy', 60, fn () => 'computed'));
    $tests->assertSame('computed', $cache->get('lazy'));

    $cache->forget('key');
    $tests->assertSame(false, $cache->has('key'));

    $cache->flush();
    $tests->assertSame(false, $cache->has('lazy'));
});

$tests->run('raw queries are autoloadable on their own', function () use ($tests): void {
    // RawQuery used to be declared inside QueryBuilder.php, so PSR-4 could
    // not find it and Database::query() was fatal as a first call.
    $tests->assertTrue(class_exists(RawQuery::class));
    $tests->assertSame(
        'src/RawQuery.php',
        str_replace(dirname(__DIR__) . '/', '', (new ReflectionClass(RawQuery::class))->getFileName())
    );
});

$tests->run('application seeders and factories are autoloadable', function () use ($tests): void {
    // The generators emit "Database\Seeders" and "Database\Factories", which
    // the PSR-4 map did not cover, so generated code never loaded.
    $tests->assertTrue(class_exists('Database\\Seeders\\DatabaseSeeder'));
    $tests->assertTrue(class_exists('Database\\Factories\\UserFactory'));
    $tests->assertTrue(is_subclass_of('Database\\Seeders\\DatabaseSeeder', Seeder::class));
    $tests->assertTrue(is_subclass_of('Database\\Factories\\UserFactory', Factory::class));
});

$tests->run('cli commands only call helpers that exist', function () use ($tests): void {
    /*
     * db:seed and queue:work called $this->getOption(), which was never
     * defined: both died with "undefined method" the moment they ran. Nothing
     * caught it because no test executed a CLI command body.
     */
    $reflection = new ReflectionClass(Application::class);
    $source = file_get_contents($reflection->getFileName());

    preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\(/', $source, $matches);

    foreach (array_unique($matches[1]) as $method) {
        $tests->assertTrue($reflection->hasMethod($method));
    }
});

$tests->run('sfht runs @php blocks as code, not as text', function () use ($tests, $sfht): void {
    // Listed as a directive but never implemented: the body was tokenized as
    // template text, so "@php $x = 1; @endphp" printed the statement.
    $tests->assertSame('2', trim($sfht('@php $x = 1 + 1; @endphp{{ $x }}')));
    $tests->assertThrows(fn () => $sfht('@php $x = 1;'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@endphp'), RuntimeException::class);
});

$tests->run('query builder counts rows without disturbing the query', function () use ($tests): void {
    // count() was called by the queue driver and documented, but never existed.
    $pdo = new QueryBuilderPdoTest();
    $builder = (new QueryBuilder($pdo))
        ->from('users')
        ->where('age', '>', 18)
        ->orderBy('name')
        ->limit(10);

    $builder->count();

    $tests->assertSame(
        'SELECT COUNT(*) AS `aggregate` FROM `users` WHERE `age` > :binding_0',
        $pdo->statements[0]->sql
    );

    // Ordering and pagination are restored for the caller.
    $tests->assertSame(
        'SELECT * FROM `users` WHERE `age` > :binding_0 ORDER BY `name` ASC LIMIT 10',
        $builder->toSql()
    );
});

$tests->run('the built stylesheet is css, not the builder log', function () use ($tests): void {
    /*
     * ./sfphp css:build captured the builder's stdout and wrote it over
     * sfcss.css. The builder writes both files itself and only prints a
     * summary, so every run replaced the stylesheet with two lines of log.
     */
    $stylesheet = dirname(__DIR__) . '/public/assets/css/sfcss.css';

    $tests->assertTrue(is_file($stylesheet));

    $head = file_get_contents($stylesheet, false, null, 0, 64);
    $tests->assertTrue(str_starts_with($head, '/* SFCSS'));
    $tests->assertTrue(filesize($stylesheet) > 10000);

    $source = file_get_contents(dirname(__DIR__) . '/src/Console/Application.php');
    $tests->assertSame(false, str_contains($source, "file_put_contents(\$outputPath, \$css)"));
});

$tests->run('request is built from injected arrays, never from globals', function () use ($tests): void {
    /*
     * The constructor taking arrays rather than reading superglobals is what
     * makes a persistent runtime possible later, and what makes the router
     * testable at all.
     */
    $request = Request::create('POST', '/produtos/caf%C3%A9?page=2&sort=name', [
        'body' => ['nome' => 'Ana'],
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer abc123'],
        'rawBody' => '{"extra":"日本語"}',
    ]);

    $tests->assertSame('POST', $request->method);
    $tests->assertSame('/produtos/café', $request->path);
    $tests->assertSame('2', $request->query('page'));
    $tests->assertSame('Ana', $request->body('nome'));
    $tests->assertSame('abc123', $request->bearerToken());
    $tests->assertTrue($request->expectsJson());
    $tests->assertTrue($request->isMethod('post'));

    // Header lookup is case insensitive in both directions.
    $tests->assertSame('application/json', $request->header('CONTENT-TYPE'));

    // input() falls back from body to the JSON payload to the query string.
    $tests->assertSame('日本語', $request->input('extra'));
    $tests->assertSame('name', $request->input('sort'));
    $tests->assertSame('fallback', $request->input('missing', 'fallback'));

    $tests->assertSame(['extra' => '日本語'], $request->json());

    // An encoded separator must not become a real one.
    $tests->assertSame('/a%2Fb', Request::create('GET', '/a%2Fb')->path);
});

$tests->run('request attributes copy on write', function () use ($tests): void {
    $request = Request::create('GET', '/posts/7');

    $withId = $request->withAttribute('id', '7');
    $withMore = $withId->withAttributes(['user' => 'ana']);

    $tests->assertSame(null, $request->attribute('id'));
    $tests->assertSame('7', $withId->route('id'));
    $tests->assertSame(null, $withId->attribute('user'));
    $tests->assertSame(['id' => '7', 'user' => 'ana'], $withMore->attributes());

    // The HTTP fields survive the clone untouched.
    $tests->assertSame($request->method, $withMore->method);
    $tests->assertSame($request->path, $withMore->path);
});

$tests->run('response is a value object that never emits', function () use ($tests): void {
    $json = Response::json(['cidade' => 'São Paulo'], HTTP_CREATED);

    $tests->assertSame(HTTP_CREATED, $json->status());
    $tests->assertSame('application/json; charset=utf-8', $json->header('Content-Type'));

    // UNESCAPED_UNICODE: "São Paulo" must not ship as "São Paulo".
    $tests->assertSame('{"cidade":"São Paulo"}', $json->body());

    $tests->assertSame(HTTP_NO_CONTENT, Response::noContent()->status());
    $tests->assertSame('/login', Response::redirect('/login')->header('Location'));
    $tests->assertSame(HTTP_FOUND, Response::redirect('/login')->status());

    /*
     * Header names are case insensitive, so withHeader() must replace an
     * existing one whatever its casing, or the response would carry
     * Content-Type twice. The stored key is the one the caller wrote.
     */
    $replaced = Response::html('<p>oi</p>')->withHeader('content-type', 'text/plain');
    $tests->assertSame(1, count($replaced->headers()));
    $tests->assertSame('text/plain', $replaced->header('Content-Type'));

    // Every with* method copies rather than mutating.
    $original = Response::html('a');
    $tests->assertSame('b', $original->withBody('b')->body());
    $tests->assertSame('a', $original->body());
    $tests->assertSame(HTTP_NOT_FOUND, $original->withStatus(HTTP_NOT_FOUND)->status());
    $tests->assertSame(HTTP_OK, $original->status());
});

$tests->run('response coerces action return values and refuses null', function () use ($tests): void {
    $response = Response::html('x');
    $tests->assertTrue(Response::from($response) === $response);

    $tests->assertSame('text/html; charset=utf-8', Response::from('<p>oi</p>')->header('Content-Type'));
    $tests->assertSame('{"a":1}', Response::from(['a' => 1])->body());

    /*
     * Returning nothing has to be an error, not an empty 200: it is how an
     * action that forgot its return statement announces itself, and naming
     * the action turns a blank page into a one-line fix.
     */
    $tests->assertThrows(
        fn () => Response::from(null, 'MainController::index()'),
        LogicException::class
    );

    try {
        Response::from(null, 'MainController::index()');
    } catch (LogicException $exception) {
        $tests->assertTrue(str_contains($exception->getMessage(), 'MainController::index()'));
    }
});

$tests->run('views can be rendered to a string without echoing', function () use ($tests): void {
    // Response needs a body it can carry; output that already escaped is no use.
    $rendered = View::makePartial('header', ['title' => '<script>']);

    $tests->assertTrue(str_contains($rendered, '&lt;script&gt;'));
    $tests->assertSame(false, str_contains($rendered, '<script>'));

    $tests->assertThrows(
        fn () => View::make('../../../etc/passwd'),
        InvalidArgumentException::class
    );
});

$tests->finish();
