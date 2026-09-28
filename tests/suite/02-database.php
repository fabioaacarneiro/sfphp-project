<?php

/*
 * Database: query builder, schema builder, migrations, models.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Database;
use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Identifier;
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Database\Factory;
use SfphpProject\src\Database\Model;
use SfphpProject\src\Database\Relation;
use SfphpProject\src\Database\Seeder;
use SfphpProject\src\Http\Response;
use SfphpProject\src\QueryBuilder;
use SfphpProject\src\RawQuery;
use SfphpProject\src\Time;
use SfphpProject\src\Migrations\Migration;

$tests->run('query builder binds filtered deletes', function () use ($tests): void {
    $pdo = new QueryBuilderPdoTest();
    (new QueryBuilder($pdo))->from('records')->where('id', 1)->delete();
    $tests->assertSame([':binding_0' => 1], $pdo->statements[0]->bindings);
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
            /*
             * Declaration order, not all columns and then all operations.
             *
             * The grouping this used to assert put CREATE INDEX on `nickname`
             * after the statement renaming that column away, and put a
             * modification of a renamed column before the rename that created
             * it. Both are statements a server refuses.
             */
            'ALTER TABLE `users` ADD COLUMN `nickname` VARCHAR(255) NULL',
            'CREATE INDEX `users_nickname_index` ON `users` (`nickname`)',
            'ALTER TABLE `users` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL',
            'ALTER TABLE `users` ADD CONSTRAINT `users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
            'ALTER TABLE `users` RENAME COLUMN `nickname` TO `display_name`',
            'ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(320) NULL AFTER `name`',
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

$tests->run('raw queries are autoloadable on their own', function () use ($tests): void {
    // RawQuery used to be declared inside QueryBuilder.php, so PSR-4 could
    // not find it and Database::query() was fatal as a first call.
    $tests->assertTrue(class_exists(RawQuery::class));
    $tests->assertSame(
        'src/RawQuery.php',
        str_replace(dirname(dirname(__DIR__)) . '/', '', (new ReflectionClass(RawQuery::class))->getFileName())
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

$tests->run('models hydrate rows into objects', function () use ($tests): void {
    $pdo = new ModelPdoTest([
        'users' => [['id' => 1, 'name' => 'Ana'], ['id' => 2, 'name' => 'Bia']],
    ]);
    Model::useConnection($pdo);

    try {
        $users = UserModelTest::all();

        $tests->assertTrue($users[0] instanceof UserModelTest);
        $tests->assertSame('Ana', $users[0]->name);
        $tests->assertSame(null, $users[0]->naoExiste);
        $tests->assertSame(['id' => 1, 'name' => 'Ana'], $users[0]->toArray());
        $tests->assertTrue($users[0]->exists());

        // JsonSerializable, so a model goes straight into a response — and
        // Response::json() keeps UTF-8 unescaped.
        $tests->assertSame('{"id":1,"name":"Ana"}', Response::json($users[0])->body());

        // The table name is inferred when not declared.
        $tests->assertSame('categories', Category::table());   // y → ies
        $tests->assertSame('boxes', Box::table());             // x → es
        $tests->assertSame('articles', Article::table());      // default
        $tests->assertSame('posts', PostModelTest::table());   // declared wins
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('saving writes only what changed', function () use ($tests): void {
    $pdo = new ModelPdoTest(['users' => [['id' => 1, 'name' => 'Ana', 'email' => 'a@b.co']]]);
    Model::useConnection($pdo);

    try {
        $novo = new UserModelTest(['name' => 'Caio']);
        $tests->assertSame(false, $novo->exists());

        $novo->save();
        $tests->assertTrue($novo->exists());
        // lastInsertId() is a string; the key is an int, as find() returns it.
        $tests->assertSame(99, $novo->id);

        $pdo->queries = [];
        $user = UserModelTest::all()[0];
        $pdo->queries = [];

        $user->name = 'Ana Silva';
        $tests->assertTrue($user->save());

        /*
         * Touching one field must not rewrite every column: the SET clause
         * carries the changed attribute and nothing else.
         */
        preg_match('/SET (.+?) WHERE/', $pdo->queries[0], $set);
        $tests->assertSame('`name` = ?', preg_replace('/:binding_\d+/', '?', $set[1] ?? ''));

        // Nothing changed, so nothing is written.
        $pdo->queries = [];
        $tests->assertSame(false, $user->save());
        $tests->assertSame([], $pdo->queries);
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('with() loads relations in one query instead of N+1', function () use ($tests): void {
    $pdo = new ModelPdoTest([
        'users' => [['id' => 1, 'name' => 'Ana'], ['id' => 2, 'name' => 'Bia']],
        'posts' => [
            ['id' => 10, 'title' => 'Olá', 'user_id' => 1],
            ['id' => 11, 'title' => 'Oi', 'user_id' => 2],
        ],
    ]);
    Model::useConnection($pdo);

    try {
        // Lazy: reading the property resolves the relation on the spot.
        $tests->assertTrue(PostModelTest::all()[0]->author instanceof UserModelTest);
        $tests->assertTrue(is_array(UserModelTest::all()[0]->posts));

        /*
         * Eager: one query for the posts and one for every author, not one
         * author query per post. This is the whole point of the relation
         * metadata being a description rather than a live query.
         */
        $pdo->queries = [];
        $posts = PostModelTest::query()->with('author')->get();

        $tests->assertSame(2, count($pdo->queries));
        $tests->assertTrue(str_contains($pdo->queries[1], 'IN ('));
        $tests->assertSame('Ana', $posts[0]->author->name);

        $before = count($pdo->queries);
        foreach ($posts as $post) {
            $post->author->name;
        }
        $tests->assertSame($before, count($pdo->queries));

        $tests->assertTrue(array_key_exists('author', $posts[0]->toArray()));
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('the query builder stays one call away', function () use ($tests): void {
    $pdo = new ModelPdoTest(['posts' => []]);
    Model::useConnection($pdo);

    try {
        $tests->assertSame(7, PostModelTest::query()->count());
        $tests->assertSame(
            'SELECT * FROM `posts` WHERE `id` = :binding_0',
            PostModelTest::query()->where('id', 1)->toSql()
        );
        $tests->assertTrue(PostModelTest::query()->builder() instanceof QueryBuilder);
        $tests->assertThrows(
            fn () => PostModelTest::findOrFail(999),
            RuntimeException::class
        );
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('casts convert attributes on the way in and out', function () use ($tests): void {
    /*
     * PDO hands back whatever the driver gives it: a DATETIME column arrives
     * as a string and so does a JSON column. Declaring the type means the
     * conversion happens once instead of at every call site.
     */
    $pdo = new ModelPdoTest(['artigos' => [
        [
            'id' => 1,
            'publicado' => '1',
            'meta' => '{"cor":"azul"}',
            'publicado_em' => '2026-09-21 10:30:00',
            'preco' => '19.9',
            'views' => '42',
        ],
        ['id' => 2, 'publicado' => '0', 'meta' => null, 'publicado_em' => null, 'preco' => '5', 'views' => '7'],
    ]]);
    Model::useConnection($pdo);

    try {
        $artigo = ArtigoModelTest::all()[0];

        $tests->assertSame(true, $artigo->publicado);
        $tests->assertSame(false, ArtigoModelTest::all()[1]->publicado);
        $tests->assertSame(['cor' => 'azul'], $artigo->meta);
        $tests->assertSame(42, $artigo->views);
        $tests->assertSame('19.90', $artigo->preco);
        $tests->assertTrue($artigo->publicado_em instanceof DateTimeImmutable);
        $tests->assertSame('2026-09-21 10:30', $artigo->publicado_em->format('Y-m-d H:i'));

        // A null column stays null rather than becoming a zero value.
        $tests->assertSame(null, ArtigoModelTest::all()[1]->publicado_em);

        /*
         * toArray() has to stay JSON-friendly: a DateTimeImmutable encodes as
         * an object full of internal fields, which is not what an API consumer
         * wants, so dates become ISO 8601.
         */
        $array = $artigo->toArray();
        $tests->assertSame(['cor' => 'azul'], $array['meta']);
        $tests->assertSame('2026-09-21T10:30:00+00:00', $array['publicado_em']);
        $tests->assertSame(true, $array['publicado']);

        // getAttribute() stays raw on purpose: relations join on these values.
        $tests->assertSame('1', $artigo->getAttribute('publicado'));
        $tests->assertSame(true, $artigo->cast('publicado'));
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('a decimal is a string, worked out on the digits and never through a float', function () use ($tests): void {
    /*
     * decimal:N used to be round((float) $value) on the way in and
     * number_format((float) $value) on the way out. A float cannot hold 19.99,
     * so 19.99 * 3 came to 59.970000000000006, and money with more digits than
     * a float keeps lost its cents on every save.
     */
    $decimal = static fn (mixed $value, int $scale): string => (new ReflectionMethod(Model::class, 'decimal'))
        ->invoke(null, $value, $scale, 'preco');

    $tests->assertSame('19.90', $decimal('19.9', 2));
    $tests->assertSame('19.99', $decimal(19.99, 2));
    $tests->assertSame('5.00', $decimal(5, 2));

    // Half away from zero, carrying as far as it has to.
    $tests->assertSame('2.35', $decimal('2.345', 2));
    $tests->assertSame('-2.35', $decimal('-2.345', 2));
    $tests->assertSame('10.00', $decimal('9.995', 2));
    $tests->assertSame('3', $decimal('2.5', 0));

    // More digits than a float holds, kept.
    $tests->assertSame('123456789012345678901.24', $decimal('123456789012345678901.235', 2));

    // No negative zero.
    $tests->assertSame('0.00', $decimal('-0.001', 2));

    foreach (['abc', '', '1.2.3', INF] as $wrong) {
        $tests->assertThrows(static fn () => $decimal($wrong, 2), InvalidArgumentException::class);
    }

    // And through the model, both ways: read as a string, stored without a float in between.
    $pdo = new ModelPdoTest(['artigos' => [
        ['id' => 1, 'publicado' => '1', 'meta' => null, 'publicado_em' => null, 'preco' => '1234567890123456.78', 'views' => '1'],
    ]]);
    Model::useConnection($pdo);

    try {
        $artigo = ArtigoModelTest::all()[0];
        $tests->assertSame('1234567890123456.78', $artigo->preco);
        $tests->assertSame('1234567890123456.78', $artigo->toArray()['preco']);

        $artigo->preco = '0.1';
        $tests->assertSame('0.10', $artigo->preco);
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('belongsToMany loads through the pivot in one query', function () use ($tests): void {
    $pdo = new ModelPdoTest([
        'artigos' => [['id' => 1], ['id' => 2]],
        'tags' => [
            ['id' => 7, 'nome' => 'php', '__pivot_key' => 1],
            ['id' => 8, 'nome' => 'web', '__pivot_key' => 1],
            ['id' => 9, 'nome' => 'css', '__pivot_key' => 2],
        ],
    ]);
    Model::useConnection($pdo);

    try {
        $pdo->queries = [];
        $tags = ArtigoModelTest::all()[0]->tags;

        $tests->assertTrue(is_array($tags));
        $tests->assertTrue($tags[0] instanceof TagModelTest);
        $tests->assertTrue(str_contains($pdo->queries[1], 'JOIN `artigo_tag`'));

        /*
         * The pivot column is selected under an alias. Without it there is no
         * way to tell which parent a joined row belongs to, and eager loading
         * through a pivot would have to fall back to one query per parent.
         */
        $tests->assertTrue(str_contains($pdo->queries[1], 'AS `__pivot_key`'));

        $pdo->queries = [];
        $artigos = ArtigoModelTest::query()->with('tags')->get();

        $tests->assertSame(2, count($pdo->queries));
        $tests->assertSame(2, count($artigos[0]->tags));
        $tests->assertSame(1, count($artigos[1]->tags));

        // Grouped by the pivot key, not by the related table's primary key.
        $tests->assertSame('css', $artigos[1]->tags[0]->nome);

        $before = count($pdo->queries);
        foreach ($artigos as $artigo) {
            $artigo->tags;
        }
        $tests->assertSame($before, count($pdo->queries));
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('transactions commit, roll back and preserve the original error', function () use ($tests): void {
    $pdo = new TransactionPdoTest();

    $instance = new ReflectionProperty(Database::class, 'instance');
    $instance->setAccessible(true);
    $previous = $instance->getValue();
    $instance->setValue(null, $pdo);

    try {
        $pdo->calls = [];
        $tests->assertSame('valor', Database::transaction(fn (): string => 'valor'));
        $tests->assertSame(['begin', 'commit'], $pdo->calls);
        $tests->assertSame(false, Database::inTransaction());

        $pdo->calls = [];
        $tests->assertThrows(
            fn () => Database::transaction(function (): void {
                throw new RuntimeException('falhou');
            }),
            RuntimeException::class
        );
        $tests->assertSame(['begin', 'rollback'], $pdo->calls);

        /*
         * The reason this helper is worth having. A failed statement can leave
         * the driver with no active transaction, and PDO::rollBack() then
         * throws "There is no active transaction" — from inside the catch
         * block, replacing the error that actually caused the failure.
         */
        $pdo->calls = [];
        $pdo->rollBackThrows = true;
        $mensagem = null;

        try {
            Database::transaction(function () use ($pdo): void {
                $pdo->closedByDriver();

                throw new RuntimeException('erro real');
            });
        } catch (Throwable $throwable) {
            $mensagem = $throwable->getMessage();
        }

        $tests->assertSame(['begin'], $pdo->calls);
        $tests->assertSame('erro real', $mensagem);
        $pdo->rollBackThrows = false;

        // A nested call joins the transaction already open.
        $pdo->calls = [];
        Database::transaction(function (): void {
            Database::transaction(fn () => null);
        });
        $tests->assertSame(['begin', 'commit'], $pdo->calls);

        // And a failure inside the inner callback rolls the whole thing back.
        $pdo->calls = [];

        try {
            Database::transaction(function (): void {
                Database::transaction(function (): void {
                    throw new RuntimeException('interno');
                });
            });
        } catch (Throwable) {
            // expected
        }

        $tests->assertSame(['begin', 'rollback'], $pdo->calls);
        $tests->assertSame(false, Database::inTransaction());

        /*
         * Even when the outer callback catches the inner failure and carries
         * on: the inner work was already committed along with the outer work,
         * which is the opposite of what a transaction promises.
         */
        $pdo->calls = [];
        $tests->assertThrows(fn () => Database::transaction(function (): void {
            try {
                Database::transaction(function (): void {
                    throw new RuntimeException('interno');
                });
            } catch (RuntimeException) {
                // swallowed on purpose
            }
        }), \SfphpProject\src\Database\NestedTransactionFailed::class);
        $tests->assertSame(['begin', 'rollback'], $pdo->calls);

        // A transaction someone else opened is joined, not begun again.
        $pdo->calls = [];
        $pdo->beginTransaction();
        Database::transaction(fn () => null);
        $tests->assertSame(['begin'], $pdo->calls);
        $pdo->commit();
    } finally {
        $instance->setValue(null, $previous);
    }
});

$tests->run('a naive database value is read as UTC, whatever the server is set to', function () use ($tests): void {
    /*
     * This is the bug the whole scope exists for. A datetime column hands back
     * "2026-09-21 23:00:00" with no zone attached. Reading that with the PHP
     * default zone means the same row is a different instant on a machine set
     * to São Paulo than on one set to UTC — and once a year of rows has been
     * written that way, nothing can repair them, because what they meant was
     * never recorded.
     */
    $original = date_default_timezone_get();

    try {
        foreach (['UTC', 'America/Sao_Paulo', 'Asia/Tokyo'] as $serverZone) {
            date_default_timezone_set($serverZone);

            $parsed = Time::parse('2026-09-21 23:00:00');

            $tests->assertSame('UTC', $parsed->getTimezone()->getName());
            $tests->assertSame('2026-09-21T23:00:00+00:00', $parsed->format(DATE_ATOM));
        }
    } finally {
        date_default_timezone_set($original);
    }

    // A value that carries its own offset keeps its meaning and is converted.
    $tests->assertSame(
        '2026-09-21T08:00:00+00:00',
        Time::parse('2026-09-21T10:00:00+02:00')->format(DATE_ATOM)
    );

    // A naive string can still be read in a stated zone, when one is known.
    $tests->assertSame(
        '2026-09-22T02:00:00+00:00',
        Time::parse('2026-09-21 23:00:00', 'America/Sao_Paulo')->format(DATE_ATOM)
    );

    // A Unix timestamp is already an instant and has no zone to guess.
    $tests->assertSame('2026-09-21T23:00:00+00:00', Time::parse(1790031600)->format(DATE_ATOM));

    $tests->assertSame(null, Time::parse('not a date'));
    $tests->assertSame(null, Time::parse(null));
});

$tests->run('date attributes round-trip through UTC', function () use ($tests): void {
    $article = TimeTestArticle::hydrate([
        'id' => 1,
        'published_at' => '2026-09-21 23:00:00',
        'published_on' => '2026-09-21',
    ]);

    $published = $article->published_at;
    $tests->assertTrue($published instanceof DateTimeImmutable);
    $tests->assertSame('UTC', $published->getTimezone()->getName());
    $tests->assertSame('2026-09-21T23:00:00+00:00', $published->format(DATE_ATOM));

    // JSON carries the offset, so a consumer cannot guess the zone wrongly.
    $tests->assertSame('2026-09-21T23:00:00+00:00', $article->toArray()['published_at']);

    /*
     * Writing a value from another zone stores the instant it names, not the
     * wall clock it was written with: 08:00 in Tokyo is 23:00 the day before
     * in UTC.
     */
    $article->published_at = new DateTimeImmutable('2026-09-22 08:00:00', new DateTimeZone('Asia/Tokyo'));
    $article->published_on = new DateTimeImmutable('2026-09-22 08:00:00', new DateTimeZone('Asia/Tokyo'));

    $forStorage = new ReflectionMethod($article, 'forStorage');
    $forStorage->setAccessible(true);
    $stored = $forStorage->invoke($article, $article->attributes());

    $tests->assertSame('2026-09-21 23:00:00', $stored['published_at']);

    // A date column keeps only the day, and the day is the UTC one.
    $tests->assertSame('2026-09-21', $stored['published_on']);
});

$tests->run('the sessions migration compiles on both dialects', function () use ($tests, $compileSchema): void {
    /*
     * The migration cannot be run here — that needs a real server, which is
     * what tests/db.php is for — but the SQL it produces can be checked, and a
     * session table that only compiles on one dialect is the kind of thing
     * nobody notices until a deploy.
     */
    $blueprint = static function (Blueprint $table): void {
        $table->string('id', 128);
        $table->primary('id');
        $table->text('payload');
        $table->unsignedBigInteger('expires_at');
        $table->index('expires_at');
    };

    foreach (['mysql', 'pgsql'] as $driver) {
        $sql = implode(";\n", $compileSchema($driver, 'create', 'sessions', $blueprint));

        $tests->assertTrue(str_contains($sql, 'sessions'));
        $tests->assertTrue(stripos($sql, 'payload') !== false);
        $tests->assertTrue(stripos($sql, 'expires_at') !== false);

        // An integer deadline, so the comparison never depends on a column's zone.
        $tests->assertTrue(stripos($sql, 'PRIMARY KEY') !== false || stripos($sql, 'primary') !== false);
    }
});

$tests->run('an alter runs in the order it was written', function () use ($tests, $compileSchema): void {
    /*
     * Renaming a column and then modifying it under its new name is how anyone
     * would write it, and how the documentation shows it. The builder used to
     * emit every column statement before every operation, so the modification
     * came first and the server answered "unknown column".
     *
     * No fixed grouping can be right: a rename changes what later statements
     * must call the column, so putting renames first breaks the opposite order
     * just as surely. Declaration order is the only rule that holds both ways.
     */
    $renameThenChange = $compileSchema('mysql', 'alter', 'posts', function (Blueprint $table): void {
        $table->renameColumn('code', 'sku');
        $table->string('sku', 10)->change();
    });

    $tests->assertSame(
        [
            'ALTER TABLE `posts` RENAME COLUMN `code` TO `sku`',
            'ALTER TABLE `posts` MODIFY COLUMN `sku` VARCHAR(10) NOT NULL',
        ],
        $renameThenChange
    );

    // Written the other way round, it comes out the other way round.
    $changeThenRename = $compileSchema('mysql', 'alter', 'posts', function (Blueprint $table): void {
        $table->string('code', 10)->change();
        $table->renameColumn('code', 'sku');
    });

    $tests->assertSame(
        [
            'ALTER TABLE `posts` MODIFY COLUMN `code` VARCHAR(10) NOT NULL',
            'ALTER TABLE `posts` RENAME COLUMN `code` TO `sku`',
        ],
        $changeThenRename
    );

    // An index declared on a column is created before a rename moves it.
    $indexed = $compileSchema('pgsql', 'alter', 'posts', function (Blueprint $table): void {
        $table->string('slug')->index();
        $table->renameColumn('slug', 'handle');
    });

    $tests->assertSame(
        [
            'ALTER TABLE "posts" ADD COLUMN "slug" VARCHAR(255) NOT NULL',
            'CREATE INDEX "posts_slug_index" ON "posts" ("slug")',
            'ALTER TABLE "posts" RENAME COLUMN "slug" TO "handle"',
        ],
        $indexed
    );
});

$tests->run('unique takes its columns like every other index helper', function () use ($tests, $compileSchema): void {
    /*
     * unique() was the one that did not: it took a name as its only argument
     * and always applied to the column being defined, so the composite form
     * the documentation shows was a type error. index(), primary() and
     * fullText() had all taken columns first the whole time.
     */
    $statements = $compileSchema('mysql', 'create', 'users', function (Blueprint $table): void {
        $table->string('email');
        $table->string('tenant_id');
        $table->unique(['email', 'tenant_id']);
    });

    $tests->assertSame(
        'CREATE UNIQUE INDEX `users_email_tenant_id_unique` ON `users` (`email`, `tenant_id`)',
        $statements[1]
    );

    // A name can still be given, now as the second argument.
    $named = $compileSchema('mysql', 'create', 'users', function (Blueprint $table): void {
        $table->string('email');
        $table->unique(['email'], 'by_email');
    });

    $tests->assertSame('CREATE UNIQUE INDEX `by_email` ON `users` (`email`)', $named[1]);

    // And the fluent form on the current column is untouched.
    $fluent = $compileSchema('mysql', 'create', 'users', function (Blueprint $table): void {
        $table->string('email')->unique();
    });

    $tests->assertSame('CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`)', $fluent[1]);
});

$tests->run('an SQLite DB_NAME can be relative, absolute, in the home directory or a URI, and a missing directory is named', function () use ($tests): void {
    /*
     * "~/data/app.sqlite" used to open a folder called ~ inside the project,
     * a relative path inside a file: URI was still the working directory's,
     * and a file in a directory that does not exist failed with SQLite's own
     * "unable to open database file", which names nothing.
     */
    $dsn = static fn (string $name): string => (new ReflectionMethod(Database::class, 'buildDsn'))
        ->invoke(null, 'sqlite', '', null, $name, 'utf8');

    $root = Bootstrap::basePath();
    $home = sys_get_temp_dir() . '/sfphp-home-' . bin2hex(random_bytes(6));
    mkdir($home, 0755, true);
    $previousHome = getenv('HOME');
    putenv('HOME=' . $home);

    try {
        $tests->assertSame('sqlite:' . $root . '/database/app.sqlite', $dsn('database/app.sqlite'));
        $tests->assertSame('sqlite:' . $home . '/app.sqlite', $dsn($home . '/app.sqlite'));
        $tests->assertSame('sqlite:' . $home . '/app.sqlite', $dsn('~/app.sqlite'));
        $tests->assertSame('sqlite::memory:', $dsn(':memory:'));

        // A URI keeps its parameters, and its relative path is the project's too.
        $tests->assertSame('sqlite:file:' . $root . '/database/app.sqlite?mode=ro&cache=shared', $dsn('file:database/app.sqlite?mode=ro&cache=shared'));
        $tests->assertSame('sqlite:file:' . $home . '/app.sqlite?mode=ro', $dsn('file:' . $home . '/app.sqlite?mode=ro'));
        $tests->assertSame('sqlite:file::memory:?cache=shared', $dsn('file::memory:?cache=shared'));

        try {
            $dsn($home . '/missing/app.sqlite');
            $tests->assertSame('an exception', 'none');
        } catch (RuntimeException $e) {
            $tests->assertSame(true, str_contains($e->getMessage(), 'the directory ' . $home . '/missing does not exist'));
        }

        // Nothing was created on the way.
        $tests->assertSame(false, is_dir($home . '/missing'));
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
        exec('rm -rf ' . escapeshellarg($home));
    }
});

$tests->run('a migration reads its own name, and its fields', function () use ($tests): void {
    /*
     * The name is the instruction: create_users creates, add_x_to_users
     * alters, drop_users_table drops. Asking for the table again as a separate
     * argument — which is what make:migration:create did — was a second way to
     * say something already said.
     */
    $draft = new SfphpProject\src\Migrations\MigrationDraft('create_users', [
        'name:string',
        'surname:string:255',
        'email:string:unique',
        'active:boolean:default=true',
        'price:decimal:8,2',
        'bio:text:nullable',
        'author_id:foreignId:constrained',
        'timestamps',
        'softDeletes',
    ]);

    $body = $draft->body();

    $tests->assertSame('create', $draft->action());
    $tests->assertSame('users', $draft->table());

    // A table being created gets a key whether or not one was asked for.
    $tests->assertTrue(str_contains($body, '$table->id();'));

    // Numbers after the type are its arguments; words are modifiers.
    $tests->assertTrue(str_contains($body, "\$table->string('surname', 255);"));
    $tests->assertTrue(str_contains($body, "\$table->string('email')->unique();"));
    $tests->assertTrue(str_contains($body, "\$table->boolean('active')->default(true);"));
    $tests->assertTrue(str_contains($body, "\$table->decimal('price', 8, 2);"));
    $tests->assertTrue(str_contains($body, "\$table->foreignId('author_id')->constrained();"));

    // A bare word is a call with no column name of its own.
    $tests->assertTrue(str_contains($body, '$table->timestamps();'));
    $tests->assertTrue(str_contains($body, '$table->softDeletes();'));

    $tests->assertTrue(str_contains($body, "\$schema->dropIfExists('users');"));

    // An alter says what it added, so down() can take it away again.
    $alter = new SfphpProject\src\Migrations\MigrationDraft('add_phone_to_users', ['phone:string:nullable']);

    $tests->assertSame('table', $alter->action());
    $tests->assertSame('users', $alter->table());
    $tests->assertTrue(str_contains($alter->body(), "\$schema->table('users'"));
    $tests->assertTrue(str_contains($alter->body(), "\$table->dropColumn(['phone']);"));

    // Dropping is dropping, whichever verb the name used.
    foreach (['drop_users_table', 'delete_users_table', 'remove_users'] as $name) {
        $drop = new SfphpProject\src\Migrations\MigrationDraft($name);

        $tests->assertSame('drop', $drop->action());
        $tests->assertSame('users', $drop->table());
        $tests->assertTrue(str_contains($drop->body(), "\$schema->dropIfExists('users');"));
    }

    // A name that says nothing about a table still produces a usable file.
    $plain = new SfphpProject\src\Migrations\MigrationDraft('backfill_totals');
    $tests->assertSame('plain', $plain->action());
    $tests->assertSame(null, $plain->table());
    $tests->assertTrue(str_contains($plain->body(), 'public function up(Schema $schema): void'));

    /*
     * A wrong type is refused with the right one, because varchar is what
     * everybody types first — and refused before anything is written, so a
     * typo in the fourth column does not leave half a migration behind.
     */
    $tests->assertThrows(
        static fn () => (new SfphpProject\src\Migrations\MigrationDraft('create_posts', ['title:varchar:255']))->body(),
        InvalidArgumentException::class
    );

    $tests->assertThrows(
        static fn () => (new SfphpProject\src\Migrations\MigrationDraft('create_posts', ['title:string:nulable']))->body(),
        InvalidArgumentException::class
    );

    $tests->assertThrows(
        static fn () => (new SfphpProject\src\Migrations\MigrationDraft('create_posts', ['title']))->body(),
        InvalidArgumentException::class
    );

    // Every file it writes is PHP.
    $file = sys_get_temp_dir() . '/sfphp-draft-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, $body);

    try {
        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        $tests->assertSame(0, $status);
    } finally {
        @unlink($file);
    }
});

$tests->run('a model keeps its secrets out of JSON and never runs a method because a property was read', function () use ($tests): void {
    eval('namespace SfphpTest\Hidden; final class Account extends \SfphpProject\src\Database\Model {
        protected static string $table = "users";
        protected static array $fillable = ["name"];
        protected static array $hidden = ["password"];
        public function wipe(): bool { throw new \RuntimeException("wipe ran"); }
    }');

    $account = \SfphpTest\Hidden\Account::hydrate(['id' => 1, 'name' => 'Ana', 'password' => '$2y$hash', \SfphpProject\src\Database\Model::PIVOT_KEY => 7]);

    $tests->assertSame(['id' => 1, 'name' => 'Ana'], $account->toArray());
    $tests->assertSame('{"id":1,"name":"Ana"}', json_encode($account));
    $tests->assertSame('$2y$hash', $account->password);

    // Reading $model->delete used to run delete(); only Relation methods are called.
    $tests->assertSame(null, $account->wipe);
    $tests->assertSame(null, $account->delete);
    $tests->assertTrue($account->exists());
});

$tests->run('an update is addressed by the key the row had, and timestamps are kept when asked for', function () use ($tests): void {
    eval('namespace SfphpTest\Stamped; final class Note extends \SfphpProject\src\Database\Model {
        protected static string $table = "notes";
        protected static array $fillable = ["body"];
        protected static bool $timestamps = true;
    }');

    $pdo = new ModelPdoTest(['notes' => [['id' => 10, 'body' => 'a']]]);
    Model::useConnection($pdo);
    Time::freeze('2026-09-25 12:00:00');

    try {
        $note = \SfphpTest\Stamped\Note::find(10);
        $pdo->queries = [];
        ModelStatementTest::$bound = [];
        $note->id = 11;
        $note->save();

        $tests->assertTrue(str_contains($pdo->queries[0], 'UPDATE `notes` SET'));
        $tests->assertTrue(str_contains($pdo->queries[0], '`updated_at`'));
        // WHERE id = 10, SET id = 11: the row changed is the one that was read.
        $tests->assertSame([10, 11, '2026-09-25 12:00:00'], ModelStatementTest::$bound);

        $pdo->queries = [];
        $created = \SfphpTest\Stamped\Note::create(['body' => 'b']);
        $tests->assertTrue(str_contains($pdo->queries[0], '`created_at`'));
        $tests->assertSame('2026-09-25 12:00:00', $created->getAttribute('updated_at'));
    } finally {
        Time::unfreeze();
        Model::useConnection(null);
    }
});

$tests->run('a factory builds each row afresh and saves columns outside $fillable', function () use ($tests): void {
    $pdo = new ModelPdoTest();
    Model::useConnection($pdo);
    ModelStatementTest::$bound = [];

    try {
        $users = (new \Database\Factories\UserFactory())->count(3)->create(['kind' => 'key', 'n' => fn ($f, int $i): int => $i]);

        $tests->assertSame(3, count($users));
        $tests->assertSame(3, count(array_filter($pdo->queries, fn (string $q): bool => str_starts_with($q, 'INSERT'))));

        // The password is not fillable on User, and it is saved all the same.
        $tests->assertTrue(str_contains($pdo->queries[0], '`password`'));

        $emails = array_map(fn ($u) => $u->getAttribute('email'), $users);
        $tests->assertSame(3, count(array_unique($emails)));

        // "key" names a PHP function and stays a string; a closure gets the row index.
        $tests->assertSame('key', $users[0]->getAttribute('kind'));
        $tests->assertSame([0, 1, 2], array_map(fn ($u) => $u->getAttribute('n'), $users));

        $one = (new \Database\Factories\UserFactory())->make();
        $tests->assertTrue(isset($one['email']));
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('with() refuses a relation that does not exist instead of falling back to one query per row', function () use ($tests): void {
    Model::useConnection(new ModelPdoTest());

    try {
        $tests->assertThrows(fn () => PostModelTest::query()->with('autor'), InvalidArgumentException::class);
        $tests->assertThrows(fn () => PostModelTest::query()->with('delete'), InvalidArgumentException::class);
        PostModelTest::query()->with('author');
    } finally {
        Model::useConnection(null);
    }
});

$tests->run('a closure groups conditions in parentheses, so an OR stays inside its group', function () use ($tests): void {
    $pdo = new ModelPdoTest();
    $builder = (new QueryBuilder($pdo))->from('posts')
        ->where('user_id', 7)
        ->where(function (QueryBuilder $q): void {
            $q->where('status', 'draft')->orWhere('status', 'review');
        });
    $builder->get();

    $tests->assertTrue(str_contains(end($pdo->queries), 'WHERE `user_id` = :binding_0 AND (`status` = :binding_1 OR `status` = :binding_2)'));

    // An offset without a limit is valid SQL on MySQL.
    $pdo->queries = [];
    (new QueryBuilder($pdo))->from('posts')->offset(5)->get();
    $tests->assertTrue(str_contains(end($pdo->queries), 'LIMIT 18446744073709551615 OFFSET 5'));
});
