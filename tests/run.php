<?php

require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Csrf;
use SfphpProject\src\Database;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Container;
use SfphpProject\src\JWT;
use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Identifier;
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Log\ErrorLogDriver;
use SfphpProject\src\Log\Level;
use SfphpProject\src\Log\LogManager;
use SfphpProject\src\Log\MemoryDriver as LogMemoryDriver;
use SfphpProject\src\Log\NullDriver as LogNullDriver;
use SfphpProject\src\Log\StreamDriver;
use SfphpProject\src\Http\Middleware\LogRequests;
use SfphpProject\src\Console\Application;
use SfphpProject\src\Cache\FileDriver;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Auth\Auth;
use SfphpProject\src\Auth\Authenticatable;
use SfphpProject\src\Auth\AuthorizationException;
use SfphpProject\src\Auth\Gate;
use SfphpProject\src\Auth\Hash;
use SfphpProject\src\Auth\ModelUserProvider;
use SfphpProject\src\Auth\SessionGuard;
use SfphpProject\src\Auth\TokenGuard;
use SfphpProject\src\Database\Factory;
use SfphpProject\src\Database\MassAssignmentException;
use SfphpProject\src\Database\Model;
use SfphpProject\src\Database\Relation;
use SfphpProject\src\Database\Seeder;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Middleware\Authenticate;
use SfphpProject\src\Http\Middleware\RateLimit;
use SfphpProject\src\Http\Middleware\SecurityHeaders;
use SfphpProject\src\Http\Middleware\SetLocale;
use SfphpProject\src\Http\Middleware\VerifyCsrfToken;
use SfphpProject\src\I18n\Translator;
use SfphpProject\src\Http\Pipeline;
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

/*
 * The suite exercises failing requests on purpose, and every one of them is now
 * reported by the router. Sending those to stderr would bury the test output in
 * stack traces, so the shared logger is silenced here; the tests that assert on
 * records swap in a driver of their own and put this one back afterwards.
 */
logger()->driver(new LogNullDriver());

/**
 * Controller used by the end-to-end dispatch tests.
 *
 * It lives in the global namespace and the router is pointed at it with an
 * empty controller namespace — the same seam an application uses to put its
 * controllers wherever it likes.
 */
final class DispatchTestController
{
    public function home(Request $request): Response
    {
        return Response::text('home');
    }

    public function show(Request $request, string $id): Response
    {
        // Proves the parameter arrives positionally and on the request.
        return Response::text(
            $request->query('from') === 'route'
                ? 'route:' . $request->route('id')
                : 'post:' . $id
        );
    }

    public function store(Request $request): Response
    {
        return Response::json(['title' => $request->body('title')], HTTP_CREATED);
    }

    public function trail(Request $request): Response
    {
        return Response::text((string) $request->attribute('trail'));
    }

    public function boom(Request $request): Response
    {
        throw new RuntimeException('a acao falhou');
    }

    /**
     * Deliberately returns nothing, to prove the dispatcher refuses it.
     */
    public function returnsNothing(Request $request)
    {
    }
}

/**
 * A PDO that answers from canned tables, so the model layer can be tested
 * without a database driver.
 */
final class ModelStatementTest extends PDOStatement
{
    public string $sql = '';
    public array $rows = [];

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->rows[0] ?? false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}

final class ModelPdoTest extends PDO
{
    public array $queries = [];

    public function __construct(private array $tables = []) {}

    public function getAttribute(int $attribute): mixed
    {
        return 'mysql';
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;

        $statement = new ModelStatementTest();
        $statement->sql = $query;

        if (str_contains($query, 'COUNT(*)')) {
            $statement->rows = [['aggregate' => 7]];

            return $statement;
        }

        if (preg_match('/FROM `(\w+)`/', $query, $matches) === 1) {
            $statement->rows = $this->tables[$matches[1]] ?? [];
        }

        return $statement;
    }

    public function lastInsertId(?string $name = null): string
    {
        return '99';
    }
}

final class UserModelTest extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email'];

    public function posts(): Relation
    {
        return $this->hasMany(PostModelTest::class, 'user_id');
    }
}

final class PostModelTest extends Model
{
    protected static string $table = 'posts';

    public function author(): Relation
    {
        return $this->belongsTo(UserModelTest::class, 'user_id');
    }
}

/**
 * A user for the authentication tests, backed by the fake PDO.
 */
final class AuthUserTest extends Model implements Authenticatable
{
    protected static string $table = 'users';

    protected static array $fillable = ['name', 'email', 'password'];

    public function getAuthIdentifierName(): string
    {
        return static::primaryKey();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getAttribute(static::primaryKey());
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password');
    }
}

/** Declares no $fillable, so it cannot be mass assigned at all. */
final class UnguardedModelTest extends Model
{
    protected static string $table = 'unguarded';
}

/** A subject for the authorization tests. */
final class AuthPostTest
{
    public function __construct(public int $user_id) {}
}

final class AuthPostPolicyTest
{
    public function update(?object $user, AuthPostTest $post): bool
    {
        return $user !== null && $user->getAuthIdentifier() === $post->user_id;
    }

    /** Anonymous reads are allowed, which is why a policy receives null. */
    public function view(?object $user, AuthPostTest $post): bool
    {
        return true;
    }
}

final class AuthControllerTest
{
    public function open(Request $request): Response
    {
        return Response::text($request->user() === null ? 'anonimo' : 'logado');
    }

    public function secret(Request $request): Response
    {
        return Response::text('secreto:' . $request->user()->getAuthIdentifier());
    }
}

final class TagModelTest extends Model
{
    protected static string $table = 'tags';
}

final class ArtigoModelTest extends Model
{
    protected static string $table = 'artigos';

    protected static array $casts = [
        'publicado' => 'bool',
        'meta' => 'json',
        'publicado_em' => 'datetime',
        'preco' => 'decimal:2',
        'views' => 'int',
    ];

    public function tags(): Relation
    {
        return $this->belongsToMany(TagModelTest::class, 'artigo_tag', 'artigo_id', 'tag_id');
    }
}

/**
 * A PDO that records the transaction calls it receives, and can pretend the
 * driver closed the transaction on its own.
 */
final class TransactionPdoTest extends PDO
{
    public array $calls = [];
    public bool $rollBackThrows = false;

    private bool $active = false;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        $this->calls[] = 'begin';
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollback';

        if ($this->rollBackThrows) {
            throw new PDOException('There is no active transaction');
        }

        $this->active = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    /** Simulate a failed statement leaving the driver with no transaction. */
    public function closedByDriver(): void
    {
        $this->active = false;
    }
}

/*
 * No $table on these three, so the table name is inferred from the class
 * name. The rules are deliberately simple and a model with an irregular name
 * is expected to declare $table instead.
 */
final class Category extends Model {}
final class Box extends Model {}
final class Article extends Model {}

/**
 * Middleware resolved by class name, to prove container resolution works.
 */
final class StampMiddlewareTest implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Stamp', 'sfphp');
    }
}

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

$tests->run('requests accept redirected bearer tokens', function () use ($tests): void {
    /*
     * Apache hands the Authorization header over as REDIRECT_HTTP_* once a
     * rewrite has run, so a token would be invisible without this. Header
     * normalisation moved from BaseAPIController to Request.
     */
    $server = $_SERVER;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-token'];

    try {
        $tests->assertSame('test-token', Request::fromGlobals()->bearerToken());
    } finally {
        $_SERVER = $server;
    }
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

    // Path decoding belongs to the request now, not to the router.
    $tests->assertSame('/produtos/café', Request::create('GET', '/produtos/caf%C3%A9')->path);

    // An encoded separator must not become a real one, or "/a%2Fb" would
    // reach a route registered as "/a/b".
    $tests->assertSame('/a%2Fb', Request::create('GET', '/a%2Fb')->path);
    $tests->assertSame('/a%5Cb', Request::create('GET', '/a%5Cb')->path);
});

$tests->run('the framework error page makes no external requests', function () use ($tests): void {
    // No reflection and no output buffer: the error page is a Response now.
    $response = (new Router(new Container()))->dispatch(Request::create('GET', '/rota-que-nao-existe'));

    $tests->assertSame(HTTP_NOT_FOUND, $response->status());
    $tests->assertSame(0, preg_match_all('#https?://#', $response->body()));
    $tests->assertTrue(str_contains($response->body(), '<style>'));
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

$tests->run('the pipeline runs middleware in order and unwinds in reverse', function () use ($tests): void {
    $trace = [];

    $stage = static function (string $label) use (&$trace): callable {
        return static function (Request $request, callable $next) use ($label, &$trace): Response {
            $trace[] = "entra:$label";
            $response = $next($request);
            $trace[] = "sai:$label";

            return $response;
        };
    };

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$stage('a'), $stage('b')],
        function (Request $request) use (&$trace): Response {
            $trace[] = 'action';

            return Response::text('ok');
        }
    );

    $tests->assertSame('ok', $response->body());
    $tests->assertSame(
        ['entra:a', 'entra:b', 'action', 'sai:b', 'sai:a'],
        $trace
    );
});

$tests->run('middleware can replace the request and short-circuit the pipeline', function () use ($tests): void {
    $reached = false;

    // A stage may hand a modified request down the chain.
    $attach = static fn (Request $request, callable $next): Response
        => $next($request->withAttribute('user', 'ana'));

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$attach],
        static fn (Request $request): Response => Response::text((string) $request->attribute('user'))
    );

    $tests->assertSame('ana', $response->body());

    // A stage that returns without calling $next stops everything after it.
    $deny = static fn (Request $request, callable $next): Response
        => Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED);

    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [$deny],
        function (Request $request) use (&$reached): Response {
            $reached = true;

            return Response::text('nunca');
        }
    );

    $tests->assertSame(HTTP_UNAUTHORIZED, $response->status());
    $tests->assertSame(false, $reached);
});

$tests->run('the pipeline resolves middleware class names through the container', function () use ($tests): void {
    // Naming a class lets routes declare middleware before any instance
    // exists, and lets the middleware constructor-inject its dependencies.
    $response = (new Pipeline(new Container()))->run(
        Request::create('GET', '/'),
        [StampMiddlewareTest::class],
        static fn (Request $request): Response => Response::text('corpo')
    );

    $tests->assertSame('sfphp', $response->header('X-Stamp'));
    $tests->assertSame('corpo', $response->body());

    $tests->assertThrows(
        fn () => (new Pipeline(new Container()))->run(
            Request::create('GET', '/'),
            ['NaoExisteMiddleware'],
            static fn (Request $request): Response => Response::text('x')
        ),
        RuntimeException::class
    );
});

$tests->run('a middleware that forgets to return fails where the mistake is', function () use ($tests): void {
    /*
     * Without the explicit check the null travels several frames before
     * failing as "call to a member function on null", pointing at the
     * pipeline rather than at the middleware that caused it.
     */
    $forgets = static function (Request $request, callable $next) {
        $next($request);
    };

    $tests->assertThrows(
        fn () => (new Pipeline(new Container()))->run(
            Request::create('GET', '/'),
            [$forgets],
            static fn (Request $request): Response => Response::text('x')
        ),
        RuntimeException::class
    );
});

$tests->run('error responses are rendered, negotiated and redacted', function () use ($tests): void {
    /*
     * The first assertions ever written for ErrorHandler. They were impossible
     * before: the class echoed and called exit, so there was nothing to
     * inspect.
     */
    $throwable = new RuntimeException('connection to 10.0.0.5 failed for user root');

    $html = ErrorHandler::toResponse($throwable);
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $html->status());
    $tests->assertSame('text/html; charset=utf-8', $html->header('Content-Type'));

    $json = ErrorHandler::toResponse(
        $throwable,
        Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']])
    );
    $tests->assertSame('application/json; charset=utf-8', $json->header('Content-Type'));

    /*
     * Outside development the driver message must not reach the client: it
     * carries the host, the database and the user.
     */
    if (APP_ENV !== 'development') {
        $tests->assertSame('{"message":"Internal Server Error"}', $json->body());
        $tests->assertSame(false, str_contains($html->body(), '10.0.0.5'));
    }
});

/*
 * End-to-end dispatch. These tests come last because Router::reset() throws
 * away the routes the earlier named-route tests registered.
 *
 * The controllers live in the global namespace and the router is pointed at it
 * with an empty prefix, which is the same seam that lets an application choose
 * its own namespace.
 */
Router::reset();

$tests->run('dispatch turns a request into a response through a controller', function () use ($tests): void {
    Router::reset();
    Router::get('/', 'DispatchTestController', 'home');
    Router::get('/posts/id:number', 'DispatchTestController', 'show');
    Router::post('/posts', 'DispatchTestController', 'store');

    $router = new Router(new Container(), '');

    $home = $router->dispatch(Request::create('GET', '/'));
    $tests->assertSame(HTTP_OK, $home->status());
    $tests->assertSame('home', $home->body());

    // Route parameters arrive positionally, after the request.
    $show = $router->dispatch(Request::create('GET', '/posts/42'));
    $tests->assertSame('post:42', $show->body());

    // And they are also readable from the request.
    $tests->assertSame('route:42', $router->dispatch(
        Request::create('GET', '/posts/42', ['query' => ['from' => 'route']])
    )->body());

    $store = $router->dispatch(Request::create('POST', '/posts', ['body' => ['title' => 'Olá']]));
    $tests->assertSame(HTTP_CREATED, $store->status());
    $tests->assertSame('{"title":"Olá"}', $store->body());
});

$tests->run('dispatch answers 404, 405 and OPTIONS with the right headers', function () use ($tests): void {
    Router::reset();
    Router::get('/posts', 'DispatchTestController', 'home');
    Router::delete('/posts', 'DispatchTestController', 'home');

    $router = new Router(new Container(), '');

    $tests->assertSame(HTTP_NOT_FOUND, $router->dispatch(Request::create('GET', '/nada'))->status());

    /*
     * Allow used to be emitted once with header() before the 405 and OPTIONS
     * branches split, so both inherited it. A returned response carries only
     * what it was handed, so both must set it explicitly.
     */
    $notAllowed = $router->dispatch(Request::create('PUT', '/posts'));
    $tests->assertSame(HTTP_METHOD_NOT_ALLOWED, $notAllowed->status());
    $tests->assertSame('GET, DELETE', $notAllowed->header('Allow'));

    $options = $router->dispatch(Request::create('OPTIONS', '/posts'));
    $tests->assertSame(HTTP_NO_CONTENT, $options->status());
    $tests->assertSame('GET, DELETE', $options->header('Allow'));
    $tests->assertSame('', $options->body());
});

$tests->run('middleware runs global first, then group, then route', function () use ($tests): void {
    Router::reset();

    $stamp = static fn (string $label): callable
        => static fn (Request $request, callable $next): Response
            => $next($request->withAttribute(
                'trail',
                trim(((string) $request->attribute('trail', '')) . ' ' . $label)
            ));

    Router::group('/admin', function () use ($stamp): void {
        Router::get('/panel', 'DispatchTestController', 'trail')
            ->middleware($stamp('route'));
    }, 'admin.', [$stamp('group')]);

    $router = (new Router(new Container(), ''))->middleware($stamp('global'));

    $tests->assertSame(
        'global group route',
        $router->dispatch(Request::create('GET', '/admin/panel'))->body()
    );
});

$tests->run('middleware can refuse a request before the controller runs', function () use ($tests): void {
    Router::reset();
    Router::get('/private', 'DispatchTestController', 'home');

    $deny = static fn (Request $request, callable $next): Response
        => $request->bearerToken() === null
            ? Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED)
            : $next($request);

    $router = (new Router(new Container(), ''))->middleware($deny);

    $refused = $router->dispatch(Request::create('GET', '/private'));
    $tests->assertSame(HTTP_UNAUTHORIZED, $refused->status());
    $tests->assertSame('{"message":"Unauthorized"}', $refused->body());

    $allowed = $router->dispatch(Request::create('GET', '/private', [
        'headers' => ['Authorization' => 'Bearer token'],
    ]));
    $tests->assertSame(HTTP_OK, $allowed->status());
});

$tests->run('global middleware also wraps requests that match no route', function () use ($tests): void {
    // CORS headers and request logging that skip 404s are a bug.
    Router::reset();

    $router = (new Router(new Container(), ''))->middleware(
        static fn (Request $request, callable $next): Response
            => $next($request)->withHeader('X-Served-By', 'sfphp')
    );

    $response = $router->dispatch(Request::create('GET', '/nada'));

    $tests->assertSame(HTTP_NOT_FOUND, $response->status());
    $tests->assertSame('sfphp', $response->header('X-Served-By'));
});

$tests->run('a failing action becomes a 500 instead of a blank page', function () use ($tests): void {
    Router::reset();
    Router::get('/boom', 'DispatchTestController', 'boom');
    Router::get('/silent', 'DispatchTestController', 'returnsNothing');
    Router::get('/missing', 'DispatchTestController', 'naoExiste');

    $router = new Router(new Container(), '');

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/boom'))->status());

    /*
     * An action that returns nothing is the "forgot the return statement"
     * bug. It has to fail loudly rather than serve an empty 200.
     */
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/silent'))->status());

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $router->dispatch(Request::create('GET', '/missing'))->status());
});

$tests->run('csrf verification finally applies by default', function () use ($tests): void {
    /*
     * Csrf has had tokens, hash_equals and the form helpers for a long time,
     * but nothing in the framework ever called the verification: every
     * application had to remember to do it in each action, and forgetting
     * produced no error at all. The pipeline is the first place the check can
     * apply by default.
     */
    Router::reset();
    Router::get('/form', 'DispatchTestController', 'home');
    Router::post('/form', 'DispatchTestController', 'home');

    Csrf::startSession();
    $token = Csrf::token();

    $router = (new Router(new Container(), ''))->middleware(VerifyCsrfToken::class);

    // Safe methods are never blocked.
    $tests->assertSame(HTTP_OK, $router->dispatch(Request::create('GET', '/form'))->status());

    // A state-changing request without a token is refused.
    $tests->assertSame(
        HTTP_FORBIDDEN,
        $router->dispatch(Request::create('POST', '/form'))->status()
    );

    // With the right token in the field, it passes.
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['body' => ['_token' => $token]])
    )->status());

    // And with the token in the header, as an AJAX call sends it.
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['headers' => ['X-CSRF-Token' => $token]])
    )->status());

    // A wrong token is refused, and the refusal is negotiated.
    $refused = $router->dispatch(Request::create('POST', '/form', [
        'body' => ['_token' => 'errado'],
        'headers' => ['Accept' => 'application/json'],
    ]));
    $tests->assertSame(HTTP_FORBIDDEN, $refused->status());
    $tests->assertSame('application/json; charset=utf-8', $refused->header('Content-Type'));

    /*
     * A bearer token is attached by the client on purpose; a browser never
     * sends one by itself, so there is no cross-site request to forge.
     */
    $tests->assertSame(HTTP_OK, $router->dispatch(
        Request::create('POST', '/form', ['headers' => ['Authorization' => 'Bearer abc']])
    )->status());

    // Exempt prefixes let a token-authenticated API opt out.
    $exempt = (new Router(new Container(), ''))->middleware(new VerifyCsrfToken(['/form']));
    $tests->assertSame(HTTP_OK, $exempt->dispatch(Request::create('POST', '/form'))->status());
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
        $tests->assertSame('99', $novo->id);

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

$tests->run('every generator produces a class that actually loads', function () use ($tests): void {
    /*
     * getNamespace() used to ucfirst() the directory, so a file written to
     * app/models declared SfphpProject\app\Models and PSR-4 looked for
     * app/Models on a case-sensitive filesystem. Every generator was affected,
     * and a generated controller could not even be found by the router, which
     * looks for the lower-case namespace.
     */
    $root = dirname(__DIR__);
    $generators = [
        'controller' => 'app/controllers/GenProbeController.php',
        'model' => 'app/models/GenProbe.php',
        'repository' => 'app/repositories/GenProbeRepository.php',
        'service' => 'app/services/GenProbeService.php',
        'request' => 'app/requests/GenProbeRequest.php',
        'policy' => 'app/policies/GenProbePolicy.php',
        'event' => 'app/events/GenProbeEvent.php',
        'listener' => 'app/listeners/GenProbeListener.php',
        'middleware' => 'app/middleware/GenProbeMiddleware.php',
    ];

    $created = [];

    try {
        foreach ($generators as $command => $relative) {
            (new Application(['sfphp', "make:$command", 'GenProbe']))->run();

            $path = $root . '/' . $relative;
            $tests->assertTrue(is_file($path));
            $created[] = $path;

            $source = file_get_contents($path);
            preg_match('/^namespace (.+);/m', $source, $namespace);
            preg_match('/(?:final )?class (\w+)/', $source, $class);

            $fqcn = $namespace[1] . '\\' . $class[1];

            require_once $path;
            $tests->assertTrue(class_exists($fqcn, false));
        }
    } finally {
        foreach ($created as $path) {
            @unlink($path);
        }

        foreach (array_unique(array_map(
            static fn (string $relative): string => $root . '/' . dirname($relative),
            $generators
        )) as $directory) {
            if (is_dir($directory) && (glob($directory . '/*') ?: []) === []) {
                @rmdir($directory);
            }
        }
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
        $tests->assertSame(19.9, $artigo->preco);
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
            Database::transaction(fn (): null => null);
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
    } finally {
        $instance->setValue(null, $previous);
    }
});

$tests->run('translations resolve, fall back and interpolate', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('en');
        $tests->assertSame('404 - Page Not Found', __('http.not_found_title'));

        Translator::setLocale('pt-BR');
        $tests->assertSame('pt_BR', Translator::locale());   // normalizado
        $tests->assertSame('404 - Página Não Encontrada', __('http.not_found_title'));

        // A aplicação sobrescreve o catálogo do framework, chave a chave.
        $tests->assertSame('Bem-vindo, Ana!', __('app.welcome', ['name' => 'Ana']));

        /*
         * Uma chave sem tradução volta como está, para que a falta apareça
         * onde ela é, em vez de renderizar vazio.
         */
        $tests->assertSame('nao.existe.chave', __('nao.existe.chave'));
        $tests->assertSame(false, Translator::has('nao.existe.chave'));
        $tests->assertTrue(Translator::has('http.not_found_title'));

        // Um locale que a aplicação não tem cai no fallback.
        $tests->assertSame('404 - Page Not Found', __('http.not_found_title', [], 'de'));

        $tests->assertThrows(
            fn () => Translator::setLocale('não é um locale'),
            InvalidArgumentException::class
        );
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('plural forms are chosen by explicit range or by locale rule', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('pt_BR');
        $tests->assertSame('Nenhum item', trans_choice('app.items', 0));
        $tests->assertSame('Um item', trans_choice('app.items', 1));
        $tests->assertSame('5 itens', trans_choice('app.items', 5));

        Translator::setLocale('en');
        $tests->assertSame('No items', trans_choice('app.items', 0));
        $tests->assertSame('3 items', trans_choice('app.items', 3));

        /*
         * Ranges cover most languages but not all: Polish picks its form from
         * the last digits, so 22 and 12 differ although both exceed five.
         * Shipping an incomplete copy of the CLDR rules would be quietly
         * wrong, so the selector is a hook the application registers.
         */
        Translator::pluralizer('pl', function (int $count): int {
            if ($count === 1) {
                return 0;
            }

            $mod10 = $count % 10;
            $mod100 = $count % 100;

            return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 1 : 2;
        });

        // O seletor é inspecionado diretamente: o que importa aqui é a regra,
        // não o catálogo que forneceria as formas.
        $reflection = new ReflectionMethod(Translator::class, 'selectPlural');
        $reflection->setAccessible(true);

        $tests->assertSame(0, $reflection->invoke(null, 'pl', 1));
        $tests->assertSame(1, $reflection->invoke(null, 'pl', 22));   // "pliki"
        $tests->assertSame(2, $reflection->invoke(null, 'pl', 12));   // "plików"
        $tests->assertSame(2, $reflection->invoke(null, 'pl', 5));

        // Sem seletor registrado, a regra é a do inglês.
        $tests->assertSame(0, $reflection->invoke(null, 'en', 1));
        $tests->assertSame(1, $reflection->invoke(null, 'en', 7));
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('validation messages follow the locale and inflect by count', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('en');
        $errors = Validator::validate(['nome' => ''], ['nome' => 'required'])->errors();
        $tests->assertSame('nome is required.', $errors['nome'][0]);

        Translator::setLocale('pt_BR');
        $errors = Validator::validate(['nome' => ''], ['nome' => 'required'])->errors();
        $tests->assertSame('nome é obrigatório.', $errors['nome'][0]);

        // A regra de comprimento flexiona: "ao menos um caractere", não "1 caracteres".
        $errors = Validator::validate(['nome' => ''], ['nome' => 'min:1'])->errors();
        $tests->assertSame('nome deve ter ao menos um caractere.', $errors['nome'][0]);

        $errors = Validator::validate(['nome' => 'ab'], ['nome' => 'min:5'])->errors();
        $tests->assertSame('nome deve ter ao menos 5 caracteres.', $errors['nome'][0]);

        // Uma mensagem passada pelo chamador vence intocada.
        $errors = Validator::validate(
            ['nome' => ''],
            ['nome' => 'required'],
            ['nome' => ['required' => 'Informe seu nome.']]
        )->errors();
        $tests->assertSame('Informe seu nome.', $errors['nome'][0]);
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('the locale is negotiated from the request', function () use ($tests): void {
    $request = fn (string $header): Request
        => Request::create('GET', '/', ['headers' => ['Accept-Language' => $header]]);

    // A ordem sai das qualidades, e um empate mantém a ordem escrita.
    $tests->assertSame(
        ['pt-BR', 'pt', 'en'],
        $request('pt-BR,pt;q=0.9,en;q=0.8')->acceptedLanguages()
    );

    // q=0 é como o cliente diz que NÃO quer um idioma.
    $tests->assertSame(['en'], $request('de;q=0,en')->acceptedLanguages());
    $tests->assertSame([], Request::create('GET', '/')->acceptedLanguages());

    $available = ['en', 'pt_BR'];
    $tests->assertSame('pt_BR', $request('pt-BR')->preferredLanguage($available));

    // Pedir "pt" e receber pt_BR é melhor do que receber inglês.
    $tests->assertSame('pt_BR', $request('pt')->preferredLanguage($available));
    $tests->assertSame('en', $request('de,fr')->preferredLanguage($available, 'en'));
});

$tests->run('a 404 is rendered in the visitor language', function () use ($tests): void {
    /*
     * The interesting part: a request that matches no route never reaches a
     * controller, so the only way it can be translated is the locale being
     * resolved by global middleware.
     */
    Router::reset();

    $router = (new Router(new Container(), ''))
        ->middleware(new SetLocale(['en', 'pt_BR'], 'en'));

    $portugues = $router->dispatch(Request::create('GET', '/nada', [
        'headers' => ['Accept-Language' => 'pt-BR'],
    ]));
    $ingles = $router->dispatch(Request::create('GET', '/nada', [
        'headers' => ['Accept-Language' => 'en'],
    ]));

    $tests->assertSame(HTTP_NOT_FOUND, $portugues->status());
    $tests->assertTrue(str_contains($portugues->body(), 'não foi encontrada'));
    $tests->assertSame('pt-BR', $portugues->header('Content-Language'));

    $tests->assertTrue(str_contains($ingles->body(), 'was not found'));
    $tests->assertSame('en', $ingles->header('Content-Language'));

    // E o atributo lang do documento acompanha.
    $tests->assertTrue(str_contains($portugues->body(), '<html lang="pt-BR">'));

    Translator::setLocale(APP_LOCALE);
});

$tests->run('password hashing delegates to PHP and stays current', function () use ($tests): void {
    $hash = Hash::make('segredo');

    $tests->assertTrue(Hash::check('segredo', $hash));
    $tests->assertSame(false, Hash::check('errado', $hash));
    $tests->assertSame(false, Hash::check('', $hash));
    $tests->assertSame(false, Hash::needsRehash($hash));

    /*
     * The throwaway hash used to equalise timing on a failed lookup must have
     * been produced with the parameters password_hash() uses today. PHP 8.4
     * raised bcrypt's default cost from 10 to 12, and a hash left at 10
     * verifies about four times faster than a real one — which would reopen
     * the very difference it exists to hide. This fails when PHP moves again.
     */
    $timing = (new ReflectionClass(Auth::class))->getConstant('TIMING_HASH');
    $tests->assertSame(false, password_needs_rehash($timing, PASSWORD_DEFAULT));
});

$tests->run('credentials are checked and a session login is kept', function () use ($tests): void {
    $hash = Hash::make('segredo');
    $pdo = new ModelPdoTest(['users' => [
        ['id' => 1, 'name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => $hash],
    ]]);

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('web', new SessionGuard(Auth::provider()));

    try {
        $tests->assertTrue(Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'segredo']));
        $tests->assertTrue(Auth::check());
        $tests->assertSame(1, Auth::id());
        $tests->assertSame('Ana', Auth::user()->name);

        Auth::logout();
        $tests->assertSame(false, Auth::check());
        $tests->assertSame(null, Auth::user());
        $tests->assertTrue(Auth::guest());

        $tests->assertSame(
            false,
            Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'errada'])
        );

        /*
         * The password is never part of the lookup. Matching on a hash could
         * only work by comparing hashes as strings, which defeats the salt.
         */
        $pdo->queries = [];
        Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'errada']);
        $tests->assertSame(false, str_contains($pdo->queries[0] ?? '', '`password`'));
    } finally {
        Model::useConnection(null);
        Auth::reset();
    }
});

$tests->run('a bearer token identifies a request without a session', function () use ($tests): void {
    $pdo = new ModelPdoTest(['users' => [
        ['id' => 1, 'name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => 'x'],
    ]]);

    $key = $_ENV['JWT_KEY'] ?? null;
    $_ENV['JWT_KEY'] = bin2hex(random_bytes(32));

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('api', new TokenGuard(Auth::provider()));

    try {
        $token = JWT::generate(['id' => 1, 'email' => 'ana@exemplo.com']);

        // claims() answers who the token is about; validate() only whether to trust it.
        $tests->assertSame('ana@exemplo.com', JWT::claims($token)['email']);
        $tests->assertSame(null, JWT::claims($token . 'x'));

        $authenticated = Request::create('GET', '/api', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $tests->assertSame(1, Auth::resolve($authenticated, 'api')?->getAuthIdentifier());
        $tests->assertSame(null, Auth::resolve(Request::create('GET', '/api'), 'api'));
        $tests->assertSame(null, Auth::resolve(Request::create('GET', '/api', [
            'headers' => ['Authorization' => 'Bearer nao.e.um.token'],
        ]), 'api'));
    } finally {
        Model::useConnection(null);
        Auth::reset();

        if ($key === null) {
            unset($_ENV['JWT_KEY']);
        } else {
            $_ENV['JWT_KEY'] = $key;
        }
    }
});

$tests->run('the authenticate middleware resolves, and refuses when required', function () use ($tests): void {
    $pdo = new ModelPdoTest(['users' => [['id' => 1, 'name' => 'Ana', 'password' => 'x']]]);

    $key = $_ENV['JWT_KEY'] ?? null;
    $_ENV['JWT_KEY'] = bin2hex(random_bytes(32));

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('api', new TokenGuard(Auth::provider()));

    Router::reset();
    Router::get('/aberta', 'AuthControllerTest', 'open');
    Router::get('/secreta', 'AuthControllerTest', 'secret')
        ->middleware(new Authenticate('api', required: true));

    try {
        $token = JWT::generate(['id' => 1, 'email' => 'ana@exemplo.com']);
        $router = (new Router(new Container(), ''))->middleware(new Authenticate('api'));

        $withToken = ['headers' => ['Authorization' => 'Bearer ' . $token]];

        // Registered globally it only resolves: an anonymous request carries on.
        $tests->assertSame('anonimo', $router->dispatch(Request::create('GET', '/aberta'))->body());
        $tests->assertSame('logado', $router->dispatch(Request::create('GET', '/aberta', $withToken))->body());

        // Attached to a route with required: true it refuses instead.
        $refused = $router->dispatch(Request::create('GET', '/secreta', [
            'headers' => ['Accept' => 'application/json'],
        ]));
        $tests->assertSame(HTTP_UNAUTHORIZED, $refused->status());

        $tests->assertSame(
            'secreto:1',
            $router->dispatch(Request::create('GET', '/secreta', $withToken))->body()
        );

        // A browser is redirected rather than shown a bare 401.
        $browser = $router->dispatch(Request::create('GET', '/secreta'));
        $tests->assertSame(HTTP_FOUND, $browser->status());
        $tests->assertSame('/login', $browser->header('Location'));
    } finally {
        Model::useConnection(null);
        Auth::reset();
        Router::reset();

        if ($key === null) {
            unset($_ENV['JWT_KEY']);
        } else {
            $_ENV['JWT_KEY'] = $key;
        }
    }
});

$tests->run('policies and abilities finally have something that calls them', function () use ($tests): void {
    /*
     * make:policy has generated classes since long before this; nothing ever
     * invoked one. Gate is what invokes them.
     */
    $pdo = new ModelPdoTest(['users' => [['id' => 1, 'password' => 'x']]]);

    Model::useConnection($pdo);
    Auth::reset();
    Gate::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('web', new SessionGuard(Auth::provider()));

    try {
        Auth::login(AuthUserTest::find(1));

        Gate::policy(AuthPostTest::class, AuthPostPolicyTest::class);
        Gate::define('admin', static fn (?object $user): bool
            => $user !== null && $user->getAuthIdentifier() === 99);

        $tests->assertTrue(Gate::allows('update', new AuthPostTest(1)));
        $tests->assertSame(false, Gate::allows('update', new AuthPostTest(2)));
        $tests->assertTrue(Gate::denies('update', new AuthPostTest(2)));
        $tests->assertTrue(Gate::allows('view', new AuthPostTest(2)));
        $tests->assertSame(false, Gate::allows('admin'));

        // An ability nobody declared is denied; allowing by default would mean
        // a typo in an ability name silently opens a door.
        $tests->assertSame(false, Gate::allows('inventada', new AuthPostTest(1)));

        $tests->assertThrows(
            fn () => Gate::authorize('update', new AuthPostTest(2)),
            AuthorizationException::class
        );

        Gate::authorize('update', new AuthPostTest(1));   // não lança

        $tests->assertSame(false, Gate::forUser(null, 'update', new AuthPostTest(1)));
    } finally {
        Model::useConnection(null);
        Auth::reset();
        Gate::reset();
    }
});

$tests->run('auth messages exist in every shipped locale', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        foreach (['en', 'pt_BR', 'es'] as $locale) {
            Translator::setLocale($locale);

            foreach (['auth.failed', 'auth.unauthenticated', 'auth.unauthorized'] as $key) {
                // A key with no translation comes back as the key itself.
                $tests->assertSame(false, __($key) === $key);
            }

            $tests->assertSame(false, __('http.not_found_title') === 'http.not_found_title');
            $tests->assertSame(false, __('validation.required') === 'validation.required');
        }
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('mass assignment needs an explicit list', function () use ($tests): void {
    /*
     * The natural line — Model::create($request->all()) — used to store every
     * column the attacker chose to submit. A registration form that never
     * showed an "is_admin" field still wrote one if the request carried it.
     */
    $submitted = [
        'name' => 'Ana',
        'email' => 'ana@exemplo.com',
        'is_admin' => 1,
        'balance' => 999999,
    ];

    $user = new AuthUserTest(Request::create('POST', '/cadastro', ['body' => $submitted])->all());

    $tests->assertSame('Ana', $user->getAttribute('name'));
    $tests->assertSame(null, $user->getAttribute('is_admin'));
    $tests->assertSame(null, $user->getAttribute('balance'));

    /*
     * A model that declares nothing cannot be filled at all. Defaulting to
     * permissive would protect only the developers who already knew to declare
     * the list, which is the wrong set of people.
     */
    $tests->assertThrows(
        fn () => new UnguardedModelTest(['qualquer' => 1]),
        MassAssignmentException::class
    );

    // An empty array is not an attempt to mass assign, so it does not raise.
    $tests->assertTrue((new UnguardedModelTest()) instanceof UnguardedModelTest);

    // forceFill is the way in for values the application itself chose.
    $forced = (new AuthUserTest())->forceFill(['is_admin' => 1]);
    $tests->assertSame(1, $forced->getAttribute('is_admin'));

    $tests->assertSame(['name', 'email', 'password'], AuthUserTest::fillable());
});

$tests->run('forwarding headers are believed only from a trusted proxy', function () use ($tests): void {
    $behindProxy = static fn (): Request => Request::create('GET', '/', [
        'server' => ['REMOTE_ADDR' => '10.0.0.7'],
        'headers' => [
            'X-Forwarded-For' => '203.0.113.9, 10.0.0.7',
            'X-Forwarded-Proto' => 'https',
        ],
    ]);

    $previous = Request::trustedProxies();

    try {
        // Nothing is trusted by default, so the headers are ignored.
        Request::setTrustedProxies([]);
        $tests->assertSame('10.0.0.7', $behindProxy()->ip());
        $tests->assertSame(false, $behindProxy()->isSecure());

        Request::setTrustedProxies(['10.0.0.0/8']);
        $tests->assertSame('203.0.113.9', $behindProxy()->ip());
        $tests->assertTrue($behindProxy()->isSecure());

        /*
         * A visitor outside the trusted range cannot claim an address, which
         * matters the moment anything rate limits or logs by IP.
         */
        $forged = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '198.51.100.4'],
            'headers' => ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'],
        ]);

        $tests->assertSame('198.51.100.4', $forged->ip());
        $tests->assertSame(false, $forged->isSecure());

        // A literal address works alongside a range.
        Request::setTrustedProxies(['10.0.0.7']);
        $tests->assertSame('203.0.113.9', $behindProxy()->ip());
    } finally {
        Request::setTrustedProxies($previous);
    }
});

$tests->run('rate limiting counts a client and refuses past the limit', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());
    $limit = new RateLimit(maxAttempts: 3, decaySeconds: 60, name: 'teste', cache: $cache);

    $request = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.1'],
        'headers' => ['Accept' => 'application/json'],
    ]);

    $destination = static fn (Request $passed): Response => Response::text('ok');

    $first = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_OK, $first->status());
    $tests->assertSame('3', $first->header('X-RateLimit-Limit'));
    $tests->assertSame('2', $first->header('X-RateLimit-Remaining'));

    $limit->handle($request, $destination);
    $third = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_OK, $third->status());
    $tests->assertSame('0', $third->header('X-RateLimit-Remaining'));

    $refused = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_TOO_MANY_REQUESTS, $refused->status());
    $tests->assertTrue((int) $refused->header('Retry-After') > 0);

    // A different client has its own allowance.
    $other = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.2'],
    ]);
    $tests->assertSame(HTTP_OK, $limit->handle($other, $destination)->status());
});

$tests->run('security headers are added, and the risky ones only on request', function () use ($tests): void {
    $destination = static fn (Request $request): Response => Response::text('ok');

    $plain = (new SecurityHeaders())->handle(Request::create('GET', '/'), $destination);

    $tests->assertSame('nosniff', $plain->header('X-Content-Type-Options'));
    $tests->assertSame('DENY', $plain->header('X-Frame-Options'));
    $tests->assertSame('strict-origin-when-cross-origin', $plain->header('Referrer-Policy'));

    /*
     * CSP is off unless asked for: a policy that does not match the
     * application's assets breaks the page with no error the developer sees,
     * and the framework cannot know what those assets are.
     */
    $tests->assertSame(null, $plain->header('Content-Security-Policy'));

    $configured = new SecurityHeaders(
        contentSecurityPolicy: "default-src 'self'",
        frameOptions: 'SAMEORIGIN',
        hstsMaxAge: 31536000
    );

    $overHttp = $configured->handle(Request::create('GET', '/'), $destination);
    $tests->assertSame("default-src 'self'", $overHttp->header('Content-Security-Policy'));
    $tests->assertSame('SAMEORIGIN', $overHttp->header('X-Frame-Options'));

    // HSTS only over HTTPS: a browser ignores it otherwise, so sending it on a
    // plain connection would look like protection without being any.
    $tests->assertSame(null, $overHttp->header('Strict-Transport-Security'));

    $overHttps = $configured->handle(
        Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]),
        $destination
    );
    $tests->assertSame('max-age=31536000', $overHttps->header('Strict-Transport-Security'));
});

$tests->run('the cache counts atomically and does not move the window', function () use ($tests): void {
    foreach ([new MemoryDriver(), new FileDriver(sys_get_temp_dir() . '/sfphp-increment-' . getmypid())] as $driver) {
        $cache = new CacheManager($driver);
        $cache->flush();

        $tests->assertSame(1, $cache->increment('hits', 1, 60));
        $tests->assertSame(2, $cache->increment('hits', 1, 60));
        $tests->assertSame(7, $cache->increment('hits', 5, 60));

        // The counter is readable as an ordinary value.
        $tests->assertSame(7, (int) $cache->get('hits'));

        /*
         * The lifetime belongs to the counter that was created, not to every
         * hit afterwards. A client that keeps knocking must not be able to
         * push its own window forward and stay inside the limit forever.
         */
        $ttl = $cache->ttl('hits');
        $tests->assertTrue($ttl !== null && $ttl <= 60);
        $cache->increment('hits', 1, 3600);
        $tests->assertTrue($cache->ttl('hits') <= 60);

        // A counter that was never created reports no deadline.
        $tests->assertSame(null, $cache->ttl('never-set'));

        /*
         * The corollary of "the lifetime applies only on creation": a counter
         * first created without one never gets one. Documented, because it is
         * the kind of thing that is only surprising after it has happened.
         */
        $cache->increment('immortal');
        $cache->increment('immortal', 1, 60);
        $tests->assertSame(null, $cache->ttl('immortal'));

        $tests->assertSame(-2, $cache->decrement('down', 2, 60));

        $cache->flush();
    }
});

$tests->run('concurrent processes do not lose counts', function () use ($tests): void {
    /*
     * The bug this guards against cannot be reproduced in one process: get()
     * followed by put() only loses a hit when something else writes in
     * between. So this really does fork PHP processes that all increment the
     * same key at once, and asserts the total is exact.
     *
     * Before Cache::increment() existed, the rate limiter counted with a read
     * and a write, and this test would land well short of the total — which is
     * how an attacker opening parallel connections got more attempts than the
     * limit allowed.
     */
    $directory = sys_get_temp_dir() . '/sfphp-concurrency-' . bin2hex(random_bytes(6));
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $script = $directory . '-worker.php';

    file_put_contents($script, <<<'WORKER'
        <?php
        require $argv[1];
        $driver = new SfphpProject\src\Cache\FileDriver($argv[2]);
        for ($i = 0; $i < (int) $argv[4]; $i++) {
            $driver->increment($argv[3], 1, 60);
        }
        WORKER);

    $workers = 12;
    $perWorker = 25;
    $processes = [];

    for ($i = 0; $i < $workers; $i++) {
        $command = escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($autoload)
            . ' ' . escapeshellarg($directory)
            . ' ' . escapeshellarg('shared')
            . ' ' . escapeshellarg((string) $perWorker);

        $processes[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }

    foreach ($processes as $index => $process) {
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        proc_close($process);
    }

    $driver = new FileDriver($directory);
    $tests->assertSame($workers * $perWorker, (int) $driver->get('shared'));

    $driver->flush();
    @rmdir($directory);
    @unlink($script);
});

$tests->run('the rate limiter counts through the atomic counter', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-ratelimit-' . bin2hex(random_bytes(6));
    $cache = new CacheManager(new FileDriver($directory));
    $limit = new RateLimit(maxAttempts: 2, decaySeconds: 60, name: 'atomic', cache: $cache);

    $request = Request::create('POST', '/login', [
        'server' => ['REMOTE_ADDR' => '203.0.113.9'],
        'headers' => ['Accept' => 'application/json'],
    ]);

    $destination = static fn (Request $passed): Response => Response::text('ok');

    $tests->assertSame('1', $limit->handle($request, $destination)->header('X-RateLimit-Remaining'));
    $tests->assertSame('0', $limit->handle($request, $destination)->header('X-RateLimit-Remaining'));

    $refused = $limit->handle($request, $destination);
    $tests->assertSame(HTTP_TOO_MANY_REQUESTS, $refused->status());

    /*
     * Retry-After comes from the counter's remaining lifetime, so it counts
     * down towards the window's close instead of restarting at the full decay
     * on every refusal.
     */
    $retryAfter = (int) $refused->header('Retry-After');
    $tests->assertTrue($retryAfter > 0 && $retryAfter <= 60);

    $cache->flush();
    @rmdir($directory);
});

$tests->run('log records are structured, ordered by severity and filtered', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver, Level::Warning);

    $log->debug('ignored');
    $log->info('also ignored');
    $log->warning('kept', ['attempt' => 3]);
    $log->error('kept too');

    $tests->assertSame(2, count($driver->records()));
    $tests->assertSame('kept', $driver->records()[0]['message']);
    $tests->assertSame(3, $driver->records()[0]['context']['attempt']);
    $tests->assertSame(Level::Error, $driver->last()['level']);

    // The severity order is the one a human reads, not RFC 5424's inverted one.
    $tests->assertTrue(Level::Error->atLeast(Level::Warning));
    $tests->assertTrue(!Level::Debug->atLeast(Level::Info));

    // A typo in configuration falls back instead of stopping the application.
    $tests->assertSame(Level::Info, Level::fromName('nonsense', Level::Info));
    $tests->assertSame(Level::Warning, Level::fromName('WARNING'));
});

$tests->run('the shared context reaches every record and can be dropped', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);

    $log->withContext(['request_id' => 'abc123']);
    $log->info('first');
    $log->info('second', ['order_id' => 7]);

    $tests->assertSame('abc123', $driver->records()[0]['context']['request_id']);
    $tests->assertSame('abc123', $driver->records()[1]['context']['request_id']);
    $tests->assertSame(7, $driver->records()[1]['context']['order_id']);

    /*
     * The reset is what keeps one visitor's id off the next visitor's logs in a
     * worker that serves many requests.
     */
    $log->forgetContext();
    $log->info('third');
    $tests->assertSame([], $driver->last()['context']);
});

$tests->run('secrets are redacted before a record is written', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);

    $log->info('login attempt', [
        'email' => 'ana@example.com',
        'password' => 'hunter2',
        'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
        'card' => ['card_number' => '4111111111111111', 'last4' => '1111'],
    ]);

    $context = $driver->last()['context'];

    $tests->assertSame('ana@example.com', $context['email']);
    $tests->assertSame('[redacted]', $context['password']);
    $tests->assertSame('[redacted]', $context['headers']['Authorization']);
    $tests->assertSame('application/json', $context['headers']['Accept']);
    $tests->assertSame('[redacted]', $context['card']['card_number']);
    $tests->assertSame('1111', $context['card']['last4']);

    // An application can name more keys of its own.
    $log->redact('pin')->info('x', ['pin' => '0000']);
    $tests->assertSame('[redacted]', $driver->last()['context']['pin']);
});

$tests->run('a stream record is one line of json, in UTC', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-log-' . bin2hex(random_bytes(6)) . '/app.log';
    $driver = new StreamDriver($path);

    $driver->write(Level::Error, 'algo quebrou', ['país' => 'Brasil']);
    $driver->write(Level::Info, 'segunda linha');
    $driver->close();

    $lines = array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($path))));
    $tests->assertSame(2, count($lines));

    $record = json_decode($lines[0], true);
    $tests->assertSame('error', $record['level']);
    $tests->assertSame('algo quebrou', $record['message']);
    $tests->assertSame('Brasil', $record['context']['país']);

    // UTF-8 survives: a log that mangles the message is worse than no log.
    $tests->assertTrue(str_contains($lines[0], 'país'));

    // The timestamp is UTC and sorts as a string, which is what makes lines
    // from several machines orderable.
    $tests->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $record['timestamp']) === 1);

    // A record with no context does not carry an empty object.
    $tests->assertTrue(!array_key_exists('context', json_decode($lines[1], true)));

    unlink($path);
    @rmdir(dirname($path));
});

$tests->run('a value that cannot be encoded degrades instead of throwing', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-log-' . bin2hex(random_bytes(6)) . '/app.log';
    $driver = new StreamDriver($path);

    $recursive = ['self' => null];
    $recursive['self'] = &$recursive;

    $driver->write(Level::Error, 'still written', ['handle' => fopen('php://memory', 'r')]);
    $driver->close();

    $record = json_decode(trim((string) file_get_contents($path)), true);
    $tests->assertSame('still written', $record['message']);

    unlink($path);
    @rmdir(dirname($path));
});

$tests->run('every request gets an id that reaches the logs and the response', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = new LogManager($driver);
    $middleware = new LogRequests($log);

    $seen = null;
    $destination = static function (Request $request) use (&$seen): Response {
        $seen = $request->attribute('request_id');

        return Response::text('ok');
    };

    $response = $middleware->handle(Request::create('GET', '/orders'), $destination);

    $id = $response->header('X-Request-Id');
    $tests->assertTrue($id !== null && strlen($id) === 32);

    // The same id is on the request, in the response header, and on the record.
    $tests->assertSame($id, $seen);
    $tests->assertSame($id, $driver->last()['context']['request_id']);
    $tests->assertSame('request handled', $driver->last()['message']);
    $tests->assertSame(HTTP_OK, $driver->last()['context']['status']);
    $tests->assertSame('/orders', $driver->last()['context']['path']);
    $tests->assertTrue($driver->last()['context']['duration_ms'] >= 0);
});

$tests->run('an inbound request id is honoured only when it is usable', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $middleware = new LogRequests(new LogManager($driver));
    $destination = static fn (Request $request): Response => Response::text('ok');

    // A trace coming from another service continues here.
    $traced = Request::create('GET', '/', ['headers' => ['X-Request-Id' => 'trace-0001']]);
    $tests->assertSame('trace-0001', $middleware->handle($traced, $destination)->header('X-Request-Id'));

    /*
     * Client-controlled input goes straight into the logs, so an id that is too
     * long, or carries characters a log viewer would act on, is replaced rather
     * than believed.
     */
    foreach (["injected\nline", str_repeat('a', 129), 'has spaces', '<script>'] as $hostile) {
        $refused = Request::create('GET', '/', ['headers' => ['X-Request-Id' => $hostile]]);
        $assigned = $middleware->handle($refused, $destination)->header('X-Request-Id');

        $tests->assertTrue($assigned !== $hostile);
        $tests->assertSame(32, strlen($assigned));
    }
});

$tests->run('a failing request is reported once, with its id, by the router', function () use ($tests): void {
    $driver = new LogMemoryDriver();
    $log = logger()->driver($driver)->forgetContext();

    Router::reset();
    Router::get('/boom', 'DispatchTestController', 'boom');

    $router = (new Router(new Container(), ''))->middleware(new LogRequests($log));
    $response = $router->dispatch(Request::create('GET', '/boom'));

    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $response->status());

    /*
     * The failure is written once, not twice. The router's boundary reports it
     * and LogRequests deliberately does not, so that both being present cannot
     * produce a duplicate.
     */
    $failures = array_values(array_filter(
        $driver->records(),
        static fn (array $record): bool => isset($record['context']['exception'])
    ));
    $tests->assertSame(1, count($failures));

    $record = $failures[0];
    $tests->assertSame(Level::Error, $record['level']);
    $tests->assertSame('/boom', $record['context']['path']);
    $tests->assertTrue(isset($record['context']['line']));

    /*
     * An exception skips the rest of the pipeline, so the id has to reach both
     * the record and the response some other way. The visitor who sees the 500
     * is the person most in need of it.
     */
    $id = $record['context']['request_id'];
    $tests->assertSame(32, strlen($id));
    $tests->assertSame($id, $response->header('X-Request-Id'));

    logger()->driver(new LogNullDriver())->forgetContext();
});

$tests->run('the 500 page follows the visitor language', function () use ($tests): void {
    $previous = locale();

    Translator::setLocale('es');
    $spanish = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($spanish->body(), 'lang="es"'));
    $tests->assertTrue(str_contains($spanish->body(), 'Error interno'));

    Translator::setLocale('pt_BR');
    $portuguese = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($portuguese->body(), 'lang="pt-BR"'));

    Translator::setLocale('en');
    $english = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($english->body(), 'lang="en"'));
    $tests->assertTrue(str_contains($english->body(), 'Internal error'));

    Translator::setLocale($previous);
});

$tests->finish();
