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
use SfphpProject\src\QueryBuilder;
use SfphpProject\src\Router;
use SfphpProject\src\Validator;
use SfphpProject\src\View;

require __DIR__ . '/TestRunner.php';

final class QueryBuilderStatementTest extends PDOStatement
{
    public string $sql;
    public array $bindings = [];

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
    View::render('home', ['title' => '<script>']);
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

$tests->finish();
