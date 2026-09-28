<?php

/*
 * SQLite, for real.
 *
 * Everything else in the suite that touches a database does it through a fake
 * PDO, and tests/db.php checks the schema builder against MySQL and PostgreSQL
 * servers — so nothing ever ran a migration, a schema change or a query against
 * SQLite, the database a new project starts on. This does, in memory, wherever
 * pdo_sqlite is installed; CI has it.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;
use SfphpProject\src\QueryBuilder;

$sqlite = static function (): ?PDO {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }

    return new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};

$tests->run('SQLite creates and alters a table the schema builder describes', function () use ($tests, $sqlite): void {
    $pdo = $sqlite();

    if ($pdo === null) {
        return;
    }

    $schema = new Schema($pdo);

    $schema->create('posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title', 100);
        $table->integer('views')->default(0);
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    $tests->assertSame(true, $schema->hasTable('posts'));
    $tests->assertSame(true, $schema->hasColumn('posts', 'views'));

    $schema->table('posts', function (Blueprint $table): void {
        $table->string('slug')->nullable();
    });

    $tests->assertSame(true, $schema->hasColumn('posts', 'slug'));
    $tests->assertSame(false, $schema->hasColumn('posts', 'nothing'));

    $schema->table('posts', function (Blueprint $table): void {
        $table->index('title');
    });

    $tests->assertSame(true, $schema->hasIndex('posts', 'posts_title_index'));
    $tests->assertSame(false, $schema->hasIndex('posts', 'posts_nothing_index'));

    // A default the database applies, not one the query sent.
    $pdo->exec("INSERT INTO posts (title) VALUES ('draft')");
    $tests->assertSame(0, (int) $pdo->query('SELECT views FROM posts')->fetchColumn());

    $schema->dropIfExists('posts');
    $tests->assertSame(false, $schema->hasTable('posts'));
});

$tests->run('SQLite answers the query builder: insert, filters, order, limit, offset, update and delete', function () use ($tests, $sqlite): void {
    $pdo = $sqlite();

    if ($pdo === null) {
        return;
    }

    (new Schema($pdo))->create('posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->integer('views')->default(0);
        $table->string('slug')->nullable();
    });

    $posts = static fn (): QueryBuilder => (new QueryBuilder($pdo))->from('posts');

    $first = $posts()->insert(['title' => 'a', 'views' => 1]);
    $posts()->insert(['title' => 'b', 'views' => 2, 'slug' => 'b']);
    $posts()->insert(['title' => 'c', 'views' => 3, 'slug' => 'c']);

    // The key comes back from the database that made it.
    $tests->assertSame('1', $first);
    $tests->assertSame(3, $posts()->count());
    $tests->assertSame(2, $posts()->where('views', '>', 1)->count());
    $tests->assertSame(['a'], array_column($posts()->whereNull('slug')->get(), 'title'));
    $tests->assertSame(['a', 'c'], array_column($posts()->whereIn('title', ['a', 'c'])->orderBy('title')->get(), 'title'));

    // LIMIT with OFFSET, and OFFSET alone, which SQLite only takes after a LIMIT.
    $tests->assertSame(['b'], array_column($posts()->orderBy('views', 'DESC')->limit(1)->offset(1)->get(), 'title'));
    $tests->assertSame(['b', 'c'], array_column($posts()->orderBy('views')->offset(1)->get(), 'title'));

    $tests->assertSame(1, $posts()->where('title', '=', 'a')->update(['views' => 10]));
    $tests->assertSame(10, (int) $posts()->where('title', '=', 'a')->first()['views']);

    $tests->assertSame(1, $posts()->where('title', '=', 'c')->delete());
    $tests->assertSame(2, $posts()->count());
});

$tests->run('SQLite runs migrations from files and rolls them back', function () use ($tests, $sqlite): void {
    $pdo = $sqlite();

    if ($pdo === null) {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-sqlite-migrations-' . bin2hex(random_bytes(6));
    mkdir($directory, 0755, true);

    try {
        writeMigrationFixture($directory, '2026_09_19_120000_create_users_table.php', 'users');
        writeMigrationFixture($directory, '2026_09_19_120001_create_posts_table.php', 'posts');

        $runner = new MigrationRunner($pdo, $directory);
        $schema = new Schema($pdo);

        $tests->assertSame(
            ['2026_09_19_120000_create_users_table.php', '2026_09_19_120001_create_posts_table.php'],
            $runner->migrate()
        );
        $tests->assertSame(true, $schema->hasTable('users') && $schema->hasTable('posts'));

        // Nothing left to run the second time.
        $tests->assertSame([], $runner->migrate());

        $tests->assertSame(['2026_09_19_120001_create_posts_table.php'], $runner->rollback(1));
        $tests->assertSame(true, $schema->hasTable('users'));
        $tests->assertSame(false, $schema->hasTable('posts'));
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
});
