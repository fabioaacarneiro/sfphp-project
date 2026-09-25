<?php

require __DIR__ . '/../vendor/autoload.php';

/*
 * Explicit, because the example application's config used to do it through
 * composer's autoload-dev files entry — which is loaded whenever this package
 * is the root one, including in a `composer create-project` install, where it
 * required a file the package does not ship.
 */
SfphpProject\src\Bootstrap::load(dirname(__DIR__));

use SfphpProject\src\Csrf;
use SfphpProject\src\Database;
use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Config;
use SfphpProject\src\Container;
use SfphpProject\src\Dotenv;
use SfphpProject\src\Health;
use SfphpProject\src\Auth\RememberToken;
use SfphpProject\src\Log\Metrics;
use SfphpProject\src\Migrations\MigrationLock;
use SfphpProject\src\Events\Dispatcher;
use SfphpProject\src\JWT;
use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Identifier;
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationRunner;
use SfphpProject\src\Migrations\Schema;
use SfphpProject\src\Bootstrap;
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
use SfphpProject\src\Cache\RedisDriver as CacheRedisDriver;
use SfphpProject\src\RedisConnection;
use SfphpProject\src\Assets;
use SfphpProject\src\Debug\Dumper;
use SfphpProject\src\Debug\HtmlDump;
use SfphpProject\src\Debug\TextDump;
use SfphpProject\src\Env;
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
use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\ClientResponse;
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\UploadException;
use SfphpProject\src\Http\UploadedFile;
use SfphpProject\src\Http\Response;
use SfphpProject\src\QueryBuilder;
use SfphpProject\src\Queue\DatabaseDriver;
use SfphpProject\src\Queue\QueueManager;
use SfphpProject\src\Queue\RedisDriver;
use SfphpProject\src\RawQuery;
use SfphpProject\src\Route;
use SfphpProject\src\Router;
use SfphpProject\src\Mail\ArrayDriver as MailArrayDriver;
use SfphpProject\src\Mail\MailManager;
use SfphpProject\src\Mail\Message;
use SfphpProject\src\Session\CacheHandler;
use SfphpProject\src\Session\Session;
use SfphpProject\src\Str;
use SfphpProject\src\Time;
use SfphpProject\src\Validator;
use SfphpProject\src\View;
use SfphpProject\src\View\Phpx;
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
 * Events and listeners used by the dispatcher tests.
 */
class DispatcherEvent
{
    public array $seen = [];
}

final class DispatcherChildEvent extends DispatcherEvent
{
}

final class DispatcherListener
{
    public function handle(object $event): void
    {
        $event->seen[] = 'class';
    }
}

final class DispatcherBrokenListener
{
    public function handle(object $event): void
    {
        throw new RuntimeException('listener failed');
    }
}



/**
 * A middleware that chooses its own collaborator when nobody binds one.
 */
final class ContainerOptionalDependency
{
    public function __construct(public ?SessionHandlerInterface $handler = null, public ?Iterator $items = null) {}
}


/**
 * Model used by the time zone tests.
 */
final class TimeTestArticle extends \SfphpProject\src\Database\Model
{
    protected static string $table = 'time_test_articles';

    protected static array $fillable = ['published_at', 'published_on'];

    protected static array $casts = [
        'published_at' => 'datetime',
        'published_on' => 'date',
    ];
}


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

    /*
     * The inlined stylesheet draws its icons with SVGs in data: URIs, and an
     * SVG must name its XML namespace, which is written as a URL. It is an
     * identifier the browser never fetches — the image is already in the
     * URI — so it is the one address allowed; any other would be a request.
     */
    $tests->assertSame(0, preg_match_all('#https?://(?!www\.w3\.org/2000/svg)#', $response->body()));
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

$tests->run('a template that arrives older than its cache is still recompiled', function () use ($tests): void {
    /*
     * Reported from a project installed with composer create-project: the
     * page rendered was the previous release's, while the file on disk was
     * already the new one. Extracting an archive writes the package's own
     * timestamps, so the updated template arrived *older* than a cache file
     * written minutes earlier — and a newer-than comparison called that cache
     * current. Time moving forward was never a safe thing to rely on.
     */
    $directory = sys_get_temp_dir() . '/sfphp-cache-age-' . bin2hex(random_bytes(6));
    mkdir($directory . '/views', 0755, true);

    $template = $directory . '/views/page.sfht';
    $engine = new SfhtEngine([$directory . '/views'], $directory . '/cache');

    try {
        file_put_contents($template, '<p>first</p>');
        $tests->assertSame('<p>first</p>', $engine->render('page'));

        // The new file, carrying an older timestamp, exactly as a dist does.
        file_put_contents($template, '<p>second</p>');
        touch($template, time() - 3600);
        clearstatcache(true, $template);

        $tests->assertSame('<p>second</p>', $engine->render('page'));

        // And the version nobody has any more does not pile up.
        $tests->assertSame(1, count(glob($directory . '/cache/*.php') ?: []));
    } finally {
        foreach (glob($directory . '/cache/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($template);
        @rmdir($directory . '/cache');
        @rmdir($directory . '/views');
        @rmdir($directory);
    }
});

$tests->run('sfht compiles to an includable file rather than eval', function () use ($tests, $sfht, $sfhtDirectory): void {
    $sfht('<p>{{ $a }}</p>', ['a' => 1]);

    $compiled = glob($sfhtDirectory . '/cache/*.php');
    $tests->assertTrue($compiled !== [] && $compiled !== false);
    $tests->assertTrue(str_starts_with(file_get_contents($compiled[0]), '<?php'));
});

$tests->run('phpx compiles markup that lives inside a function', function () use ($tests): void {
    /*
     * The whole point of the compiler in one source: a region opened with
     * sfht( and closed by its matching parenthesis, with a parenthesis inside
     * an attribute that must not be mistaken for the closing one.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\Phpx;

    use SfphpProject\src\View\Sfht;

    function Badge(string $label): Sfht
    {
        return sfht(
            <span class="badge" title="a)b">{{ $label }}</span>
        );
    }

    function Panel(string $text): Sfht
    {
        return sfht(
            <div>
                @php $count = 2; @endphp
                {{ Badge('ok') }}
                {{ $text }}
                @if ($count === 2)<i>two</i>@endif
            </div>
        );
    }

    function notsfht(): string
    {
        return 'kept';
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        // What build --phpx checks, checked here too: the output has to be PHP.
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        require $file;

        $badge = SfphpTest\Phpx\Badge('<b>');
        $tests->assertSame('<span class="badge" title="a)b">&lt;b&gt;</span>', (string) $badge);

        $panel = (string) SfphpTest\Phpx\Panel('<script>');

        /*
         * Both interpolations are {{ }}. The component renders and the string
         * is escaped, because the type decides — this is the reason a
         * component returns Sfht instead of a string.
         */
        $tests->assertTrue(str_contains($panel, '<span class="badge" title="a)b">ok</span>'));
        $tests->assertTrue(str_contains($panel, '&lt;script&gt;'));
        $tests->assertSame(0, substr_count($panel, '<script>'));

        // @php and the directives work inside a region, as they do in a .sfht.
        $tests->assertTrue(str_contains($panel, '<i>two</i>'));

        // A function whose name merely ends in sfht( is not a markup region.
        $tests->assertSame('kept', SfphpTest\Phpx\notsfht());
    } finally {
        @unlink($file);
    }
});

$tests->run('phpx keeps the line numbers of the file the author wrote', function () use ($tests): void {
    /*
     * A region spans several lines and compiles to one expression, so without
     * padding every line after it would shift and php -l would name the wrong
     * one — which is the only thing standing between a syntax error and a
     * useless error message.
     */
    $source = "<?php\nfunction A(): \\SfphpProject\\src\\View\\Sfht\n{\n    return sfht(\n        <p>one</p>\n        <p>two</p>\n    );\n}\n// marker\n";
    $compiled = (new Phpx())->compile($source);

    $tests->assertSame(substr_count($source, "\n"), substr_count($compiled, "\n"));
    $tests->assertSame(8, array_search('// marker', explode("\n", $compiled), true));
});

$tests->run('build --phpx mirrors the folders the components live in', function () use ($tests): void {
    /*
     * One component per file is the convention, so a page's parts live in a
     * folder of their own — and the build has to walk into it. It used to scan
     * the first level only, which silently compiled nothing for a project that
     * organised its components at all.
     */
    $root = sys_get_temp_dir() . '/sfphp-build-' . bin2hex(random_bytes(6));
    mkdir($root . '/src/page', 0755, true);

    file_put_contents(
        $root . '/src/Loose.phpx',
        "<?php\nfunction Loose(): \\SfphpProject\\src\\View\\Sfht\n{\n    return sfht(<p>loose</p>);\n}\n"
    );
    file_put_contents(
        $root . '/src/page/Nested.phpx',
        "<?php\nfunction Nested(): \\SfphpProject\\src\\View\\Sfht\n{\n    return sfht(<p>nested</p>);\n}\n"
    );

    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/sfphp')
            . ' build --phpx --from=' . escapeshellarg($root . '/src')
            . ' --to=' . escapeshellarg($root . '/out') . ' 2>&1';

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(is_file($root . '/out/Loose.php'));
        $tests->assertTrue(is_file($root . '/out/page/Nested.php'));
    } finally {
        foreach (['/out/page/Nested.php', '/out/Loose.php', '/src/page/Nested.phpx', '/src/Loose.phpx'] as $file) {
            @unlink($root . $file);
        }

        foreach (['/out/page', '/out', '/src/page', '/src', ''] as $directory) {
            @rmdir($root . $directory);
        }
    }
});

$tests->run('phpx refuses a markup region that is never closed', function () use ($tests): void {
    $tests->assertThrows(
        fn () => (new Phpx())->compile("<?php\n\nfunction B() { return sfht(\n  <p>x</p>\n; }\n"),
        RuntimeException::class
    );
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
    /*
     * resources/, which is where the builder writes and what the package
     * carries. This read public/assets, which is now a published copy and
     * gitignored — so the test passed on a working tree that had published and
     * failed on a fresh checkout, which is the wrong way round.
     */
    $stylesheet = dirname(__DIR__) . '/resources/assets/css/sfcss.css';

    $tests->assertTrue(is_file($stylesheet));

    $head = file_get_contents($stylesheet, false, null, 0, 64);
    $tests->assertTrue(str_starts_with($head, '/* SFCSS'));
    $tests->assertTrue(filesize($stylesheet) > 10000);

    $source = file_get_contents(dirname(__DIR__) . '/src/Console/Application.php');
    $tests->assertSame(false, str_contains($source, "file_put_contents(\$outputPath, \$css)"));
});

/**
 * Build SFCSS from a project config and return the stylesheet and the warnings.
 *
 * @return array{css: string, stderr: string, status: int}
 */
$buildSfcss = static function (array $config): array {
    $directory = sys_get_temp_dir() . '/sfcss-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/config.json', json_encode($config === [] ? new stdClass() : $config));

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/tools/css-builder/sfcss-builder.php', $directory . '/config.json', $directory],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);

    $css = (string) @file_get_contents($directory . '/sfcss.css');
    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);

    return ['css' => $css, 'stderr' => $stderr, 'status' => $status];
};

$tests->run('a project sfcss config holds only what it changes', function () use ($tests, $buildSfcss): void {
    /*
     * A project config used to have to be a full copy of the default one:
     * leaving "spacing" out to change one colour broke the build. It is merged
     * over the defaults now, so three lines are a complete config.
     */
    $build = $buildSfcss(['colors' => ['primary' => '#7c3aed']]);

    $tests->assertSame(0, $build['status']);
    $tests->assertTrue(str_contains($build['css'], '--primary: #7c3aed;'));
    $tests->assertTrue(str_contains($build['css'], '.p-3 { padding: 1rem; }'));
    $tests->assertTrue(str_contains($build['css'], '.text-blue-600 { color: #2563eb; }'));
});

$tests->run('sfcss picks readable text for every colour, and says when it cannot', function () use ($tests, $buildSfcss): void {
    /*
     * White on the default blue-500 was 3.7:1, below the 4.5:1 WCAG AA asks
     * for. The text colour on a colour is computed now: white where it reads,
     * dark where white would not.
     */
    $build = $buildSfcss(['colors' => ['primary' => '#3b82f6', 'warning' => '#facc15']]);

    $tests->assertTrue(str_contains($build['css'], '--primary-contrast: #111827;'));
    $tests->assertTrue(str_contains($build['css'], '--warning-contrast: #111827;'));
    $tests->assertSame('', trim($build['stderr']));

    // A mid-grey that neither white nor near-black reaches 4.5:1 on is reported.
    $grey = $buildSfcss(['colors' => ['primary' => '#777777'], 'contrast' => ['dark' => '#555555']]);
    $tests->assertTrue(str_contains($grey['stderr'], 'colors.primary'));
});

$tests->run('a colour added to the sfcss config gets every variant', function () use ($tests, $buildSfcss): void {
    // A new colour used to produce a custom property and nothing else.
    $css = $buildSfcss(['colors' => ['brand' => '#0f766e']])['css'];

    foreach (['.btn-brand {', '.btn-outline-brand {', '.badge-brand {', '.alert-brand {', '.text-brand {', '.bg-brand-subtle {', '--brand-emphasis:'] as $needle) {
        $tests->assertTrue(str_contains($css, $needle), "missing {$needle}");
    }
});

$tests->run('the sfcss utility map takes additions and removals from the config', function () use ($tests, $buildSfcss): void {
    $css = $buildSfcss(['utilities' => [
        'cursor' => false,
        'tab-size' => ['class' => 'tab', 'property' => 'tab-size', 'values' => ['2' => '2', '4' => '4'], 'responsive' => true],
    ]])['css'];

    $tests->assertSame(false, str_contains($css, '.cursor-pointer'));
    $tests->assertTrue(str_contains($css, '.tab-4 { tab-size: 4; }'));
    $tests->assertTrue(str_contains($css, '.md\:tab-4 { tab-size: 4; }'));
});

$tests->run('sfcss options prefix variables and switch features off', function () use ($tests, $buildSfcss): void {
    $css = $buildSfcss(['options' => [
        'prefix' => 'sf',
        'components' => false,
        'responsiveVariants' => false,
        'reducedMotion' => false,
    ]])['css'];

    $tests->assertTrue(str_contains($css, '--sf-primary:'));
    $tests->assertTrue(str_contains($css, 'var(--sf-surface)'));
    $tests->assertSame(false, str_contains($css, 'var(--primary)'));
    $tests->assertSame(false, str_contains($css, '.btn {'));
    $tests->assertSame(false, str_contains($css, '.md\:'));
    $tests->assertSame(false, str_contains($css, 'prefers-reduced-motion'));

    // The accessibility baseline is not a component and stays.
    $tests->assertTrue(str_contains($css, ':focus-visible'));

    // Role colours are utilities, not components, so they stay too.
    $tests->assertTrue(str_contains($css, '.text-primary {'));
});

$tests->run('an sfcss prefix leaves the anchor SFJS writes alone', function () use ($tests, $buildSfcss): void {
    // SFJS sets --sf-anchor on every popover it positions; renaming it to
    // --acme-sf-anchor left every dropdown and tooltip unanchored.
    $css = $buildSfcss(['options' => ['prefix' => 'acme']])['css'];

    $tests->assertTrue(str_contains($css, '--acme-primary:'));
    $tests->assertTrue(str_contains($css, 'var(--sf-anchor)'));
    $tests->assertSame(false, str_contains($css, '--acme-sf-anchor'));
});

$tests->run('sfcss rounded:false squares the radius utilities too', function () use ($tests, $buildSfcss): void {
    // The rounded-* classes used literal values, so turning rounding off
    // squared the components and left every rounded-lg on the page round.
    $css = $buildSfcss(['options' => ['rounded' => false]])['css'];

    $tests->assertTrue(str_contains($css, '--radius-lg: 0;'));
    $tests->assertTrue(str_contains($css, '.rounded-lg { border-radius: var(--radius-lg); }'));
    $tests->assertTrue(str_contains($css, '--radius-full: 9999px;'));
});

$tests->run('an sfcss palette class wins over the component it is written on', function () use ($tests): void {
    // class="card bg-blue-50" kept the card's own background because the
    // palette was emitted before the components.
    $css = file_get_contents(dirname(__DIR__) . '/resources/assets/css/sfcss.css');

    $tests->assertTrue(strpos($css, '.card {') < strpos($css, '.bg-blue-50 {'));
    $tests->assertTrue(strpos($css, '.btn {') < strpos($css, '.hover\:bg-blue-700:hover {'));
});

$tests->run('sfcss spacing means the same with and without a breakpoint', function () use ($tests): void {
    /*
     * p-3 was 1rem and md:p-3 was 0.75rem: the base classes and the
     * breakpoint variants came from two different scales. One scale produces
     * both now.
     */
    $css = file_get_contents(dirname(__DIR__) . '/resources/assets/css/sfcss.css');

    $tests->assertTrue(str_contains($css, '.p-3 { padding: 1rem; }'));
    $tests->assertTrue(str_contains($css, '.md\:p-3 { padding: 1rem; }'));
    $tests->assertSame(false, str_contains($css, '@media (max-width: 768px)'));
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
            // Anchored: a docblock mentioning "the class name" is not a declaration.
            preg_match('/^(?:final )?class (\w+)/m', $source, $class);

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
    $tests->assertTrue(str_contains($portugues->body(), '<html lang="pt-BR"'));

    Translator::setLocale(APP_LOCALE);
});

$tests->run('password hashing delegates to PHP and stays current', function () use ($tests): void {
    $hash = Hash::make('segredo');

    $tests->assertTrue(Hash::check('segredo', $hash));
    $tests->assertSame(false, Hash::check('errado', $hash));
    $tests->assertSame(false, Hash::check('', $hash));
    $tests->assertSame(false, Hash::needsRehash($hash));

    /*
     * The throwaway hash used to equalise timing on a failed lookup must carry
     * the parameters password_hash() uses on the PHP that is running. A
     * mismatch in either direction makes the no-such-user path take a
     * different time from the wrong-password path, which is the difference an
     * attacker uses to learn which accounts exist.
     *
     * The assertion is about the hash actually used, not the baked constant:
     * PASSWORD_DEFAULT is bcrypt cost 10 on PHP 8.1 to 8.3 and cost 12 on 8.4,
     * so no single constant can be right everywhere. Asserting the constant is
     * what made this pass on 8.4 and fail on every other supported version.
     */
    $resolve = new ReflectionMethod(Auth::class, 'timingHash');
    $resolve->setAccessible(true);
    $timing = $resolve->invoke(null);

    $tests->assertSame(false, password_needs_rehash($timing, PASSWORD_DEFAULT));

    // And it really is a hash something can be verified against.
    $tests->assertSame(false, password_verify('anything at all', $timing));
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

        /*
         * Output goes to /dev/null rather than to pipes nobody reads. With
         * pipes, closing the read end while a worker was still writing killed
         * that worker mid-loop, and the count came up short for a reason that
         * had nothing to do with locking — which is exactly the failure this
         * test is supposed to be able to attribute.
         */
        $processes[] = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes[$i]
        );
    }

    foreach ($processes as $process) {
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

$tests->run('the runtime is UTC and now() is the instant', function () use ($tests): void {
    $tests->assertSame('UTC', date_default_timezone_get());
    $tests->assertSame('UTC', Time::now()->getTimezone()->getName());
    $tests->assertSame('UTC', now()->getTimezone()->getName());

    // now() is the helper the documentation has been showing all along.
    $tests->assertTrue(function_exists('now'));
    $tests->assertTrue(abs(now()->getTimestamp() - time()) <= 1);
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

$tests->run('a zone is for showing a time, not for storing one', function () use ($tests): void {
    $instant = Time::parse('2026-09-21T23:00:00+00:00');

    $tokyo = Time::in($instant, 'Asia/Tokyo');
    $tests->assertSame('2026-09-22 08:00:00', $tokyo->format('Y-m-d H:i:s'));

    // The same moment, seen from elsewhere: the instant did not move.
    $tests->assertSame($instant->getTimestamp(), $tokyo->getTimestamp());

    // And storing it stores the same instant, in UTC.
    $tests->assertSame('2026-09-21 23:00:00', Time::toDatabase($tokyo));

    $tests->assertSame('2026-09-21 23:00:00', Time::display($instant));
});

$tests->run('the clock can be held still so a time test is not a race', function () use ($tests): void {
    $frozen = Time::freeze('2026-01-01T12:00:00+00:00');

    $tests->assertTrue(Time::frozen());
    $tests->assertSame('2026-01-01T12:00:00+00:00', now()->format(DATE_ATOM));
    $tests->assertSame($frozen->getTimestamp(), Time::now()->getTimestamp());

    Time::unfreeze();
    $tests->assertTrue(!Time::frozen());
    $tests->assertTrue(abs(now()->getTimestamp() - time()) <= 1);
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

$tests->run('the session keeps values and hides its own bookkeeping', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);

    Session::put('cart_id', 42);
    $tests->assertSame(42, Session::get('cart_id'));
    $tests->assertTrue(Session::has('cart_id'));
    $tests->assertSame('fallback', Session::get('absent', 'fallback'));

    /*
     * The timestamps are the framework's, not the application's. Showing them
     * in all() would invite writing to them, and the deadlines would stop
     * meaning anything.
     */
    $tests->assertSame(['cart_id' => 42], Session::all());
    $tests->assertTrue(Session::startedAt() !== null);
    $tests->assertTrue(Session::lastActivityAt() !== null);

    Session::forget('cart_id');
    $tests->assertTrue(!Session::has('cart_id'));
});

$tests->run('an idle session ends, and says why once', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);
    Session::put('user_id', 7);

    /*
     * The clock is moved rather than the stamps, so the test exercises the
     * comparison the middleware actually makes.
     */
    Time::freeze(Time::now()->modify('+31 minutes'));
    Session::start(false, null, 1800, 0);

    $tests->assertSame(null, Session::get('user_id'));
    $tests->assertSame('idle', Session::expiredReason());

    // Read once and forgotten, so the notice is not shown on every later page.
    $tests->assertSame(null, Session::expiredReason());

    // Activity inside the window keeps it alive.
    Session::put('user_id', 7);
    Time::freeze(Time::now()->modify('+10 minutes'));
    Session::start(false, null, 1800, 0);
    $tests->assertSame(7, Session::get('user_id'));

    Time::unfreeze();
});

$tests->run('an absolute deadline ends a session however busy it has been', function () use ($tests): void {
    $_SESSION = [];
    Time::freeze('2026-01-01T00:00:00+00:00');
    Session::start(false, null, 1800, 7200);
    Session::put('user_id', 7);

    /*
     * Active the whole time — a request every ten minutes — so the idle
     * timeout never fires. This is the deadline that limits what a stolen
     * cookie is worth, and it is the one an audit asks about.
     */
    for ($minute = 10; $minute <= 130; $minute += 10) {
        Time::freeze((new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->modify("+{$minute} minutes"));
        Session::start(false, null, 1800, 7200);
    }

    $tests->assertSame(null, Session::get('user_id'));
    $tests->assertSame('absolute', Session::expiredReason());

    Time::unfreeze();
});

$tests->run('expiring a session takes the data and the id with it', function () use ($tests): void {
    $_SESSION = [];
    Session::start(false, null, 0, 0);
    Session::put('user_id', 7);

    Session::invalidate();

    /*
     * Emptying the data while keeping the id would leave the visitor holding a
     * cookie that still names a live session, which is most of what expiring
     * one was meant to prevent.
     */
    $tests->assertSame([], Session::all());
    $tests->assertTrue(Session::startedAt() !== null);
});

$tests->run('a shared handler lets a second instance read the same session', function () use ($tests): void {
    /*
     * This is the reason the handler exists. With PHP's own files, a session
     * written by one machine is invisible to another, which is what forces
     * sticky sessions on a load balancer. A shared store removes that, and the
     * cache handler is tested through the memory driver because the contract is
     * what matters here, not which driver is behind it.
     */
    $cache = new CacheManager(new MemoryDriver());
    $first = new CacheHandler($cache, 'session:');
    $second = new CacheHandler($cache, 'session:');

    $id = 'abcdef0123456789abcdef0123456789';
    $payload = 'user_id|i:7;';

    $tests->assertTrue($first->write($id, $payload));

    // A different instance, sharing only the store.
    $tests->assertSame($payload, $second->read($id));

    /*
     * validateId is what makes session.use_strict_mode work: PHP asks before
     * adopting the id a cookie carries, so an id nobody was issued is refused
     * instead of being brought to life.
     */
    $tests->assertTrue($second->validateId($id));
    $tests->assertTrue(!$second->validateId('an-id-nobody-issued'));

    $second->destroy($id);
    $tests->assertSame('', $first->read($id));
    $tests->assertSame(0, $first->gc(3600));
});

$tests->run('an unbound interface falls back to the default instead of failing', function () use ($tests): void {
    /*
     * This came out of registering StartSession by class name. Its constructor
     * takes `?SessionHandlerInterface $handler = null`, meaning "I will pick
     * one myself unless you bind one" — and the container resolved the type
     * anyway, so every request died with "Class SessionHandlerInterface does
     * not exist" and the middleware could only be registered as an instance.
     *
     * An interface is not instantiable, so there is nothing to autowire; when
     * the parameter already carries an answer, that answer is the right one.
     */
    $resolved = (new Container())->get(ContainerOptionalDependency::class);

    $tests->assertSame(null, $resolved->handler);
    $tests->assertSame(null, $resolved->items);

    // A binding still wins over the default.
    $container = new Container();
    $handler = new CacheHandler(new CacheManager(new MemoryDriver()));
    $container->set(SessionHandlerInterface::class, $handler);

    $tests->assertSame($handler, $container->get(ContainerOptionalDependency::class)->handler);
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

$tests->run('the package is a project somebody can start developing in', function () use ($tests): void {
    /*
     * A skeleton, not a library. `composer create-project` hands over a working
     * application — routes, a controller, views, migrations, the front
     * controller and the console at the root — because "install it and start
     * developing" is the promise, and a framework you have to wire up first is
     * not that.
     */
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

    $tests->assertSame('project', $composer['type']);

    // The application's namespaces are the project's, not a development extra.
    foreach (['SfphpProject\\src\\' => 'src/', 'SfphpProject\\app\\' => 'app/'] as $prefix => $path) {
        $tests->assertSame($path, $composer['autoload']['psr-4'][$prefix] ?? null);
    }

    // Nothing the package loads may live outside src/.
    foreach ($composer['autoload']['files'] as $file) {
        $tests->assertTrue(str_starts_with($file, 'src/'));
        $tests->assertTrue(is_file(__DIR__ . '/../' . $file));
    }

    /*
     * Nothing is loaded through an autoload "files" entry that lives under
     * app/. That entry runs whenever this package is the root one — which it is
     * in a created project — and it used to require app/config/config.php,
     * which broke the install at autoload time, before any script could run.
     * The front controller and the console call Bootstrap::load() themselves.
     */
    foreach ($composer['autoload']['files'] ?? [] as $file) {
        $tests->assertTrue(str_starts_with($file, 'src/'));
    }

    $tests->assertSame(null, $composer['autoload-dev'] ?? null);

    /*
     * Creating a project leaves it ready to run rather than ready to configure.
     * There is no post-install hook any more: `composer require` was the path
     * that needed one, and that path is gone — it put a whole second
     * application inside the consumer's vendor/, which is exactly what this
     * layout exists to avoid.
     */
    $tests->assertSame(
        ['@php sfphp env:example', '@php sfphp assets:publish'],
        $composer['scripts']['post-create-project-cmd']
    );
    $tests->assertSame(null, $composer['scripts']['post-install-cmd'] ?? null);

    // And excluded from what a `composer require` downloads.
    $attributes = (string) file_get_contents(__DIR__ . '/../.gitattributes');

    /*
     * What stays behind is what belongs to developing the framework rather than
     * to developing with it. The application, its views, its migrations and the
     * front controller travel: they are the project.
     */
    foreach (['/tests', '/docs', '/.github'] as $directory) {
        $tests->assertTrue((bool) preg_match('#^' . preg_quote($directory, '#') . '\s+export-ignore#m', $attributes));
    }

    foreach (['/app', '/public', '/database', '/lang', '/server.php', '/.env-example'] as $shipped) {
        $tests->assertSame(0, preg_match('#^' . preg_quote($shipped, '#') . '\s+export-ignore#m', $attributes));
    }

    /*
     * tools/ used to be excluded whole, which made "SFCSS is generated from
     * sfcss.config.json" untrue for everybody who installed the framework: they
     * had the stylesheet and no way to build another. The builders travel; the
     * documentation parity check, which is about this repository's own three
     * languages, does not.
     */
    $tests->assertTrue((bool) preg_match('#^/tools/docs-parity\.php\s+export-ignore#m', $attributes));
    $tests->assertSame(0, preg_match('#^/tools\s+export-ignore#m', $attributes));
});

$tests->run('the framework reads no constant it did not define itself', function () use ($tests): void {
    /*
     * Every setting the framework consults has to survive being absent, because
     * an application that installs the package may never call Bootstrap::load()
     * — or may call it after something has already asked. A bare `defined()`
     * check is the whole contract, and this asserts nobody added a read without
     * one.
     */
    $unguarded = [];
    $names = 'APP_NAME|APP_VERSION|APP_ENV|APP_LOCALE|APP_LOCALES|APP_TIMEZONE'
        . '|LOG_CHANNEL|LOG_PATH|LOG_LEVEL'
        . '|SESSION_DRIVER|SESSION_LIFETIME|SESSION_ABSOLUTE_LIFETIME|SESSION_TABLE';

    $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src'));

    foreach ($directory as $file) {
        if ($file->getExtension() !== 'php'
            || in_array($file->getFilename(), ['Bootstrap.php', 'Config.php'], true)) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            // Comments and docblocks name these constants while explaining them.
            $code = trim($line);

            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }

            /*
             * Two forms are safe: a defined() guard, and a read through
             * Config, which falls back to a default when the constant was
             * never defined. Anything else is a framework file assuming an
             * application set something up for it.
             */
            $guarded = str_contains($line, 'defined(') || str_contains($line, 'Config::');

            if (preg_match('/\b(' . $names . ')\b/', $line) === 1 && !$guarded) {
                $unguarded[] = basename($file->getPathname()) . ':' . ($number + 1);
            }
        }
    }

    $tests->assertSame([], $unguarded);
});

$tests->run('bootstrap registers the application paths without owning them', function () use ($tests): void {
    $base = sys_get_temp_dir() . '/sfphp-bootstrap-' . bin2hex(random_bytes(6));
    mkdir($base . '/views', 0777, true);
    file_put_contents($base . '/views/greeting.sfht', 'hello {{ $name }}');

    Bootstrap::load($base, ['env' => null, 'views' => 'views', 'lang' => null]);

    $tests->assertSame($base, Bootstrap::basePath());
    $tests->assertSame($base . DIRECTORY_SEPARATOR . 'views', Bootstrap::basePath('views'));

    // An absolute path is left alone rather than joined to the root twice.
    $tests->assertSame('/etc/sfphp', Bootstrap::basePath('/etc/sfphp'));

    /*
     * The view layer used to reach into "../app/resources/views" from inside
     * src/, which only worked while the framework and the application were the
     * same checkout.
     */
    $tests->assertSame('hello Ana', View::make('greeting', ['name' => 'Ana']));

    // Put this checkout back, for anything running after.
    Bootstrap::load(dirname(__DIR__), ['env' => null]);

    unlink($base . '/views/greeting.sfht');
    rmdir($base . '/views');
    rmdir($base);
});

$tests->run('the console finds the project autoloader, not its own', function () use ($tests): void {
    /*
     * Found by installing the package into a throwaway project. A path
     * repository copies the working tree, so the package arrived carrying the
     * framework's own vendor/ — and the binary tried that one first, loaded an
     * autoloader built for a different checkout, and died on a dev file the
     * package does not even ship.
     *
     * Composer's binary proxy already publishes the right answer, so the fix is
     * to ask it, and to put the local checkout last among the guesses.
     */
    $binary = (string) file_get_contents(__DIR__ . '/../sfphp');

    $composerGlobal = strpos($binary, "_composer_autoload_path");
    $tests->assertTrue($composerGlobal !== false);

    $positions = [];

    foreach ([
        'installed_sibling' => "__DIR__ . '/../../autoload.php'",
        'installed_bin' => "__DIR__ . '/../autoload.php'",
        'local_checkout' => "__DIR__ . '/vendor/autoload.php'",
    ] as $name => $needle) {
        $at = strpos($binary, $needle);
        $tests->assertTrue($at !== false);
        $positions[$name] = $at;
    }

    // Composer's own answer is consulted before any guess.
    $tests->assertTrue($composerGlobal < $positions['installed_sibling']);

    // And the checkout is the last guess, never the first.
    $tests->assertTrue($positions['local_checkout'] > $positions['installed_sibling']);
    $tests->assertTrue($positions['local_checkout'] > $positions['installed_bin']);

    // The package must not carry a vendor/ of its own into a consumer.
    $attributes = (string) file_get_contents(__DIR__ . '/../.gitattributes');
    $tests->assertTrue((bool) preg_match('#^/vendor\s+export-ignore#m', $attributes));
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

$tests->run('a message renders the headers and body a mail server expects', function () use ($tests): void {
    $rendered = (new Message())
        ->from('nao-responda@exemplo.com', 'Loja')
        ->to('ana@exemplo.com', 'Ana')
        ->subject('Pedido confirmado')
        ->text('Obrigado.')
        ->toString();

    $tests->assertTrue(str_contains($rendered, 'From: Loja <nao-responda@exemplo.com>'));
    $tests->assertTrue(str_contains($rendered, 'To: Ana <ana@exemplo.com>'));
    $tests->assertTrue(str_contains($rendered, 'Subject: Pedido confirmado'));
    $tests->assertTrue(str_contains($rendered, 'Content-Type: text/plain; charset=UTF-8'));

    // Every line ends CRLF, which is what the protocol requires.
    $tests->assertSame(0, preg_match('/(?<!\r)\n/', $rendered));

    $body = substr($rendered, strpos($rendered, "\r\n\r\n") + 4);
    $tests->assertSame('Obrigado.', base64_decode(trim($body)));
});

$tests->run('headers that are not ASCII are encoded, not sent raw', function () use ($tests): void {
    $rendered = (new Message())
        ->from('a@exemplo.com', 'Ação Imediata')
        ->to('b@exemplo.com', 'João Gonçalves')
        ->subject('Confirmação de inscrição')
        ->text('Olá João. 日本語')
        ->toString();

    /*
     * A mail header is ASCII on the wire. Sending UTF-8 raw is what produces a
     * subject line of mojibake in half the clients, so anything outside ASCII
     * travels as an RFC 2047 encoded word.
     */
    $tests->assertTrue(str_contains($rendered, 'Subject: =?UTF-8?B?' . base64_encode('Confirmação de inscrição') . '?='));
    $tests->assertTrue(str_contains($rendered, '=?UTF-8?B?' . base64_encode('Ação Imediata') . '?='));

    // And no bare non-ASCII byte survives in the header block.
    $headers = substr($rendered, 0, strpos($rendered, "\r\n\r\n"));
    $tests->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $headers));

    // Pure ASCII is left alone, so a raw message stays readable.
    $plain = (new Message())->from('a@exemplo.com')->to('b@exemplo.com')->subject('Order')->text('x')->toString();
    $tests->assertTrue(str_contains($plain, 'Subject: Order'));
});

$tests->run('a line break in a header is refused, not escaped', function () use ($tests): void {
    /*
     * This is the injection. A newline in a value the caller supplied — a name
     * typed into a form — lets them append headers of their own, and "Bcc: a
     * third party" is the one that matters. Refused rather than stripped:
     * quietly sending a different message than the one asked for is the wrong
     * answer to both an attack and a mistake.
     */
    foreach ([
        fn () => (new Message())->subject("Oi\r\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->to('a@exemplo.com', "Ana\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->header('X-Custom', "a\r\nBcc: vitima@exemplo.com"),
        fn () => (new Message())->attach("nota\r\n.txt", 'x'),
    ] as $attempt) {
        $tests->assertThrows($attempt, InvalidArgumentException::class);
    }

    // A malformed address is refused too, before it reaches a server.
    $tests->assertThrows(fn () => (new Message())->to('não é um endereço'), InvalidArgumentException::class);
});

$tests->run('bcc reaches the server and never reaches a header', function () use ($tests): void {
    $message = (new Message())
        ->from('a@exemplo.com')
        ->to('ana@exemplo.com')
        ->cc('copia@exemplo.com')
        ->bcc('oculto@exemplo.com')
        ->subject('x')
        ->text('y');

    // Delivery uses this list, and it carries the blind copy.
    $tests->assertSame(
        ['ana@exemplo.com', 'copia@exemplo.com', 'oculto@exemplo.com'],
        $message->recipients()
    );

    /*
     * The rendered message must not mention it. Writing a Bcc header would show
     * every blind recipient to everyone else, which is the one thing Bcc
     * promises not to do.
     */
    $rendered = $message->toString();
    $tests->assertTrue(str_contains($rendered, 'Cc: copia@exemplo.com'));
    $tests->assertSame(false, str_contains($rendered, 'oculto@exemplo.com'));
});

$tests->run('text and html travel as alternatives, attachments as parts', function () use ($tests): void {
    $both = (new Message())
        ->from('a@exemplo.com')->to('b@exemplo.com')->subject('x')
        ->text('versão texto')->html('<p>versão HTML</p>')
        ->toString();

    $tests->assertTrue(str_contains($both, 'Content-Type: multipart/alternative'));
    $tests->assertTrue(str_contains($both, 'Content-Type: text/plain; charset=UTF-8'));
    $tests->assertTrue(str_contains($both, 'Content-Type: text/html; charset=UTF-8'));

    $withFile = (new Message())
        ->from('a@exemplo.com')->to('b@exemplo.com')->subject('x')
        ->text('corpo')
        ->attach('nota.txt', 'conteúdo', 'text/plain')
        ->toString();

    $tests->assertTrue(str_contains($withFile, 'Content-Type: multipart/mixed'));
    $tests->assertTrue(str_contains($withFile, 'Content-Disposition: attachment; filename="nota.txt"'));
    $tests->assertTrue(str_contains($withFile, base64_encode('conteúdo')));

    // A message with no sender or no recipient says so instead of going out broken.
    $tests->assertThrows(
        fn () => (new Message())->to('b@exemplo.com')->toString(),
        InvalidArgumentException::class
    );
    $tests->assertThrows(
        fn () => (new Message())->from('a@exemplo.com')->toString(),
        InvalidArgumentException::class
    );
});

$tests->run('the manager fills in the sender and can redirect everything', function () use ($tests): void {
    $driver = new MailArrayDriver();
    $mailer = new MailManager($driver, 'nao-responda@exemplo.com', 'Loja');

    $mailer->send((new Message())->to('ana@exemplo.com')->subject('x')->text('y'));

    $tests->assertSame(
        ['address' => 'nao-responda@exemplo.com', 'name' => 'Loja'],
        $driver->last()->sender()
    );

    // A message that names its own sender keeps it.
    $mailer->send((new Message())->from('outro@exemplo.com')->to('ana@exemplo.com')->subject('x')->text('y'));
    $tests->assertSame('outro@exemplo.com', $driver->last()->sender()['address']);

    /*
     * Redirecting is for a staging environment working from a copy of
     * production data, where the addresses in the database belong to real
     * people. The intended recipient is kept so the message still says who it
     * was for.
     */
    $driver->flush();
    $mailer->alwaysTo('equipe@exemplo.com');
    $mailer->send((new Message())->to('cliente-real@exemplo.com')->subject('x')->text('y'));

    $tests->assertSame(['equipe@exemplo.com'], $driver->last()->recipients());
    $tests->assertTrue(str_contains($driver->last()->toString(), 'X-Intended-For: cliente-real@exemplo.com'));

    $mailer->alwaysTo(null);
    $driver->flush();
    $tests->assertSame(null, $driver->last());
});

$tests->run('an upload is refused unless it really is an upload', function () use ($tests): void {
    /*
     * The check that matters most and is easiest to leave out. $_FILES can be
     * forged when a script is reachable in a way its author did not expect, and
     * a tmp_name pointing at /etc/passwd would otherwise be read and stored as
     * though the visitor had uploaded it. is_uploaded_file() is what tells the
     * two apart.
     */
    $forged = new UploadedFile('passwd', '/etc/passwd', 100);

    $tests->assertSame(false, $forged->isValid());
    $tests->assertThrows(fn () => $forged->contents(), UploadException::class);
    $tests->assertThrows(fn () => $forged->store(sys_get_temp_dir()), UploadException::class);

    // A failed upload says why, in the visitor's language.
    $tooBig = new UploadedFile('photo.jpg', '', 0, UPLOAD_ERR_INI_SIZE);
    $tests->assertSame(false, $tooBig->isValid());
    $tests->assertSame(__('upload.too_large'), $tooBig->errorMessage());

    $absent = new UploadedFile('', '', 0, UPLOAD_ERR_NO_FILE);
    $tests->assertSame(__('upload.missing'), $absent->errorMessage());

    // Nothing wrong means no message rather than an empty one.
    $tests->assertSame(null, (new UploadedFile('a', '/tmp', 1))->errorMessage());
});

$tests->run('the name a client sends cannot become a path', function () use ($tests): void {
    /*
     * "../../public/shell.php" is how an upload lands somewhere PHP is
     * executed, and "shell.php\0.png" is how it passes an extension check on
     * the way: a null byte truncates the string for anything that reaches C.
     */
    $traversal = new UploadedFile('../../public/shell.php', '/tmp/x', 1);
    $tests->assertSame('shell.php', $traversal->clientName());

    $nullByte = new UploadedFile("shell.php\x00.png", '/tmp/x', 1);
    $tests->assertSame('shell.php.png', $nullByte->clientName());
    $tests->assertSame('png', $nullByte->clientExtension());

    $windows = new UploadedFile('C:\\\\Users\\\\ana\\\\avatar.PNG', '/tmp/x', 1);
    $tests->assertSame('avatar.PNG', $windows->clientName());
    $tests->assertSame('png', $windows->clientExtension());
});

$tests->run('a stored file gets a name the framework chose', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-upload-' . bin2hex(random_bytes(6));
    $source = $directory . '/incoming';
    mkdir($directory, 0777, true);
    file_put_contents($source, 'conteúdo');

    $file = new UploadedFile('../../evil.php', $source, 8, UPLOAD_ERR_OK, trustPath: true);

    $stored = $file->store($directory . '/kept');

    /*
     * The client's name is nowhere in the path. It is random, with the
     * extension carried over only because it is plain alphanumeric.
     */
    $tests->assertSame(true, str_starts_with($stored, $directory . '/kept/'));
    $tests->assertSame(false, str_contains($stored, 'evil'));
    $tests->assertSame(1, preg_match('#/[0-9a-f]{32}\.php$#', $stored));
    $tests->assertSame('conteúdo', file_get_contents($stored));

    // A caller-supplied name is still reduced to something that is not a path.
    file_put_contents($source, 'x');
    $named = (new UploadedFile('a.txt', $source, 1, UPLOAD_ERR_OK, trustPath: true))
        ->store($directory . '/kept', '../../../etc/passwd');

    $tests->assertSame($directory . '/kept/passwd', $named);

    // And a name that reduces to nothing is refused rather than guessed at.
    file_put_contents($source, 'x');
    $tests->assertThrows(
        fn () => (new UploadedFile('a.txt', $source, 1, UPLOAD_ERR_OK, trustPath: true))
            ->store($directory . '/kept', '...'),
        UploadException::class
    );

    array_map('unlink', glob($directory . '/kept/*') ?: []);
    @unlink($source);
    @rmdir($directory . '/kept');
    @rmdir($directory);
});

$tests->run('the type is read from the bytes, not from what the client said', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-upload-' . bin2hex(random_bytes(6));
    mkdir($directory, 0777, true);

    // A PHP script announcing itself as a PNG, which is the whole attack.
    $script = $directory . '/upload';
    file_put_contents($script, "<?php echo 'owned';");
    $lying = new UploadedFile('avatar.png', $script, 19, UPLOAD_ERR_OK, trustPath: true);

    $tests->assertSame('png', $lying->clientExtension());
    $tests->assertTrue($lying->mimeType() !== 'image/png');
    $tests->assertThrows(fn () => $lying->assertType(['image/png']), UploadException::class);

    // It is not an image either, which getimagesize() decides by decoding.
    $tests->assertThrows(fn () => $lying->assertImage(), UploadException::class);

    // A real PNG passes both, and reports its size.
    $png = $directory . '/real';
    file_put_contents($png, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8z8DAwMDEwMDAwMAAAA8QAQFqKQ0kAAAAAElFTkSuQmCC'
    ));
    $real = new UploadedFile('avatar.png', $png, filesize($png), UPLOAD_ERR_OK, trustPath: true);

    $tests->assertSame('image/png', $real->mimeType());
    $real->assertType(['image/png', 'image/jpeg'])->assertImage()->assertExtension(['png', 'jpg']);
    $tests->assertSame(['width' => 2, 'height' => 2], $real->dimensions());

    // Size and extension are separate refusals with their own messages.
    $tests->assertThrows(fn () => $real->assertSmallerThan(1), UploadException::class);
    $tests->assertThrows(fn () => $real->assertExtension(['gif']), UploadException::class);

    array_map('unlink', glob($directory . '/*') ?: []);
    rmdir($directory);
});

$tests->run('a field with several files is turned the right way round', function () use ($tests): void {
    /*
     * The shape that catches people out: $_FILES['photos'] for name="photos[]"
     * is not a list of files, it is one file whose every property is a list.
     */
    $request = Request::create('POST', '/upload', ['files' => [
        'photos' => [
            'name' => ['a.png', 'b.png'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'size' => [10, 20],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ],
        'avatar' => ['name' => 'c.png', 'tmp_name' => '/tmp/c', 'size' => 30, 'error' => UPLOAD_ERR_OK],
    ]]);

    $photos = $request->files('photos');
    $tests->assertSame(2, count($photos));
    $tests->assertSame(['a.png', 'b.png'], array_map(fn (UploadedFile $f): string => $f->clientName(), $photos));
    $tests->assertSame([10, 20], array_map(fn (UploadedFile $f): int => $f->size(), $photos));

    // A single file answers file(), and a multiple one does not.
    $tests->assertSame('c.png', $request->file('avatar')?->clientName());
    $tests->assertSame(null, $request->file('photos'));
    $tests->assertSame(1, count($request->files('avatar')));

    // An absent field is empty rather than an error.
    $tests->assertSame([], $request->files('nada'));
    $tests->assertSame(null, $request->file('nada'));

    // hasFile() is about a usable file, not a present field.
    $tests->assertSame(false, $request->hasFile('nada'));
    $tests->assertSame(false, Request::create('POST', '/', ['files' => [
        'doc' => ['name' => 'x', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE],
    ]])->hasFile('doc'));
});

$tests->run('events reach their listeners, and a broken one does not stop the rest', function () use ($tests): void {
    /*
     * make:event and make:listener generated classes for four rounds with
     * nothing to dispatch them. A generator producing code for infrastructure
     * that does not exist is worse than no generator: it looks like a feature.
     */
    Dispatcher::forget();

    $event = new DispatcherEvent();
    $tests->assertSame(false, Dispatcher::hasListeners(DispatcherEvent::class));

    Dispatcher::listen(DispatcherEvent::class, static function (DispatcherEvent $e): void {
        $e->seen[] = 'closure';
    });
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);

    $tests->assertSame(true, Dispatcher::hasListeners(DispatcherEvent::class));
    $tests->assertSame($event, Dispatcher::dispatch($event));
    $tests->assertSame(['closure', 'class'], $event->seen);

    /*
     * Dispatching is telling, not asking: an event whose second listener failed
     * has still happened, so the others still run and the caller is not made to
     * handle somebody else's failure. The failure is logged instead.
     */
    Dispatcher::forget();
    $log = new LogMemoryDriver();
    logger()->driver($log);

    $resilient = new DispatcherEvent();
    Dispatcher::listen(DispatcherEvent::class, DispatcherBrokenListener::class);
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);
    Dispatcher::dispatch($resilient);

    $tests->assertSame(['class'], $resilient->seen);
    $tests->assertSame('listener failed', $log->last()['message']);
    $tests->assertSame(DispatcherEvent::class, $log->last()['context']['event']);
    $tests->assertSame(DispatcherBrokenListener::class, $log->last()['context']['listener']);

    logger()->driver(new LogNullDriver());

    // dispatchOrFail is for the caller that genuinely depends on the listeners.
    $tests->assertThrows(
        fn () => Dispatcher::dispatchOrFail(new DispatcherEvent()),
        RuntimeException::class
    );

    /*
     * Listening for a parent class catches its children, which is what makes
     * "record every domain event" expressible without naming each one.
     */
    Dispatcher::forget();
    $child = new DispatcherChildEvent();
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);
    Dispatcher::dispatch($child);
    $tests->assertSame(['class'], $child->seen);

    // A listener class with no handle() says so rather than failing silently.
    Dispatcher::forget();
    Dispatcher::listen(DispatcherEvent::class, Container::class);
    $tests->assertThrows(
        fn () => Dispatcher::dispatchOrFail(new DispatcherEvent()),
        RuntimeException::class
    );

    Dispatcher::forget();
});

$tests->run('settings can be overridden without a separate process', function () use ($tests): void {
    /*
     * They were constants, and a constant cannot be unset — fine for an
     * application that decides once at boot, awkward for a test that wants to
     * know what happens with a different value.
     */
    $tests->assertSame(APP_ENV, Config::get('APP_ENV'));

    /*
     * A value nothing else would produce. This compared against the literal
     * 'production', which held only while the checkout had no .env — so
     * creating one turned a passing test red without anything being wrong.
     */
    $constant = APP_ENV;
    Config::set('APP_ENV', 'sentinel-environment');
    $tests->assertSame('sentinel-environment', Config::get('APP_ENV'));

    // The constant is untouched; the override sits in front of it.
    $tests->assertSame($constant, APP_ENV);

    Config::forget('APP_ENV');
    $tests->assertSame(APP_ENV, Config::get('APP_ENV'));

    // A setting nobody defined answers the default rather than failing.
    $tests->assertSame('fallback', Config::get('NO_SUCH_SETTING', 'fallback'));
    $tests->assertSame(false, Config::has('NO_SUCH_SETTING'));
    $tests->assertSame(7, Config::int('NO_SUCH_SETTING', 7));
    $tests->assertSame('', Config::string('NO_SUCH_SETTING'));

    // int() and string() coerce rather than trusting what is there.
    Config::set('A_NUMBER', '42');
    $tests->assertSame(42, Config::int('A_NUMBER'));
    Config::set('A_NUMBER', ['not scalar']);
    $tests->assertSame(9, Config::int('A_NUMBER', 9));
    Config::forget();
});

$tests->run('metrics count and time, including the call that failed', function () use ($tests): void {
    Metrics::reset();

    Metrics::count('orders.placed');
    Metrics::count('orders.placed');
    Metrics::count('payments.failed', ['gateway' => 'stripe']);

    $snapshot = Metrics::snapshot();
    $tests->assertSame(2.0, $snapshot['counters']['orders.placed']['value']);
    $tests->assertSame(['gateway' => 'stripe'], $snapshot['counters']['payments.failed|{"gateway":"stripe"}']['labels']);

    // time() returns what the work returned.
    $tests->assertSame('done', Metrics::time('work', static fn (): string => 'done'));

    /*
     * And records the failed call too: something that only gets slow when it is
     * failing is exactly the thing worth seeing.
     */
    try {
        Metrics::time('work', static function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $tests->assertSame(2, Metrics::snapshot()['timers']['work']['count']);

    // The scraper format escapes what would end a label early.
    Metrics::reset();
    Metrics::count('a.metric', ['label' => 'with "quote"']);
    $tests->assertSame(true, str_contains(Metrics::prometheus(), 'with \"quote\"'));
    $tests->assertSame(true, str_starts_with(Metrics::prometheus(), 'a_metric{label='));

    Metrics::reset();
    $tests->assertSame('', Metrics::prometheus());
});

$tests->run('a health check reports each dependency and times it', function () use ($tests): void {
    Health::forget();

    Health::register('fine', static fn (): bool => true);
    $report = Health::check();
    $tests->assertSame(true, $report['healthy']);
    $tests->assertSame(true, $report['checks']['fine']['ok']);
    $tests->assertSame(true, $report['checks']['fine']['ms'] >= 0);

    // One failing dependency makes the instance unhealthy.
    Health::register('broken', static fn (): bool => false);
    $tests->assertSame(false, Health::check()['healthy']);

    /*
     * A check that throws is a failed check, and the message is reported so
     * whoever reads it knows which dependency — but the trace is not, because
     * this response may leave the deployment.
     */
    Health::forget();
    Health::register('throws', static function (): bool {
        throw new RuntimeException('connection refused');
    });

    $report = Health::check();
    $tests->assertSame(false, $report['healthy']);
    $tests->assertSame('connection refused', $report['checks']['throws']['error']);

    // A subset can be asked for, which is how a liveness probe differs from a
    // readiness one.
    Health::forget();
    Health::register('a', static fn (): bool => true);
    Health::register('b', static fn (): bool => false);
    $tests->assertSame(['a'], array_keys(Health::check(['a'])['checks']));
    $tests->assertSame(true, Health::check(['a'])['healthy']);

    Health::forget();
});

$tests->run('a remember cookie is not a password that can be replayed', function () use ($tests): void {
    $issued = RememberToken::issue();
    $parsed = RememberToken::parse($issued['cookie']);

    $tests->assertSame($issued['selector'], $parsed['selector']);
    $tests->assertSame(true, RememberToken::matches($parsed['verifier'], $issued['hash']));
    $tests->assertSame(false, RememberToken::matches('guessed', $issued['hash']));

    /*
     * The verifier is stored hashed. A database someone can read otherwise
     * hands them a working cookie for every remembered user — the column is
     * the one thing read access gets for free.
     */
    $tests->assertSame(false, str_contains($issued['hash'], $parsed['verifier']));
    $tests->assertSame(64, strlen($issued['hash']));

    // Two issues never collide, which is what makes the selector a lookup key.
    $tests->assertSame(false, RememberToken::issue()['selector'] === RememberToken::issue()['selector']);

    // A malformed cookie is refused rather than half-read.
    foreach (['', 'nocolon', ':empty', 'empty:'] as $malformed) {
        $tests->assertSame(null, RememberToken::parse($malformed));
    }

    $tests->assertSame(true, $issued['expires'] > time());
});

$tests->run('dotenv reads a file, and an absent one is not fatal when optional', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-env-' . bin2hex(random_bytes(6));

    file_put_contents($path, <<<'ENV'
        # a comment
        PLAIN=value
        QUOTED="with spaces"
        SINGLE='single quoted'
        EMPTY=
        WITH_EQUALS=a=b=c

        SPACED = padded
        ENV);

    Dotenv::loadEnv($path);

    $tests->assertSame('value', $_ENV['PLAIN']);
    $tests->assertSame('with spaces', $_ENV['QUOTED']);
    $tests->assertSame('single quoted', $_ENV['SINGLE']);
    $tests->assertSame('', $_ENV['EMPTY']);

    // A value containing "=" keeps all of it; only the first separator counts.
    $tests->assertSame('a=b=c', $_ENV['WITH_EQUALS']);
    $tests->assertSame('padded', $_ENV['SPACED']);

    // A comment is not a variable.
    $tests->assertSame(false, isset($_ENV['# a comment']));

    unlink($path);

    /*
     * Optional is why a fresh clone boots. A required .env made requiring the
     * autoloader fatal before the application could say what was missing.
     */
    Dotenv::loadEnv($path, required: false);
    $tests->assertThrows(fn () => Dotenv::loadEnv($path, required: true), RuntimeException::class);
});

$tests->run('the error handler redacts in production and explains in development', function () use ($tests): void {
    $failure = new RuntimeException('the database password is hunter2');

    Config::set('APP_ENV', 'production');
    $hidden = ErrorHandler::toResponse($failure);
    $tests->assertSame(HTTP_INTERNAL_SERVER_ERROR, $hidden->status());

    /*
     * An exception message routinely carries a connection string or a query.
     * Production gets the translated generic message and nothing else.
     */
    $tests->assertSame(false, str_contains($hidden->body(), 'hunter2'));
    $tests->assertSame(true, str_contains($hidden->body(), __('http.server_error_message')));

    Config::set('APP_ENV', 'development');
    $shown = ErrorHandler::toResponse($failure);
    $tests->assertSame(true, str_contains($shown->body(), 'hunter2'));

    // The content type follows what the request asked for.
    $json = ErrorHandler::toResponse($failure, Request::create('GET', '/', [
        'headers' => ['Accept' => 'application/json'],
    ]));
    $tests->assertSame(true, str_contains((string) $json->header('Content-Type'), 'application/json'));
    $tests->assertSame('the database password is hunter2', json_decode($json->body(), true)['message']);

    // The error page carries no external request, so it renders when the
    // network is exactly what is broken.
    $tests->assertSame(0, preg_match('#(src|href)=["\']https?://#', $shown->body()));

    Config::forget('APP_ENV');
});

$tests->run('the cache and the queue read the driver they are told to use', function () use ($tests): void {
    /*
     * Both used to hardcode their driver, and neither read any setting. The
     * consequence was not a missing feature: it was that the token denylist,
     * the rate limit counters and cache-backed sessions were all kept per
     * machine, silently, with no way to change it short of writing code.
     */
    Config::set('CACHE_DRIVER', 'array');
    $tests->assertSame(MemoryDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::set('CACHE_DRIVER', 'file');
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    // An unknown name falls back to the file driver rather than failing: a
    // typo in .env should not take an application down.
    Config::set('CACHE_DRIVER', 'nosuchdriver');
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::set('QUEUE_DRIVER', 'database');
    $tests->assertSame(DatabaseDriver::class, get_class(QueueManager::fromConfig()->getDriver()));

    Config::forget('CACHE_DRIVER');
    Config::forget('QUEUE_DRIVER');

    // The defaults are what an application gets when it says nothing.
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));
    $tests->assertSame(DatabaseDriver::class, get_class(QueueManager::fromConfig()->getDriver()));
});

$tests->run('redis is selected through the shared connection, or refused out loud', function () use ($tests): void {
    if (!extension_loaded('redis')) {
        /*
         * Selecting redis without the extension must fail rather than quietly
         * handing back a file driver. An operator who believes revocation is
         * shared between instances when it is not has a security hole that
         * shows up months later and never as an error.
         */
        Config::set('CACHE_DRIVER', 'redis');
        $tests->assertThrows(fn () => CacheManager::fromConfig(), RuntimeException::class);
        Config::forget('CACHE_DRIVER');

        return;
    }

    // With the extension present, a connection the application supplies is
    // used as it is — no second socket to the same server.
    $fake = new \Redis();
    RedisConnection::use($fake);
    $tests->assertSame(true, RedisConnection::isConnected());
    $tests->assertSame($fake, RedisConnection::get());

    Config::set('CACHE_DRIVER', 'redis');
    $tests->assertSame(CacheRedisDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::forget('CACHE_DRIVER');
    RedisConnection::use(null);
});

$tests->run('a dump describes a value without following it forever', function () use ($tests): void {
    $node = new class {
        public string $name = 'root';
        protected int $depth = 2;
        private array $tags = ['a', 'b'];
        public ?object $self = null;
        public int $uninitialised;
    };
    $node->self = $node;

    $described = Dumper::describe($node);

    $tests->assertSame('object', $described['type']);

    $by = [];

    foreach ($described['children'] as $child) {
        $by[$child['key']] = $child;
    }

    // Visibility is part of what a dump is for: a private property read as
    // public sends somebody looking in the wrong place.
    $tests->assertSame('public', $by['name']['visibility']);
    $tests->assertSame('protected', $by['depth']['visibility']);
    $tests->assertSame('private', $by['tags']['visibility']);

    // A value that points at itself is reported, not followed.
    $tests->assertSame(true, $by['self']['value']['circular'] ?? false);

    // A typed property with no value throws when read. That is a state worth
    // showing rather than an error worth propagating.
    $tests->assertSame('uninitialised', $by['uninitialised']['value']['type']);
});

$tests->run('a dump states what it had to cut', function () use ($tests): void {
    $long = str_repeat('a', Dumper::MAX_STRING + 50);
    $string = Dumper::describe($long);

    $tests->assertSame(true, $string['truncated']);
    $tests->assertSame(Dumper::MAX_STRING + 50, $string['length']);
    $tests->assertSame(Dumper::MAX_STRING, strlen($string['value']));

    // Depth is capped, and the cap is reported rather than silently flattened.
    $deep = 'bottom';

    for ($i = 0; $i < Dumper::MAX_DEPTH + 3; $i++) {
        $deep = [$deep];
    }

    $described = Dumper::describe($deep);

    for ($i = 0; $i < Dumper::MAX_DEPTH; $i++) {
        $described = $described['children'][0]['value'];
    }

    $tests->assertSame(true, $described['deep'] ?? false);

    // A string that is not valid UTF-8 is reported as bytes rather than being
    // put into an HTML page, where it would produce a blank screen.
    $tests->assertSame(true, Dumper::describe("\xff\xfe")['binary']);
});

$tests->run('the dump screen is SFCSS, escaped, and asks nothing of the network', function () use ($tests): void {
    $html = HtmlDump::render([['<script>alert(1)</script>' => "it's \"quoted\""]], [
        'file' => '/app/routes.php',
        'line' => 12,
    ]);

    // Escaped: a dump renders values an attacker may control.
    $tests->assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    $tests->assertSame(true, str_contains($html, '&lt;script&gt;'));
    $tests->assertSame(true, str_contains($html, '&quot;quoted&quot;'));

    // SFCSS, inlined rather than linked: the screen has to render when the
    // application around it is what is broken.
    $tests->assertSame(true, str_contains($html, 'class="card mb-4"'));
    $tests->assertSame(true, str_contains($html, '.card-header'));
    $tests->assertSame(0, preg_match('#(src|href)=["\']https?://#', $html));
    $tests->assertSame(0, preg_match('#<link\b#', $html));

    // Where it was called from, because a dump you cannot locate is a riddle.
    $tests->assertSame(true, str_contains($html, 'routes.php:12'));
});

$tests->run('dump appends a fragment, and the stylesheet only once', function () use ($tests): void {
    /*
     * dump() writes into a response that is already being written. A second
     * <!DOCTYPE html> in the middle of a document is malformed, and repeating
     * ninety kilobytes of stylesheet for every call in a loop is its own
     * problem.
     */
    HtmlDump::forgetStylesheet();

    $first = HtmlDump::fragment([['a' => 1]], ['file' => '/app/x.php', 'line' => 3]);

    $tests->assertSame(false, str_contains($first, '<!DOCTYPE'));
    $tests->assertSame(false, str_contains($first, '<body'));
    $tests->assertSame(true, str_contains($first, 'sf-dump-fragment'));
    $tests->assertSame(true, str_contains($first, '<style>'));
    $tests->assertSame(true, str_contains($first, 'x.php:3'));

    $second = HtmlDump::fragment([['b' => 2]]);

    // The second one is cards and nothing else.
    $tests->assertSame(false, str_contains($second, '<style>'));
    $tests->assertSame(true, str_contains($second, 'sf-dump-fragment'));

    /*
     * Under a persistent runtime the flag would otherwise carry into the next
     * request and the second visitor would get an unstyled dump.
     */
    HtmlDump::forgetStylesheet();
    $tests->assertSame(true, str_contains(HtmlDump::fragment([1]), '<style>'));

    // dd()'s page is still a whole document.
    $tests->assertSame(true, str_contains(HtmlDump::render([1]), '<!DOCTYPE'));
});

$tests->run('a dump renders for a terminal too, without colour when redirected', function () use ($tests): void {
    $plain = TextDump::render([['a' => 1, 'b' => [true, null]]], null, false);

    $tests->assertSame(true, str_contains($plain, '"a" => 1'));
    $tests->assertSame(true, str_contains($plain, 'array ['));
    // No escape codes when colour is off: piped into a file they are noise.
    $tests->assertSame(false, str_contains($plain, "\033["));

    $coloured = TextDump::render([1], null, true);
    $tests->assertSame(true, str_contains($coloured, "\033["));
});

$tests->run('the environment is read wherever PHP put it', function () use ($tests): void {
    /*
     * The framework read $_ENV alone, and variables_order decides whether PHP
     * fills it — php.ini-production leaves the E out. On such a host a
     * container started with -e DB_HOST=... passed a value nothing could see,
     * and the failure was a default being used silently.
     */
    $key = 'SFPHP_ENV_PROBE_' . bin2hex(random_bytes(4));

    $tests->assertSame(false, Env::has($key));
    $tests->assertSame('fallback', Env::get($key, 'fallback'));

    putenv($key . '=from-getenv');
    $tests->assertSame(true, Env::has($key));
    $tests->assertSame('from-getenv', Env::get($key));

    // $_ENV wins, being the nearest source.
    $_ENV[$key] = 'from-env-superglobal';
    $tests->assertSame('from-env-superglobal', Env::get($key));

    // An environment variable is always a string, so the words everybody
    // writes for nothing have to mean nothing.
    $_ENV[$key] = 'null';
    $tests->assertSame(null, Env::get($key));
    $_ENV[$key] = 'false';
    $tests->assertSame('', Env::get($key));

    unset($_ENV[$key]);
    putenv($key);
});

$tests->run('the framework ships its stylesheet and script, and can publish them', function () use ($tests): void {
    /*
     * SFCSS and SFJS are tools the framework ships, not files of the example
     * application, so they live where a composer require can reach them.
     */
    foreach (Assets::files() as $relative) {
        $tests->assertSame(true, is_file(Assets::path() . '/' . $relative));
    }

    $tests->assertSame(true, str_contains(Assets::css(), '.card'));
    $tests->assertSame(true, str_contains(Assets::js(), 'function') || Assets::js() !== '');

    $target = sys_get_temp_dir() . '/sfphp-assets-' . bin2hex(random_bytes(4));

    try {
        $written = Assets::publish($target)['written'];
        $tests->assertSame(Assets::files(), $written);
        $tests->assertSame(true, is_file($target . '/css/sfcss.min.css'));

        // Publishing twice copies nothing: reporting work that did not happen
        // is how a command stops being believed.
        $tests->assertSame([], Assets::publish($target)['written']);
        $tests->assertSame(Assets::files(), Assets::publish($target, true)['written']);

        /*
         * A stylesheet somebody built from their own config lives here. The
         * next composer install runs publish, and overwriting it would throw
         * their palette away silently — the worst way to lose work.
         */
        file_put_contents($target . '/css/sfcss.css', '/* mine */');
        $again = Assets::publish($target);

        $tests->assertSame(['css/sfcss.css'], $again['kept']);
        $tests->assertSame('/* mine */', file_get_contents($target . '/css/sfcss.css'));

        // Asking for it explicitly does replace it.
        $tests->assertSame(true, in_array('css/sfcss.css', Assets::publish($target, true)['written'], true));

        /*
         * Copying means the same bytes exist twice: once in the package and
         * once under a document root a browser can reach. Where symbolic links
         * work, that can be one file instead — which is the answer to why there
         * appear to be two.
         */
        $tests->assertThrows(fn () => Assets::link($target), RuntimeException::class);

        $tests->assertSame(['css', 'js'], Assets::link($target, true));
        $tests->assertSame(true, is_link($target . '/css'));
        $tests->assertSame(
            md5_file(Assets::path() . '/css/sfcss.min.css'),
            md5_file($target . '/css/sfcss.min.css')
        );

        // Linking twice is not an error and not work.
        $tests->assertSame([], Assets::link($target));
    } finally {
        foreach (['css', 'js'] as $directory) {
            if (is_link($target . '/' . $directory)) {
                @unlink($target . '/' . $directory);
            }
        }

        foreach (Assets::files() as $relative) {
            @unlink($target . '/' . $relative);
        }

        @rmdir($target . '/css');
        @rmdir($target . '/js');
        @rmdir($target);
    }
});

$tests->run('the published package is a project that runs out of the box', function () use ($tests): void {
    /*
     * What somebody receives is the git archive, which honours the
     * export-ignore rules in .gitattributes — not what is in the repository.
     * The two drift silently: a directory added here appears in every created
     * project until somebody notices, and a rule that stops matching removes
     * something the project needs to run.
     */
    $root = dirname(__DIR__);

    if (!is_dir($root . '/.git')) {
        // An exported copy has no history to archive. Nothing to check.
        return;
    }

    /*
     * The index rather than HEAD. What gets published is a commit, but running
     * against HEAD means a shipped file reads as missing for as long as it is
     * staged and not yet committed — the test would be red during exactly the
     * change it exists to check.
     */
    $tree = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' write-tree 2>/dev/null'));
    $ref = $tree === '' ? 'HEAD' : $tree;

    $command = 'git -C ' . escapeshellarg($root) . ' archive --format=tar ' . escapeshellarg($ref)
        . ' 2>/dev/null | tar -t 2>/dev/null';
    $listing = shell_exec($command);

    if (!is_string($listing) || trim($listing) === '') {
        // No git or no tar on this machine; the CI job has both.
        return;
    }

    $entries = array_filter(explode("\n", trim($listing)));
    $top = array_values(array_unique(array_map(
        static fn (string $path): string => explode('/', $path)[0],
        $entries
    )));
    sort($top);

    $tests->assertSame(
        [
            /*
             * The editor settings travel too. Language associations for .phpx
             * and .sfht are the project's rather than a person's — without them
             * a contributor opens a component and sees uncoloured text and
             * false syntax errors.
             */
            '.editorconfig', '.env-example', '.vscode', '.zed',
            'LICENSE', 'README.md', 'app', 'composer.json', 'database',
            'lang', 'public', 'resources', 'server.php', 'sfphp', 'src', 'tools',
        ],
        $top
    );

    // The pieces a consumer actually needs, named rather than assumed.
    foreach ([
        'src/Bootstrap.php',
        'src/helpers.php',
        'src/I18n/lang/en/http.php',
        'resources/assets/css/sfcss.min.css',
        'resources/assets/js/sfjs.js',
        'resources/assets/js/sfjs.min.js',
        // Without these, "generated from a config" is not true for anybody who
        // installed the framework rather than cloning it.
        'tools/css-builder/sfcss-builder.php',
        'tools/css-builder/sfcss.config.json',
        'tools/css-builder/sfcss-base.css',
        'tools/js-builder/sfjs-builder.php',
        // The application: what makes this a project rather than a framework
        // somebody still has to assemble.
        'public/index.php',
        'server.php',
        'src/routes.php',
        'app/controllers/MainController.php',
        'app/resources/views/home.sfht',
        'database/migrations/2026_09_21_000001_create_users_table.php',
    ] as $needed) {
        $tests->assertSame(true, in_array($needed, $entries, true));
    }

    // The console is run as a command, so it has to arrive executable.
    $mode = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -s sfphp 2>/dev/null');
    $tests->assertSame(true, is_string($mode) && str_starts_with(trim((string) $mode), '100755'));
});

$tests->run('the minified script is still a program, and still the same one', function () use ($tests): void {
    /*
     * A minifier that is wrong produces a file that looks fine in a directory
     * listing and breaks every page that loads it. SFJS has no regex literals,
     * which is what makes a minifier this small safe — and this is what would
     * notice if that stopped being true.
     */
    $readable = Assets::path() . '/js/sfjs.js';
    $minified = Assets::path() . '/js/sfjs.min.js';

    $tests->assertSame(true, is_file($minified));
    $tests->assertSame(true, filesize($minified) < filesize($readable));

    // Comments went, the code did not.
    $source = file_get_contents($minified);
    $tests->assertSame(false, str_contains($source, 'HTMX-like AJAX'));
    $tests->assertSame(true, str_contains($source, 'const sf'));

    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        // No JavaScript engine here; the CI runner has one.
        return;
    }

    /*
     * Both files, because only the minified one used to be checked — and a
     * mistake in the readable source is a mistake in every copy of it. One
     * arrived this way: a const that redeclared the parameter it sat next to.
     */
    foreach ([$readable, $minified] as $script) {
        $status = 0;
        $output = [];
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $tests->assertSame(0, $status);
    }
});

$tests->run('one action answers a fragment and a whole page', function () use ($tests): void {
    /*
     * The pattern the example application had written by hand: SFJS asks for
     * the piece that changed, a browser with no JavaScript submits the same
     * form and needs the page around it. Written twice, the two answers drift
     * apart — so this is one call with the page as a wrapper.
     */
    $panel = new SfphpProject\src\View\Sfht('<p>inner</p>');
    $page = static fn (SfphpProject\src\View\Sfht $inner): string => '<html>' . $inner . '</html>';

    $xhr = Request::create('GET', '/panel', ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
    $plain = Request::create('GET', '/panel');

    $tests->assertSame(true, $xhr->isFragment());
    $tests->assertSame(false, $plain->isFragment());

    $tests->assertSame('<p>inner</p>', Response::fragment($xhr, $panel, page: $page)->body());
    $tests->assertSame('<html><p>inner</p></html>', Response::fragment($plain, $panel, page: $page)->body());

    // With no page to fall back on, both get the fragment.
    $tests->assertSame('<p>inner</p>', Response::fragment($plain, $panel)->body());
});

$tests->run('the state helper encodes values an attribute can carry', function () use ($tests): void {
    /*
     * A plain string on purpose. {{ }} escapes it, so the quotes JSON needs
     * become entities inside the attribute and the browser hands them back
     * intact. Returning Sfht would put raw quotes in an attribute, which is
     * how markup breaks — or, with a value that came from a visitor, how an
     * attribute is forged.
     */
    $tests->assertSame('{"open":false,"items":[1,2]}', state(['open' => false, 'items' => [1, 2]]));

    // Unicode and slashes stay readable rather than turning into escapes.
    $tests->assertSame('{"name":"José","path":"a/b"}', state(['name' => 'José', 'path' => 'a/b']));

    // Printed with {{ }}, every quote becomes an entity — attribute-safe.
    $tests->assertSame(
        '{&quot;a&quot;:&quot;b&quot;}',
        SfphpProject\src\View\Compiler::text(state(['a' => 'b']))
    );
});

$tests->run('client state survives a refresh that came from the server', function () use ($tests): void {
    /*
     * The two halves of the feature meet here, and they disagreed. A panel
     * that refreshes itself is morphed against the markup the server sent,
     * which replaces the nodes the bindings pointed at — so afterwards the
     * state said "closed" while the page showed "open", silently, which is
     * worse than either failing.
     *
     * The scope now collects its bindings again, keeping the state it had,
     * and an element that survived the swap does not end up listening twice.
     */
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-state-swap-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $page = $directory . '/harness.html';
    $script = Assets::path() . '/js/sfjs.min.js';

    file_put_contents($page, <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <div id="panel" \x40state="{ open: true, n: 0 }" \x40get="/fragment" \x40trigger="load"
         \x40target="#panel" \x40swap="morph">
      <span id="mark">old</span>
      <p id="body" \x40show="open">visible</p>
      <button id="plus" \x40on:click="n = n + 1">+</button>
      <span id="count" \x40text="n"></span>
    </div>
    <div id="log">nothing happened</div>
    <script>
      window.fetch = () => Promise.resolve({
        ok: true, status: 200,
        text: () => Promise.resolve(
          '<span id="mark">new</span>'
          + '<p id="body" \x40show="open">visible</p>'
          + '<button id="plus" \x40on:click="n = n + 1">+</button>'
          + '<span id="count" \x40text="n"></span>'
        )
      });
    </script>
    <script src="file://{$script}"></script>
    <script>
      window.addEventListener('load', async () => {
        const q = (id) => document.getElementById(id);

        // Close it before the server's answer arrives.
        q('panel').__sfState.open = false;

        await new Promise((resolve) => setTimeout(resolve, 300));

        q('plus').click();

        q('log').textContent = [
          'mark=' + q('mark').textContent,
          'hidden=' + q('body').hidden,
          'state=' + q('panel').__sfState.open,
          'clicks=' + q('count').textContent
        ].join(' | ');
      });
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=4000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        if (!str_contains($dom, 'id="log"')) {
            return;
        }

        preg_match('/<div id="log">([^<]*)</', $dom, $matches);
        $log = $matches[1] ?? '';

        // The server's markup did arrive.
        $tests->assertTrue(str_contains($log, 'mark=new'));

        // And the state the visitor had set is still in force afterwards.
        $tests->assertTrue(str_contains($log, 'hidden=true'));
        $tests->assertTrue(str_contains($log, 'state=false'));

        // One click counts once: the swap did not leave a second listener.
        $tests->assertTrue(str_contains($log, 'clicks=1'));
    } finally {
        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
});

$tests->run('a scope holds state in the browser, and the page follows it', function () use ($tests): void {
    /*
     * Interface state — open, selected, half-typed — belongs in the page:
     * asking a server whether a menu is open spends thirty milliseconds on a
     * decision that takes none. This is the whole feature in one harness,
     * because none of it is observable from PHP.
     *
     * The expressions are parsed rather than eval()'d, so this also stands as
     * the check that the grammar covers what the documentation promises.
     */
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-state-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $page = $directory . '/harness.html';
    $script = Assets::path() . '/js/sfjs.min.js';
    $initial = state(['open' => false, 'name' => '', 'items' => 3, 'user' => null, 'busy' => false]);
    $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    file_put_contents($page, <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <div \x40state="{$initial}">
      <button id="toggle" \x40on:click="open = !open">Toggle</button>
      <div id="panel" \x40show="open">visible</div>
      <input id="field" \x40model="name">
      <span id="greeting" \x40text="'Hello, ' + name"></span>
      <span id="doubled" \x40text="items * 2"></span>
      <span id="styled" class="base" \x40class="open ? 'on' : 'off'"></span>
      <button id="load" \x40get="/api/user" \x40into="user" \x40loading="busy">Load</button>
      <span id="loaded" \x40text="user.name"></span>
      <span id="busy" \x40text="busy ? 'busy' : 'idle'"></span>
    </div>
    <div id="log">nothing happened</div>
    <script>
      window.fetch = () => new Promise((resolve) => setTimeout(() => resolve({
        ok: true, status: 200, text: () => Promise.resolve('{"name":"Ana"}')
      }), 30));
    </script>
    <script src="file://{$script}"></script>
    <script>
      window.addEventListener('load', async () => {
        const q = (id) => document.getElementById(id);
        const steps = [];

        steps.push('hidden=' + q('panel').hidden);
        steps.push('class=' + q('styled').className);

        q('toggle').click();
        steps.push('shown=' + !q('panel').hidden);
        steps.push('class2=' + q('styled').className);

        q('field').value = 'Fabio';
        q('field').dispatchEvent(new Event('input'));
        steps.push('greeting=' + q('greeting').textContent);
        steps.push('doubled=' + q('doubled').textContent);

        q('load').click();
        steps.push('during=' + q('busy').textContent);
        await new Promise((resolve) => setTimeout(resolve, 300));
        steps.push('loaded=' + q('loaded').textContent);
        steps.push('after=' + q('busy').textContent);

        q('log').textContent = steps.join(' | ');
      });
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=4000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        if (!str_contains($dom, 'id="log"')) {
            return;
        }

        preg_match('/<div id="log">([^<]*)</', $dom, $matches);
        $log = $matches[1] ?? '';

        // @show follows a boolean through the hidden attribute, and @on:click can flip it.
        $tests->assertTrue(str_contains($log, 'hidden=true'));
        $tests->assertTrue(str_contains($log, 'shown=true'));

        // @class adds to the element's own classes rather than replacing them.
        $tests->assertTrue(str_contains($log, 'class=base off'));
        $tests->assertTrue(str_contains($log, 'class2=base on'));

        // @model writes into the state, and @text reads an expression back.
        $tests->assertTrue(str_contains($log, 'greeting=Hello, Fabio'));
        $tests->assertTrue(str_contains($log, 'doubled=6'));

        // @into puts the answer in the state; @loading brackets the request.
        $tests->assertTrue(str_contains($log, 'during=busy'));
        $tests->assertTrue(str_contains($log, 'loaded=Ana'));
        $tests->assertTrue(str_contains($log, 'after=idle'));
    } finally {
        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
});

$tests->run('morph updates a panel without throwing away what is being typed', function () use ($tests): void {
    /*
     * Why morph is the default rather than an option. A panel that refreshes on
     * a period contains a form somebody is filling in; replacing the markup
     * throws away the focus, the caret and anything typed and not yet sent, and
     * nothing warns anybody. This asks for no strategy at all, so a change of
     * default is what it would catch. Measured against innerHTML by hand, the same swap loses
     * the focus, the caret and the text; here the strategy is asserted on its
     * own, because two panels in one page end up with duplicate ids and the
     * assertions start reading the wrong element.
     */
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-morph-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $page = $directory . '/harness.html';
    $script = Assets::path() . '/js/sfjs.min.js';

    file_put_contents($page, <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <div id="morphed"><h2 id="heading">Count: 1</h2><input id="field" name="note" value=""></div>
    <div id="log">nothing happened</div>
    <script>
      window.fetch = () => Promise.resolve({
        ok: true, status: 200,
        text: () => Promise.resolve('<h2 id="heading">Count: 2</h2><input id="field" name="note" value="">')
      });
    </script>
    <script src="file://{$script}"></script>
    <script>
      window.addEventListener('load', async () => {
        const headingBefore = document.getElementById('heading');
        const field = document.getElementById('field');
        field.focus(); field.value = 'typing'; field.setSelectionRange(3, 3);

        // No swap named on purpose: the default is what is under test.
        await sf.ajax.get('/x', { target: '#morphed' });

        document.getElementById('log').textContent = [
          'text=' + document.getElementById('heading').textContent,
          'sameNode=' + (headingBefore === document.getElementById('heading')),
          'focus=' + ((document.activeElement || {}).id || 'lost'),
          'typed=' + document.getElementById('field').value,
          'caret=' + document.getElementById('field').selectionStart
        ].join(' | ');
      });
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=3000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        if (!str_contains($dom, 'id="log"')) {
            return;
        }

        preg_match('/<div id="log">([^<]*)</', $dom, $matches);
        $log = $matches[1] ?? '';

        // What the server sent did arrive.
        $tests->assertTrue(str_contains($log, 'text=Count: 2'));

        // And the node itself was kept rather than rebuilt.
        $tests->assertTrue(str_contains($log, 'sameNode=true'));

        // Which is why the visitor keeps the focus, the text and the caret.
        $tests->assertTrue(str_contains($log, 'focus=field'));
        $tests->assertTrue(str_contains($log, 'typed=typing'));
        $tests->assertTrue(str_contains($log, 'caret=3'));
    } finally {
        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
});

$tests->run('an element can say when it fires, and a field sends itself', function () use ($tests): void {
    /*
     * @trigger is what turns "click this" into "keep this current": a panel
     * that loads itself and refreshes on a period, a search box that asks as
     * somebody types. None of it is observable from PHP, so this drives a real
     * browser and steps aside where there is not one.
     */
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-trigger-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $page = $directory . '/harness.html';
    $script = Assets::path() . '/js/sfjs.min.js';

    file_put_contents($page, <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <div id="panel" \x40get="/tick" \x40trigger="load, every 200ms"></div>
    <input id="search" name="q" value="abc" \x40get="/search" \x40target="#out" \x40trigger="input delay:50ms">
    <form id="form" \x40post="/save" \x40target="#out" \x40trigger="submit">
      <input name="title" value="hello"><button type="submit">go</button>
    </form>
    <div id="out"></div>
    <div id="log">nothing happened</div>
    <script>
      const calls = [];
      window.fetch = (url, init) => {
        calls.push(((init && init.method) || 'GET') + ' ' + url);
        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<b>swapped</b>') });
      };
    </script>
    <script src="file://{$script}"></script>
    <script>
      window.addEventListener('load', () => {
        document.querySelector('#form button').click();
        const field = document.getElementById('search');
        field.value = 'xyz';
        field.dispatchEvent(new Event('input'));
        setTimeout(() => { document.getElementById('log').textContent = calls.join(' | '); }, 700);
      });
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=4000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        if (!str_contains($dom, 'id="log"')) {
            return;
        }

        preg_match('/<div id="log">([^<]*)</', $dom, $matches);
        $log = $matches[1] ?? '';

        // load fired once, and the period kept firing after it.
        $tests->assertTrue(substr_count($log, 'GET /tick') >= 3);

        // The form sent its fields through the new attribute name.
        $tests->assertTrue(str_contains($log, 'POST /save'));

        // The field sent what was typed, debounced into one request.
        $tests->assertTrue(str_contains($log, 'GET /search?q=xyz'));
        $tests->assertSame(1, substr_count($log, 'GET /search'));
    } finally {
        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
});

$tests->run('a declarative form sends the fields a visitor typed', function () use ($tests): void {
    /*
     * Two bugs lived here, and neither was visible from PHP. The click handler
     * walks up from whatever was clicked, so a submit button found the form,
     * prevented the default and fetched the bare action — the submit event,
     * which is the only place fields are serialised, never fired. And when it
     * did fire, form.submit() called GET with the three-argument shape the
     * other verbs use, so the fields arrived as the options object.
     *
     * A real browser is the only honest way to check that, so this runs one
     * when there is one and steps aside when there is not.
     */
    $browser = '';

    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $candidate) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($found !== '') {
            $browser = $found;
            break;
        }
    }

    if ($browser === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-sfjs-' . bin2hex(random_bytes(6));
    mkdir($directory . '/profile', 0755, true);

    $page = $directory . '/harness.html';
    $script = Assets::path() . '/js/sfjs.min.js';

    file_put_contents($page, <<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body>
    <form method="get" action="/look" \x40hxGet="/look" \x40hxTarget="#result">
      <input name="postcode" value="01001-000">
      <button type="submit">Look up</button>
    </form>
    <div id="result"></div>
    <div id="log">nothing happened</div>
    <script>
      const log = [];
      window.fetch = (url, init) => {
        log.push('FETCH ' + url);
        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<p>swapped</p>') });
      };
    </script>
    <script src="file://{$script}"></script>
    <script>
      window.addEventListener('load', () => {
        document.querySelector('button').click();
        setTimeout(() => { document.getElementById('log').textContent = log.join(' | '); }, 50);
      });
    </script>
    </body></html>
    HTML);

    try {
        $command = escapeshellarg($browser)
            . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage'
            . ' --no-first-run --no-default-browser-check --virtual-time-budget=3000'
            . ' --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null';

        $dom = (string) shell_exec($command);

        /*
         * A browser that never rendered the page says nothing about SFJS. On a
         * CI runner it can fail for reasons of its own — a sandbox it may not
         * use, a shared memory segment that is too small, a profile directory
         * it cannot write — and a test that reports those as a defect in the
         * framework is a test people learn to ignore. The marker is in the
         * page, so its absence means the page never ran.
         */
        if (!str_contains($dom, 'id="log"')) {
            return;
        }

        // The typed value left the page, which is the whole point.
        $tests->assertTrue(str_contains($dom, 'FETCH /look?postcode=01001-000'));

        // And the answer landed where the attribute said.
        $tests->assertTrue(str_contains($dom, '<p>swapped</p>'));
    } finally {
        @unlink($page);

        foreach (glob($directory . '/profile/*') ?: [] as $leftover) {
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }

        exec('rm -rf ' . escapeshellarg($directory) . ' 2>/dev/null');
    }
});

$tests->run('min and max follow the value, and length is asked for by name', function () use ($tests): void {
    /*
     * "min:18" on an age used to demand eighteen *characters*: it passed for 7
     * and failed for 21, and nothing said so. A rule whose meaning is the
     * opposite of what it reads is worse than a missing rule.
     */
    $tests->assertSame(true, Validator::validate(['age' => 21], ['age' => 'number|min:18'])->passes());
    $tests->assertSame(true, Validator::validate(['age' => 7], ['age' => 'number|min:18'])->fails());
    $tests->assertSame(true, Validator::validate(['age' => 21], ['age' => 'number|max:18'])->fails());

    // On anything that is not a number they count characters, as before.
    $tests->assertSame(true, Validator::validate(['name' => 'Jo'], ['name' => 'min:3'])->fails());
    $tests->assertSame(true, Validator::validate(['name' => 'Joana'], ['name' => 'min:3'])->passes());

    // Characters, not bytes.
    $tests->assertSame(true, Validator::validate(['name' => '日本語'], ['name' => 'min:3'])->passes());

    /*
     * A postcode is a number that is really a string, so the length rules say
     * so by name and are never read as a value.
     */
    $tests->assertSame(true, Validator::validate(['zip' => '01001'], ['zip' => 'minLength:5'])->passes());
    $tests->assertSame(true, Validator::validate(['zip' => '0100'], ['zip' => 'minLength:5'])->fails());
    $tests->assertSame(true, Validator::validate(['zip' => '010012'], ['zip' => 'maxLength:5'])->fails());

    // The message says which kind of limit failed.
    $value = Validator::validate(['age' => 7], ['age' => 'number|min:18'])->errors()['age'][0];
    $length = Validator::validate(['name' => 'Jo'], ['name' => 'min:3'])->errors()['name'][0];

    $tests->assertSame(false, str_contains($value, 'character'));
    $tests->assertSame(true, str_contains($length, 'character'));
});

$tests->run('the rules the browser checks are the rules the server enforces', function () use ($tests): void {
    /*
     * The browser validated url and pattern while the server could not, which
     * is backwards: anybody can skip the browser with a request of their own,
     * so the weaker list was the one that mattered. And three of the client's
     * documented rules never ran at all — "minLength:5" was looked up whole as
     * a rule name, not found, and the field passed.
     *
     * This compares the two lists so they cannot drift apart again.
     */
    $script = (string) file_get_contents(Assets::path() . '/js/sfjs.js');
    $start = strpos($script, 'const validate = {');
    $end = strpos($script, PHP_EOL . '  };', $start ?: 0);
    $section = substr($script, (int) $start, (int) $end - (int) $start);

    preg_match_all('/^\s{4}([a-zA-Z]+):/m', $section, $matches);
    $browser = array_values(array_unique($matches[1]));

    $server = ['required', 'email', 'url', 'number', 'alpha', 'alphanum', 'min', 'max', 'minLength', 'maxLength', 'pattern'];

    sort($browser);
    sort($server);

    $tests->assertSame($server, $browser);

    // And every one of them is a rule the server really applies.
    foreach ($server as $rule) {
        $expression = in_array($rule, ['min', 'max', 'minLength', 'maxLength'], true)
            ? $rule . ':3'
            : ($rule === 'pattern' ? 'pattern:^a$' : $rule);

        // Unknown rules throw; a known one must not, whatever the value.
        Validator::validate(['f' => 'a'], ['f' => $expression]);
    }

    $tests->assertThrows(
        static fn () => Validator::validate(['f' => 'a'], ['f' => 'inventada']),
        InvalidArgumentException::class
    );
});

$tests->run('a pattern that needs a pipe is given as an array', function () use ($tests): void {
    /*
     * Rules are pipe separated, so a pattern containing one cannot be written
     * in the string form — the separator cannot tell them apart. The array
     * form exists for exactly that, and for nothing else.
     */
    $rules = ['colour' => ['required', 'pattern:^(blue|green)$']];

    $tests->assertSame(true, Validator::validate(['colour' => 'blue'], $rules)->passes());
    $tests->assertSame(true, Validator::validate(['colour' => 'red'], $rules)->fails());

    // url is the other half of the parity that was missing.
    $tests->assertSame(true, Validator::validate(['site' => 'https://example.com'], ['site' => 'url'])->passes());
    $tests->assertSame(true, Validator::validate(['site' => 'not a url'], ['site' => 'url'])->fails());
});

$tests->run('the documented field vocabulary is the one the code accepts', function () use ($tests): void {
    /*
     * A reader asked for every type and every modifier to be listed, because
     * otherwise they are guessed at. A list written by hand is a list that
     * drifts, so this compares the documentation against the constants: adding
     * a type without documenting it fails here, in all three languages.
     */
    $draft = new ReflectionClass(SfphpProject\src\Migrations\MigrationDraft::class);

    $vocabulary = array_merge(
        $draft->getConstant('PLAIN_TYPES'),
        $draft->getConstant('SIZED_TYPES'),
        $draft->getConstant('PRECISION_TYPES'),
        $draft->getConstant('FLAGS'),
        $draft->getConstant('SHORTHANDS')
    );

    foreach (['en', 'pt-BR', 'es'] as $language) {
        $documentation = (string) file_get_contents(dirname(__DIR__) . '/docs/' . $language . '/DOCUMENTATION.md');

        foreach ($vocabulary as $word) {
            if (!str_contains($documentation, '`' . $word . '`')) {
                throw new RuntimeException(sprintf('%s does not document "%s".', $language, $word));
            }
        }

        foreach ($draft->getConstant('VALUED') as $word) {
            if (!str_contains($documentation, '`' . $word . '=`')) {
                throw new RuntimeException(sprintf('%s does not document "%s=".', $language, $word));
            }
        }
    }

    $tests->assertSame(true, true);
});

$tests->run('a request validates what it carried, not what is in a superglobal', function () use ($tests): void {
    /*
     * The documentation used to show Validator::validate($_POST, ...), which
     * contradicts the layer it sits in: a superglobal is process-wide state, so
     * a test has to fake it, a second request in the same worker inherits it,
     * and a controller written against it cannot be called twice with different
     * input.
     */
    $request = Request::create('POST', '/users', [
        'body' => ['name' => 'Jo', 'email' => 'not-an-email', 'age' => '30'],
    ]);

    $result = $request->validate([
        'name' => 'required|min:3',
        'email' => 'required|email',
        'age' => 'required|number',
    ]);

    $tests->assertSame(true, $result->fails());
    $tests->assertSame(true, isset($result->errors()['name']));
    $tests->assertSame(true, isset($result->errors()['email']));

    // The field that passed is in validated(); the ones that did not are not.
    $tests->assertSame(false, isset($result->errors()['age']));

    // Two requests, two answers, with nothing shared between them.
    $second = Request::create('POST', '/users', [
        'body' => ['name' => 'Joana', 'email' => 'joana@example.com', 'age' => '30'],
    ]);

    $tests->assertSame(true, $second->validate([
        'name' => 'required|min:3',
        'email' => 'required|email',
        'age' => 'required|number',
    ])->passes());

    // Query string counts too: a GET form is still input.
    $query = Request::create('GET', '/search?term=ab');
    $tests->assertSame(true, $query->validate(['term' => 'required|min:3'])->fails());
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

$tests->run('upgrade replaces the framework and leaves the application alone', function () use ($tests): void {
    /*
     * Tested against a project of its own, for the same reason reset is: this
     * deletes and overwrites, and a test that can destroy the checkout it runs
     * in is not a test anybody should have to trust.
     *
     * What it has to get right is the division. A file under src/ is the
     * framework's and is replaced; a language file a project added sits beside
     * the framework's and stays; a controller is never touched; and
     * public/index.php is the project's even though releases change it, so the
     * new one is written next to it rather than over it.
     */
    $root = sys_get_temp_dir() . '/sfphp-upgrade-test-' . bin2hex(random_bytes(6));
    $source = sys_get_temp_dir() . '/sfphp-upgrade-src-' . bin2hex(random_bytes(6));

    foreach ([$root, $source] as $directory) {
        foreach (['vendor', 'src/Console', 'app/controllers', 'lang', 'public', 'resources', 'tools'] as $part) {
            mkdir($directory . '/' . $part, 0755, true);
        }
    }

    // The project: an autoloader shim and a copy of the binary, as reset does.
    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
    );
    copy(dirname(__DIR__) . '/sfphp', $root . '/sfphp');
    chmod($root . '/sfphp', 0755);

    file_put_contents($root . '/src/Bootstrap.php', '<?php // the old framework');
    file_put_contents($root . '/src/Leftover.php', '<?php // a file the new release removed');
    file_put_contents($root . '/app/controllers/MineController.php', '<?php // mine');
    file_put_contents($root . '/lang/mine.json', '{"mine": true}');
    file_put_contents($root . '/lang/en.json', '{"old": true}');
    mkdir($root . '/tools/css-builder', 0755, true);
    file_put_contents($root . '/tools/css-builder/sfcss.config.json', '{"mine": true}');
    file_put_contents($root . '/public/index.php', '<?php // my front controller');
    file_put_contents($root . '/server.php', '<?php // old');

    // The release being upgraded to.
    file_put_contents($source . '/src/Bootstrap.php', '<?php // the new framework');
    file_put_contents($source . '/sfphp', '#!/usr/bin/env php' . PHP_EOL . '<?php // new binary');
    file_put_contents($source . '/server.php', '<?php // new');
    file_put_contents($source . '/lang/en.json', '{"new": true}');
    mkdir($source . '/tools/css-builder', 0755, true);
    file_put_contents($source . '/tools/css-builder/sfcss.config.json', '{"theirs": true}');
    file_put_contents($source . '/public/index.php', '<?php // the new front controller');

    $binary = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/sfphp');
    $from = ' --from=' . escapeshellarg($source);

    try {
        // A dry run says what it would do and does none of it.
        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' --dry-run 2>&1', $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(str_contains(implode("\n", $output), 'nothing was changed'));
        $tests->assertSame('<?php // the old framework', file_get_contents($root . '/src/Bootstrap.php'));

        // With no terminal to answer at, it refuses rather than proceeding.
        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' < /dev/null 2>&1', $output, $status);

        $tests->assertSame(1, $status);

        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' --force 2>&1', $output, $status);
        clearstatcache(true);

        $tests->assertSame(0, $status);

        // The framework is the new one, and what the release dropped is gone.
        $tests->assertSame('<?php // the new framework', file_get_contents($root . '/src/Bootstrap.php'));
        $tests->assertSame(false, is_file($root . '/src/Leftover.php'));
        $tests->assertSame('<?php // new', file_get_contents($root . '/server.php'));

        // The application is untouched.
        $tests->assertSame('<?php // mine', file_get_contents($root . '/app/controllers/MineController.php'));

        // A merged directory keeps what is only yours and takes what is theirs.
        $tests->assertSame('{"mine": true}', file_get_contents($root . '/lang/mine.json'));
        $tests->assertSame('{"new": true}', file_get_contents($root . '/lang/en.json'));

        // The front controller is yours; the new one arrives beside it.
        $tests->assertSame('<?php // my front controller', file_get_contents($root . '/public/index.php'));
        $tests->assertSame('<?php // the new front controller', file_get_contents($root . '/public/index.php.new'));

        /*
         * The SFCSS configuration is documented as yours to edit and sits
         * inside a merged directory, where it was being overwritten by the
         * release's copy: a project that had customised its palette lost it.
         */
        $tests->assertSame('{"mine": true}', file_get_contents($root . '/tools/css-builder/sfcss.config.json'));
        $tests->assertSame('{"theirs": true}', file_get_contents($root . '/tools/css-builder/sfcss.config.json.new'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($source) . ' 2>/dev/null');
    }
});

$tests->run('reset removes the example application and refuses to do it in silence', function () use ($tests): void {
    /*
     * This deletes a project's application, so it is tested against a project
     * of its own: a directory with an autoloader shim and a copy of the binary,
     * which is what the command reads the root from. Pointing it at anything
     * else would be a test that can destroy the thing it is testing.
     */
    $root = sys_get_temp_dir() . '/sfphp-reset-' . bin2hex(random_bytes(6));

    foreach ([
        'vendor', 'src', 'app/config', 'app/components/page', 'app/controllers',
        'app/models', 'app/Jobs', 'app/resources/views/partials', 'app/routes',
        'database/seeders', 'database/factories', 'database/migrations',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0755, true);
    }

    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
    );
    copy(dirname(__DIR__) . '/sfphp', $root . '/sfphp');
    chmod($root . '/sfphp', 0755);

    $removed = [
        'app/components/page/Card.phpx',
        'app/controllers/MainController.php',
        'app/models/User.php',
        'app/Jobs/SendEmailJob.php',
        'app/resources/views/home.sfht',
        'app/resources/views/partials/header.sfht',
        'database/seeders/DatabaseSeeder.php',
        'database/factories/UserFactory.php',
        // A migration the project wrote goes with the rest of what it wrote.
        'database/migrations/2026_10_01_000001_create_posts_table.php',
    ];

    $kept = [
        'app/config/config.php',
        'database/migrations/2026_01_01_000001_create_users_table.php',
        'database/migrations/2026_01_01_000002_create_sessions_table.php',
        // A .gitkeep exists to hold an empty directory, which is what is left.
        'database/migrations/.gitkeep',
    ];

    foreach ([...$removed, ...$kept] as $file) {
        file_put_contents($root . '/' . $file, '<?php // example');
    }

    file_put_contents($root . '/app/routes/web.php', "<?php\n\nRouter::get('/', 'MainController', 'index');\n");
    file_put_contents($root . '/app/routes/api.php', "<?php\n\n// API routes\n");

    $binary = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/sfphp');

    try {
        /*
         * With no terminal to answer at, it has to refuse. A command that
         * deletes an application must not proceed on silence, which is exactly
         * what a pipe or a CI job gives it.
         */
        $output = [];
        $status = 0;
        exec($binary . ' reset < /dev/null 2>&1', $output, $status);

        $tests->assertSame(1, $status);
        $tests->assertTrue(is_file($root . '/app/controllers/MainController.php'));

        // It still says what it would have done, so the warning is readable.
        $printed = implode("\n", $output);
        $tests->assertTrue(str_contains($printed, 'cannot be undone'));

        $output = [];
        $status = 0;
        exec($binary . ' reset --force 2>&1', $output, $status);

        /*
         * is_file() above filled the stat cache, so without this the file that
         * was checked before the deletion still reports as present — a test
         * that fails while the command is correct.
         */
        clearstatcache(true);

        $tests->assertSame(0, $status);

        foreach ($removed as $file) {
            $tests->assertSame(false, is_file($root . '/' . $file));
        }

        // The directories stay, because they are where the next thing goes.
        $tests->assertTrue(is_dir($root . '/app/controllers'));
        $tests->assertTrue(is_dir($root . '/app/resources/views'));

        /*
         * The framework's own migrations survive. The users and sessions
         * tables are what the authentication guard and the database session
         * driver are written against, and a project that lost them would find
         * out at a login. A migration the project wrote is the project's, and
         * goes with the rest of it.
         */
        foreach ($kept as $file) {
            $tests->assertTrue(is_file($root . '/' . $file));
        }

        // The routes file is rewritten, or the application boots into a
        // controller that is no longer there.
        $routes = (string) file_get_contents($root . '/src/routes.php');
        $tests->assertSame(false, str_contains($routes, 'MainController'));

        // Running it again has nothing left to do, and says so.
        $output = [];
        $status = 0;
        exec($binary . ' reset < /dev/null 2>&1', $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(str_contains(implode("\n", $output), 'already gone'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root) . ' 2>/dev/null');
    }
});

$tests->run('the dark theme changes nothing a page did not ask for', function () use ($tests): void {
    /*
     * This shipped applying prefers-color-scheme to :root directly, so a page
     * that had never asked for a dark theme got dark cards — while bg-blue-50
     * and text-slate-600, being fixed palette values, stayed as light as they
     * were. A light heading on a dark card is not a theme, it is a collision.
     */
    $css = Assets::css(false);

    // No bare prefers-color-scheme block: the media query only applies to a
    // root that opted in.
    $tests->assertSame(0, preg_match('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root\s*\{/', $css));

    $tests->assertSame(true, str_contains($css, ':root[data-theme="dark"]'));
    $tests->assertSame(true, str_contains($css, ':root[data-theme="auto"]'));

    /*
     * The light values sit on a bare :root, so a page that says nothing is
     * light — and the dark ones only ever appear under a [data-theme] selector.
     */
    $tests->assertSame(1, preg_match('/^:root \{[^}]*--surface: #ffffff/m', $css));

    // Every dark definition sits inside a data-theme block, never on its own.
    $parts = explode('--surface: #17181c', $css);

    for ($index = 1; $index < count($parts); $index++) {
        $tests->assertSame(true, str_contains(substr($parts[$index - 1], -400), 'data-theme'));
    }

    /*
     * The framework's own screens are the framework's pages, not somebody's, so
     * they do follow the reader's setting.
     */
    $tests->assertSame(true, str_contains(HtmlDump::render([1]), 'data-theme="auto"'));
});

$tests->run('generators write into the project that ran them', function () use ($tests): void {
    /*
     * The namespace was the literal string "SfphpProject\app", which is this
     * repository's example application. Every generator therefore wrote a class
     * into the framework's own namespace: unusable in any project that
     * installed the framework, because nothing there could autoload it — and
     * make:controller extended a base class the package does not even ship.
     */
    $project = sys_get_temp_dir() . '/sfphp-generators-' . bin2hex(random_bytes(4));
    mkdir($project, 0755, true);

    try {
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\Shop\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $generator = new SfphpProject\src\Console\Generators\ControllerGenerator($project);
        $path = $generator->generate('Post');

        // The project's prefix, and the directory PSR-4 expects for it.
        $tests->assertSame(true, str_ends_with($path, 'app/Controllers/PostController.php'));

        $source = (string) file_get_contents($path);
        $tests->assertSame(true, str_contains($source, 'namespace Acme\\Shop\\Controllers;'));

        /*
         * Nothing from the example application, which a consumer never
         * receives. Extending is fine now that the base class is in the
         * package, and that is the whole point of where it lives.
         */
        $tests->assertSame(false, str_contains($source, 'SfphpProject\\app'));
        $tests->assertSame(false, str_contains($source, 'extends'));
        $tests->assertSame(true, str_contains($source, 'Response::view('));

        // And it is a real action: a Response, not a string.
        $tests->assertSame(true, str_contains($source, 'public function index(Request $request): Response'));

        $status = 0;
        $output = [];
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        // A project that declares nothing gets App\, which is what init writes.
        file_put_contents($project . '/composer.json', '{}');
        $bare = new SfphpProject\src\Console\Generators\ControllerGenerator($project);
        $tests->assertSame(true, str_contains((string) file_get_contents($bare->generate('Bare')), 'namespace App\\Controllers;'));
    } finally {
        foreach (glob($project . '/app/Controllers/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($project . '/composer.json');
        @rmdir($project . '/app/Controllers');
        @rmdir($project . '/app');
        @rmdir($project);
    }
});

$tests->run('a response can be built from anywhere, including back to where you were', function () use ($tests): void {
    /*
     * Response is a factory, which is what makes a base class unnecessary: a
     * controller inherits nothing and still reaches every kind of response.
     * There was a Controller class here offering $this->view() and
     * $this->redirect(); it inherited a whole class to shorten two calls that
     * already existed, so route() and back() moved here and it went away.
     */
    $tests->assertSame(false, class_exists('SfphpProject\\src\\Http\\Controller'));

    // back() follows the referer when it is this site.
    $local = Response::back(Request::create('GET', '/x', [
        'headers' => ['Referer' => 'https://example.test/posts?page=2', 'Host' => 'example.test'],
    ]));
    $tests->assertSame('/posts?page=2', $local->header('Location'));

    // A relative referer has no host to disagree with.
    $relative = Response::back(Request::create('GET', '/x', [
        'headers' => ['Referer' => '/posts', 'Host' => 'example.test'],
    ]));
    $tests->assertSame('/posts', $relative->header('Location'));

    /*
     * The referer is a header, so the visitor chooses it. Following it to
     * another origin is an open redirect — the classic way a phishing link
     * borrows a domain's good name.
     */
    foreach (['https://evil.test/steal', '//evil.test/steal', 'javascript:alert(1)', ''] as $hostile) {
        $refused = Response::back(
            Request::create('GET', '/x', ['headers' => ['Referer' => $hostile, 'Host' => 'example.test']]),
            '/fallback'
        );
        $tests->assertSame('/fallback', $refused->header('Location'));
    }

    // And a named route, which is the other redirect an application writes by hand.
    Router::reset();
    Router::get('/posts/id:number', 'PostController', 'show')->name('posts.show');
    $tests->assertSame('/posts/7', Response::route('posts.show', ['id' => 7])->header('Location'));
    Router::reset();
});

$tests->run('a request whose body is not the json it claims is refused before the action', function () use ($tests): void {
    /*
     * This was two methods on a base class every API controller had to extend,
     * so the check ran only where somebody remembered to call it. Refusing a
     * request that cannot be handled is what middleware is for: it happens
     * once, before the action, for everything it is registered on.
     */
    $middleware = new SfphpProject\src\Http\Middleware\RequireJson();
    $reached = false;
    $next = function (Request $request) use (&$reached): Response {
        $reached = true;

        return Response::json(['seen' => $request->attribute('json')]);
    };

    // The good case: decoded, handed on, and readable by the action.
    $ok = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'rawBody' => '{"title":"Olá"}',
    ]), $next);

    $tests->assertSame(true, $reached);
    $tests->assertSame(['title' => 'Olá'], json_decode($ok->body(), true)['seen']);

    // 415 is "I do not speak that".
    $reached = false;
    $wrongType = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'text/xml'],
        'rawBody' => '<post/>',
    ]), $next);

    $tests->assertSame(HTTP_UNSUPPORTED_MEDIA_TYPE, $wrongType->status());
    $tests->assertSame(false, $reached);

    // 400 is "that was not valid JSON" — a different answer, because a client
    // debugging one is looking somewhere else entirely.
    $broken = $middleware->handle(Request::create('POST', '/api/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'rawBody' => '{"title":',
    ]), $next);

    $tests->assertSame(HTTP_BAD_REQUEST, $broken->status());
    $tests->assertSame(false, $reached);

    /*
     * A GET carries no body. Refusing it would make the middleware unusable on
     * a group that both reads and writes, which is most groups.
     */
    $read = $middleware->handle(Request::create('GET', '/api/posts'), $next);
    $tests->assertSame(true, $reached);
    $tests->assertSame(HTTP_OK, $read->status());

    // A write with no Content-Type passes unless the endpoint insists.
    $reached = false;
    $silent = $middleware->handle(Request::create('POST', '/api/posts'), $next);
    $tests->assertSame(true, $reached);
    $tests->assertSame(HTTP_OK, $silent->status());

    $strict = (new SfphpProject\src\Http\Middleware\RequireJson(required: true))
        ->handle(Request::create('POST', '/api/posts'), $next);
    $tests->assertSame(HTTP_UNSUPPORTED_MEDIA_TYPE, $strict->status());
});

$tests->run('the console finds a project laid out like a project, not like this repository', function () use ($tests): void {
    /*
     * Both of these were found only by running the commands from an installed
     * project. `routes` looked for src/routes.php, which is this repository's
     * layout and nobody else's, so it failed everywhere it was installed. And
     * the generators capitalised every directory segment for a foreign project,
     * which sent a seeder to database/Seeders while `db:seed` kept reading
     * database/seeders.
     */
    $source = (string) file_get_contents(__DIR__ . '/../src/Console/Application.php');

    $tests->assertSame(false, str_contains($source, "rootPath() . '/src/routes.php'"));
    $tests->assertSame(true, str_contains($source, "projectPath('app/routes/web.php')"));
    $tests->assertSame(true, str_contains($source, "projectPath('app/routes/api.php')"));

    $project = sys_get_temp_dir() . '/sfphp-paths-' . bin2hex(random_bytes(4));
    mkdir($project, 0755, true);

    try {
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\Shop\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        // app/ follows the project's PSR-4 prefix, so its spelling follows too.
        $controller = (new SfphpProject\src\Console\Generators\ControllerGenerator($project))->generate('Post');
        $tests->assertSame(true, str_ends_with($controller, 'app/Controllers/PostController.php'));

        /*
         * database/ does not: those paths are read back by db:seed and by the
         * factory loader, at names this framework decides.
         */
        $seeder = (new SfphpProject\src\Console\Generators\SeederGenerator($project))->generate('Post');
        $factory = (new SfphpProject\src\Console\Generators\FactoryGenerator($project))->generate('Post');

        $tests->assertSame(true, str_contains($seeder, '/database/seeders/'));
        $tests->assertSame(true, str_contains($factory, '/database/factories/'));

        // And they return a path, like every other generator, rather than a
        // sentence the command then prints inside its own sentence.
        $tests->assertSame(true, is_file($seeder));
        $tests->assertSame(true, is_file($factory));
    } finally {
        foreach (['app/Controllers', 'database/seeders', 'database/factories'] as $directory) {
            foreach (glob($project . '/' . $directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
        }

        @unlink($project . '/composer.json');

        foreach (['app/Controllers', 'app', 'database/seeders', 'database/factories', 'database'] as $directory) {
            @rmdir($project . '/' . $directory);
        }

        @rmdir($project);
    }
});

$tests->run('the development branch declares which release it is heading for', function () use ($tests): void {
    /*
     * Without a branch alias, Packagist offers the default branch only as
     * dev-master, so nobody can depend on the next minor before it is tagged
     * and a caret constraint matches nothing at all. It is also the one place
     * that says out loud which version this branch becomes.
     */
    $composer = json_decode(
        (string) file_get_contents(__DIR__ . '/../composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $alias = $composer['extra']['branch-alias']['dev-master'] ?? null;

    $tests->assertTrue(is_string($alias));
    $tests->assertSame(1, preg_match('/^\d+\.\d+\.x-dev$/', (string) $alias));
});

$tests->run('the console reports the version it actually is', function () use ($tests): void {
    /*
     * This was a literal in the line the command prints, so every release
     * depended on somebody remembering to edit a string — and the release where
     * they forget is the one whose console claims to be the previous version.
     * Composer's InstalledVersions is generated into the autoloader rather than
     * required as a package, so asking it costs no dependency.
     */
    $source = (string) file_get_contents(__DIR__ . '/../src/Console/Application.php');

    $tests->assertSame(false, str_contains($source, "'SFPHP v1.0.0'"));
    $tests->assertSame(true, str_contains($source, 'InstalledVersions'));

    $version = SfphpProject\src\Console\Application::version();

    // Always something, and never a number nobody set.
    $tests->assertSame(true, $version !== '');
    $tests->assertSame(false, str_contains($version, 'no-version-set'));
});

$tests->run('a response describes what came back without pretending it is an error', function () use ($tests): void {
    /*
     * A 404 and a 500 are answers: the server was reached, understood and said
     * no. They come back to be inspected rather than thrown, because a missing
     * record is routinely an expected result. throw() is how a caller opts in
     * where it is not.
     */
    $ok = new ClientResponse(200, '{"id":7}', ['Content-Type' => 'application/json'], 'https://x.test/a');

    $tests->assertSame(true, $ok->ok());
    $tests->assertSame(false, $ok->failed());
    $tests->assertSame(['id' => 7], $ok->json());
    $tests->assertSame($ok, $ok->throw());

    // Header lookup ignores case, because a server's capitalisation is its own.
    $tests->assertSame('application/json', $ok->header('CONTENT-TYPE'));
    $tests->assertSame(null, $ok->header('X-Absent'));

    $missing = new ClientResponse(404, '{"message":"no"}', [], 'https://x.test/b');
    $tests->assertSame(true, $missing->failed());
    $tests->assertSame(true, $missing->clientError());
    $tests->assertSame(false, $missing->serverError());

    $broken = new ClientResponse(503, 'unavailable', [], 'https://x.test/c');
    $tests->assertSame(true, $broken->serverError());

    // A body that is not JSON answers null rather than throwing, unless asked.
    $html = new ClientResponse(200, '<html></html>', [], 'https://x.test/d');
    $tests->assertSame(null, $html->json());
    $tests->assertThrows(fn () => $html->json(true), ClientException::class);

    // throw() carries what the service said, so nobody has to go read its logs.
    try {
        $missing->throw();
        $tests->assertSame('throw() did not throw', false);
    } catch (ClientException $exception) {
        $tests->assertSame(true, str_contains($exception->getMessage(), '404'));
        $tests->assertSame(true, str_contains($exception->getMessage(), 'no'));
    }
});

$tests->run('a client is a value, so configuring one cannot change another', function () use ($tests): void {
    /*
     * The verbs were static at first, so a configured client calling get()
     * built a fresh default and silently dropped everything — the token and the
     * base URL never left the building and the request looked perfectly fine.
     * That is what this shape is guarding.
     */
    $plain = Http::client();
    $authenticated = $plain->token('secret');
    $based = $authenticated->base('https://api.test/v1');

    $tests->assertSame(true, $plain !== $authenticated);
    $tests->assertSame(true, $authenticated !== $based);

    $read = new ReflectionProperty(SfphpProject\src\Http\Client::class, 'headers');
    $read->setAccessible(true);

    // Configuring the second did not reach back into the first.
    $tests->assertSame([], $read->getValue($plain));
    $tests->assertSame(['Authorization' => 'Bearer secret'], $read->getValue($authenticated));
    $tests->assertSame(['Authorization' => 'Bearer secret'], $read->getValue($based));

    $basic = new ReflectionProperty(SfphpProject\src\Http\Client::class, 'headers');
    $basic->setAccessible(true);
    $credentials = $basic->getValue(Http::withBasic('ana', 'senha'));
    $tests->assertSame('Basic ' . base64_encode('ana:senha'), $credentials['Authorization']);
});

$tests->run('the async runtime keeps several requests in flight at once', function () use ($tests): void {
    /*
     * The claim under test is the whole point of the runtime, and it is not
     * observable from unit assertions: three requests that each take 300 ms
     * either finish in about 300 ms or in about 900, and only a clock and a
     * real socket can say which.
     *
     * The origin is the non-blocking one in benchmarks/, on purpose. PHP's
     * built-in server answers from a pool of worker processes, so measuring
     * against it measures the pool — three 300 ms requests took 600 ms there
     * while the client was already perfectly concurrent.
     */
    if (!extension_loaded('curl')) {
        return;
    }

    $port = 8400 + (getmypid() % 500);
    $origin = dirname(__DIR__) . '/benchmarks/origin.php';
    $command = sprintf(
        '%s %s 127.0.0.1:%d > /dev/null 2>&1 & echo $!',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($origin),
        $port
    );

    $pid = (int) trim((string) shell_exec($command));
    $base = 'http://127.0.0.1:' . $port;

    try {
        // Wait for the socket, rather than guessing how long it takes to bind.
        $listening = false;

        for ($attempt = 0; $attempt < 150; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);

            if ($probe !== false) {
                fclose($probe);
                $listening = true;
                break;
            }

            usleep(20_000);
        }

        /*
         * No origin, nothing to measure. A port that was taken or a process
         * that could not start says nothing about whether transfers overlap,
         * and a test that reports the machine as a defect in the framework is
         * one people learn to ignore.
         */
        if (!$listening) {
            return;
        }

        $url = $base . '/delay?ms=300';

        $startedAt = microtime(true);
        $first = Http::getAsync($url);
        $second = Http::getAsync($url);
        $third = Http::getAsync($url);

        $statuses = [
            SfphpProject\src\Async\await($first)->status(),
            SfphpProject\src\Async\await($second)->status(),
            SfphpProject\src\Async\await($third)->status(),
        ];
        $elapsed = microtime(true) - $startedAt;

        $tests->assertSame([200, 200, 200], $statuses);

        /*
         * Sequential would be 900 ms. The bound is generous because a loaded
         * machine is still a machine, but it is nowhere near 900: this fails
         * the moment the transfers stop overlapping.
         */
        $tests->assertTrue($elapsed < 0.6);

        // A response is the framework's own, so sync and async agree on shape.
        $tests->assertSame(300, SfphpProject\src\Async\await(Http::getAsync($url))->json()['slept_ms']);

        // A deadline is honoured, and arrives as the exception it promises.
        $startedAt = microtime(true);
        $tests->assertThrows(
            static fn () => SfphpProject\src\Async\await(Http::getAsync($base . '/delay?ms=3000'), 300),
            SfphpProject\src\Async\TimeoutException::class
        );
        $tests->assertTrue(microtime(true) - $startedAt < 1.0);

        // A refused connection is a failure, not an empty response.
        $tests->assertThrows(
            static fn () => SfphpProject\src\Async\await(Http::getAsync('http://127.0.0.1:9/nothing')),
            ClientException::class
        );
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});

$tests->run('a task that awaits gets its value, and the scheduler waits for nobody', function () use ($tests): void {
    /*
     * Both halves used to be broken. await() inside a Task registered a
     * callback that resumed the Fiber from whatever stack settled the Future,
     * and the scheduler resumed every Task on every pass whether or not it was
     * waiting for anything — so this returned null, silently, and the work was
     * never done.
     */
    $task = SfphpProject\src\Async\async(static function (): int {
        $inner = SfphpProject\src\Async\async(static fn (): int => 20);

        return SfphpProject\src\Async\await($inner) + 1;
    });

    $tests->assertSame(21, SfphpProject\src\Async\await($task));

    // Timers are the loop's, not usleep()'s: three of them overlap.
    $startedAt = microtime(true);
    SfphpProject\src\Async\await(SfphpProject\src\Async\CompositeFuture::all(
        SfphpProject\src\Async\delay(150),
        SfphpProject\src\Async\delay(150),
        SfphpProject\src\Async\delay(150)
    ));
    $elapsed = microtime(true) - $startedAt;

    $tests->assertTrue($elapsed >= 0.14 && $elapsed < 0.4);

    // A value read before it exists is an error, not null.
    $pending = new SfphpProject\src\Async\Task(static fn (): int => 1);
    $tests->assertThrows(
        static fn () => $pending->getValue(),
        SfphpProject\src\Async\AsyncException::class
    );

    // A rejected task carries its exception to whoever awaits it.
    $failing = SfphpProject\src\Async\async(static function (): void {
        throw new RuntimeException('from inside the fiber');
    });
    $tests->assertThrows(
        static fn () => SfphpProject\src\Async\await($failing),
        RuntimeException::class
    );
    $tests->assertSame(true, $failing->isRejected());

    // A bare suspend is cooperative yielding: the task gets its turn back.
    $yielding = SfphpProject\src\Async\async(static function (): string {
        Fiber::suspend();

        return 'resumed';
    });
    $tests->assertSame('resumed', SfphpProject\src\Async\await($yielding));

    /*
     * A task parked on something nobody will ever settle is reported rather
     * than hung on. A program that stops with a message can be fixed; one that
     * stops silently gets reported as "the server is slow".
     */
    $never = new class extends SfphpProject\src\Async\Pending {};
    $waiting = SfphpProject\src\Async\async(static fn () => SfphpProject\src\Async\await($never));
    $tests->assertThrows(
        static fn () => SfphpProject\src\Async\await($waiting),
        SfphpProject\src\Async\AsyncException::class
    );
});

$tests->run('the client talks to a real server', function () use ($tests): void {
    /*
     * A real socket, because what a client does happens on the wire: a mock
     * would confirm that the code calls the functions it calls, which was never
     * the thing in doubt.
     */
    if (!extension_loaded('curl')) {
        return;
    }

    $port = 8000 + (getmypid() % 900);
    $root = __DIR__ . '/fixtures';
    $command = sprintf(
        'php -S 127.0.0.1:%d -t %s %s/http-server.php > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg($root),
        escapeshellarg($root)
    );

    $pid = (int) trim((string) shell_exec($command));
    $base = 'http://127.0.0.1:' . $port;

    try {
        // Wait for it to accept, rather than sleeping a guessed amount.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if ($probe !== false) {
                fclose($probe);
                break;
            }

            usleep(100_000);
        }

        $response = Http::get($base . '/users', ['page' => 2, 'q' => 'ação']);

        $tests->assertSame(200, $response->status());
        $tests->assertSame(['page' => '2', 'q' => 'ação'], $response->json()['query']);
        $tests->assertSame('sfphp-test', $response->header('X-Served-By'));

        // A body is JSON unless told otherwise, and says so in its type.
        $posted = Http::post($base . '/users', ['name' => 'Ana', 'cidade' => 'São Paulo']);
        $tests->assertSame('{"name":"Ana","cidade":"São Paulo"}', $posted->json()['body']);
        $tests->assertSame('application/json', $posted->json()['headers']['CONTENT_TYPE'] ?? null);

        $form = Http::client()->asForm()->post($base . '/users', ['a' => 1, 'b' => 2]);
        $tests->assertSame('a=1&b=2', $form->json()['body']);

        // Configuration survives the call, which is the bug this guards.
        $api = Http::base($base)->token('t0k');
        $tests->assertSame('/invoices/7', $api->get('/invoices/7')->json()['path']);
        $tests->assertSame('Bearer t0k', $api->get('/me')->json()['headers']['HTTP_AUTHORIZATION'] ?? null);

        $tests->assertSame('PATCH', Http::patch($base . '/x', ['a' => 1])->json()['method']);
        $tests->assertSame('DELETE', Http::delete($base . '/x')->json()['method']);

        // An error is an answer, not an exception.
        $tests->assertSame(404, Http::get($base . '/status/404')->status());
        $tests->assertSame(true, Http::get($base . '/status/500')->serverError());

        /*
         * A timeout is not an answer, so it throws — and it has to actually
         * stop, or one slow service takes the whole application down with it.
         */
        $startedAt = microtime(true);
        $tests->assertThrows(fn () => Http::timeout(1)->get($base . '/slow'), ClientException::class);
        $tests->assertSame(true, microtime(true) - $startedAt < 4.0);

        // So is a connection nobody answered.
        $tests->assertThrows(
            fn () => Http::timeout(2)->get('http://127.0.0.1:' . ($port + 1) . '/'),
            ClientException::class
        );
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});


/*
 * The async helpers beyond the scheduler — events, streams, cache adapters,
 * reactive state. Each test names the defect it guards: these classes shipped
 * with none, and every one of the bugs below was reachable from its first call.
 */
$tests->run('a synchronous broadcast waits for its listeners instead of throwing', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $events->subscribe('user.created', static fn (string $event, mixed $payload): string => 'hello ' . $payload['name']);

    $result = $events->broadcastSync('user.created', ['name' => 'Ana']);

    $tests->assertSame(1, $result['listeners_count']);
    $tests->assertSame(['hello Ana'], array_values($result['results']));
    $tests->assertSame([], $result['errors']);
});

$tests->run('event wildcards match exact, leading, middle and trailing patterns', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $heard = [];

    foreach (['user.created', 'user.*', '*.created', 'order.*.shipped'] as $pattern) {
        $events->subscribe($pattern, static function (string $event) use (&$heard, $pattern): void {
            $heard[$pattern][] = $event;
        });
    }

    foreach (['user.created', 'user.profile.updated', 'order.created', 'order.42.shipped', 'users.created', 'user'] as $event) {
        $events->broadcastSync($event);
    }

    $tests->assertSame(['user.created'], $heard['user.created']);
    $tests->assertSame(['user.created', 'user.profile.updated'], $heard['user.*']);
    $tests->assertSame(['user.created', 'order.created', 'users.created'], $heard['*.created']);
    $tests->assertSame(['order.42.shipped'], $heard['order.*.shipped']);
});

$tests->run('a listener can be unsubscribed with the id subscribe returned', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $calls = [];

    $low = $events->subscribe('ping', static function () use (&$calls): void { $calls[] = 'low'; }, 1);
    $events->subscribe('ping', static function () use (&$calls): void { $calls[] = 'high'; }, 10);

    $events->broadcastSync('ping');
    $tests->assertSame(['high', 'low'], $calls);

    $tests->assertSame(true, $events->unsubscribe('ping', $low));
    $tests->assertSame(1, $events->getListenerCount('ping'));

    // A scope shares the listeners and prefixes the names.
    $billing = $events->scope('billing');
    $billing->subscribe('paid', static function (string $event) use (&$calls): void { $calls[] = $event; });
    $events->broadcastSync('billing.paid');
    $tests->assertSame('billing.paid', end($calls));
});

$tests->run('a stream reduces across every chunk, not only the first', function () use ($tests): void {
    $sum = static fn (?int $carry, int $item): int => ($carry ?? 0) + $item;

    $tests->assertSame(55, (new SfphpProject\src\Async\StreamFuture(range(1, 10), 3))->reduce($sum, 0));

    $stream = (new SfphpProject\src\Async\StreamFuture(range(1, 10), 3))
        ->filter(static fn (int $n): bool => $n % 2 === 0)
        ->map(static fn (int $n): int => $n * 10);

    $tests->assertSame(300, $stream->reduce($sum, 0));
    $tests->assertSame([20, 40, 60, 80, 100], $stream->getValue());
});

$tests->run('the cache adapters work with the framework cache', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());

    SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::set('greeting', 'olá', 60, $cache));
    $tests->assertSame('olá', SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::get('greeting', $cache)));
    $tests->assertSame(true, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::has('greeting', $cache)));
    $tests->assertSame(3, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::increment('hits', 3, $cache)));
    // A bare driver has no decrement(), so the adapter increments by the negative.
    $tests->assertSame(-1, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::decrement('left', 1, new MemoryDriver())));

    SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::delete('greeting', $cache));
    $tests->assertSame(false, $cache->has('greeting'));

    // Awaiting an adapter that has already run returns its value again.
    $read = SfphpProject\src\Async\Adapters\CacheFuture::get('hits', $cache);
    $read->getValue();
    $tests->assertSame(3, SfphpProject\src\Async\await($read));

    $cache->put('user:1', 'a');
    $cache->put('user:1:posts', 'b');
    $cache->put('user:1:followers', 'c');

    $invalidator = (new SfphpProject\src\Async\CacheInvalidator($cache))
        ->registerDependency('user:1', ['user:1:posts'])
        ->registerDependency('user:1:posts', ['user:1']);

    // The cycle is deliberate: it used to recurse until the stack ran out.
    $invalidator->invalidate('user:1');
    $tests->assertSame([false, false, true], [$cache->has('user:1'), $cache->has('user:1:posts'), $cache->has('user:1:followers')]);
});

$tests->run('reactive state takes its value from a Future that is still pending', function () use ($tests): void {
    $state = new SfphpProject\src\Async\ReactiveState();
    $seen = [];
    $state->onChange(static function (SfphpProject\src\Async\ReactiveState $s) use (&$seen): void {
        $seen[] = [$s->getValue(), $s->isLoading()];
    });

    $state->updateFromFuture(SfphpProject\src\Async\delay(10, 'ready'));

    $tests->assertSame('ready', $state->getValue());
    $tests->assertSame(false, $state->hasError());
    $tests->assertSame([['ready', false]], $seen);
});

$tests->run('a component with fallbacks renders, retries and falls back', function () use ($tests): void {
    $tests->assertSame('<p>ok</p>', SfphpProject\src\Async\ComponentFuture::withFallbacks(static fn (): string => '<p>ok</p>')->getValue());

    $attempts = 0;
    $flaky = SfphpProject\src\Async\ComponentFuture::withFallbacks(
        static function () use (&$attempts): string {
            if (++$attempts < 3) {
                throw new RuntimeException('not yet');
            }

            return 'third time';
        },
        null,
        null,
        2
    );
    $tests->assertSame('third time', $flaky->getValue());

    $broken = SfphpProject\src\Async\ComponentFuture::withFallbacks(
        static fn () => throw new RuntimeException('down'),
        null,
        static fn (Throwable $e): string => 'fallback: ' . $e->getMessage()
    );
    $tests->assertSame('fallback: down', $broken->getValue());
});

$tests->run('a WebSocketFuture refuses wss:// instead of connecting in the clear', function () use ($tests): void {
    $socket = new SfphpProject\src\Async\WebSocketFuture('wss://example.invalid/socket');

    $tests->assertSame(false, $socket->connect());
    $tests->assertSame(true, $socket->isRejected());
    $tests->assertSame(false, $socket->isConnected());
});

$tests->run('a deadlock is reported once, and the next await is not blamed for it', function () use ($tests): void {
    $never = new class extends SfphpProject\src\Async\Pending {};
    $stuck = SfphpProject\src\Async\async(static fn () => SfphpProject\src\Async\await($never));

    $tests->assertThrows(static fn () => SfphpProject\src\Async\await($stuck), SfphpProject\src\Async\AsyncException::class);
    $tests->assertSame(true, $stuck->isCancelled());

    // The stuck task used to stay parked and fail this unrelated await too.
    $tests->assertSame('fine', SfphpProject\src\Async\await(SfphpProject\src\Async\delay(1, 'fine')));
});

/*
 * Jobs for the queue tests below. Declared at the top level, not as anonymous
 * classes, because a queued payload names its class and the worker rebuilds
 * the job from that name.
 */
final class QueueProbeInvoiceJob extends \SfphpProject\src\Queue\Job
{
    public static array $sent = [];

    public function __construct(private readonly int $invoiceId, private string $to)
    {
    }

    public function handle(): void
    {
        self::$sent[] = $this->invoiceId . ':' . $this->to;
    }
}

final class QueueProbeFailingJob extends \SfphpProject\src\Queue\Job
{
    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
        throw new RuntimeException('always fails');
    }
}

final class QueueProbeSlowJob extends \SfphpProject\src\Queue\Job
{
    public function handle(): void
    {
        sleep(5);
    }
}

/**
 * A queue kept in memory that stores what the real drivers store: the JSON
 * payload, not the object, so every pop() goes through Job::fromPayload().
 * When it runs dry it sends the process SIGTERM, which is how a test finds
 * out whether the worker listens.
 */
final class QueueProbeDriver implements \SfphpProject\src\Queue\Queue
{
    /** @var list<array{id: string, attempts: int, payload: string}> */
    public array $jobs = [];

    /** @var list<array{id: string, exception: string}> */
    public array $failures = [];

    public bool $terminateWhenEmpty = true;

    public function push(\SfphpProject\src\Queue\Job $job, ?int $delay = null): string
    {
        $id = 'probe_' . count($this->jobs) . '_' . bin2hex(random_bytes(3));
        $this->jobs[] = ['id' => $id, 'attempts' => 0, 'payload' => $this->encode($job)];

        return $id;
    }

    public function pop(): ?\SfphpProject\src\Queue\Job
    {
        $row = array_shift($this->jobs);

        if ($row === null) {
            if ($this->terminateWhenEmpty) {
                posix_kill(getmypid(), SIGTERM);
            }

            return null;
        }

        return \SfphpProject\src\Queue\Job::fromPayload(json_decode($row['payload'], true), $row['id'], $row['attempts']);
    }

    public function failed(\SfphpProject\src\Queue\Job $job, \Throwable $exception): void
    {
        $this->failures[] = ['id' => (string) $job->getId(), 'exception' => get_class($exception)];
    }

    public function failedJobs(): array
    {
        return [];
    }

    public function retry(\SfphpProject\src\Queue\Job $job): void
    {
        // What both real drivers do: count the attempt, then put it back.
        $job->setAttempts($job->getAttempts() + 1);
        $this->jobs[] = ['id' => (string) $job->getId(), 'attempts' => $job->getAttempts(), 'payload' => $this->encode($job)];
    }

    public function release(\SfphpProject\src\Queue\Job $job, ?int $delay = null): void
    {
    }

    public function delete(\SfphpProject\src\Queue\Job $job): void
    {
    }

    public function flush(): void
    {
        $this->jobs = [];
    }

    public function size(): int
    {
        return count($this->jobs);
    }

    private function encode(\SfphpProject\src\Queue\Job $job): string
    {
        return json_encode(['class' => get_class($job), 'data' => $job->payload(), 'options' => $job->options()]);
    }
}

$tests->run('validated() hands back only the fields that had rules', function () use ($tests): void {
    /*
     * The whole input used to come back, so a smuggled is_admin rode along
     * into whatever validated() was handed to.
     */
    $result = Validator::validate(
        ['name' => 'Joana', 'email' => 'joana@example.com', 'is_admin' => '1', 'tags' => ['a', 'b']],
        ['name' => 'required|min:3', 'email' => 'required|email', 'tags' => 'required']
    );

    $tests->assertSame(true, $result->passes());
    $tests->assertSame(
        ['name' => 'Joana', 'email' => 'joana@example.com', 'tags' => ['a', 'b']],
        $result->validated()
    );

    // Through the request, which is how a controller gets there.
    $request = Request::create('POST', '/users', [
        'body' => ['name' => 'Joana', 'role' => 'admin'],
    ]);
    $tests->assertSame(['name' => 'Joana'], $request->validate(['name' => 'required'])->validated());
});

$tests->run('a queued job with constructor arguments comes back whole, with its dispatch options', function () use ($tests): void {
    $job = (new QueueProbeInvoiceJob(42, 'ana@example.com'))->tries(5)->timeout(120);
    $payload = json_decode(json_encode([
        'class' => get_class($job),
        'data' => $job->payload(),
        'options' => $job->options(),
    ]), true);

    // new $class() used to throw ArgumentCountError here and kill the worker.
    $restored = \SfphpProject\src\Queue\Job::fromPayload($payload, 'job_1', 2);

    $tests->assertSame('job_1', $restored->getId());
    $tests->assertSame(2, $restored->getAttempts());
    $tests->assertSame(5, $restored->getTries());
    $tests->assertSame(120, $restored->getTimeout());

    QueueProbeInvoiceJob::$sent = [];
    $restored->handle();
    $tests->assertSame(['42:ana@example.com'], QueueProbeInvoiceJob::$sent);

    // A payload stored before options were recorded keeps the class defaults.
    unset($payload['options']);
    $old = \SfphpProject\src\Queue\Job::fromPayload($payload, 'job_2', 0);
    $tests->assertSame(3, $old->getTries());
    $tests->assertSame(60, $old->getTimeout());

    // A payload that names something other than a job is never instantiated.
    $tests->assertThrows(
        fn () => \SfphpProject\src\Queue\Job::fromPayload(['class' => ArrayObject::class, 'data' => []], 'job_3', 0),
        UnexpectedValueException::class
    );
});

/**
 * An in-memory stand-in for the phpredis methods the queue driver uses, so the
 * driver is tested here without a Redis server or ext-redis.
 */
$fakeRedis = static fn (): object => new class () {
    /** @var array<string, array<string, float>> */
    public array $sorted = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    public function zAdd(string $key, float $score, string $member): int
    {
        $new = !isset($this->sorted[$key][$member]);
        $this->sorted[$key][$member] = $score;

        return $new ? 1 : 0;
    }

    public function zRangeByScore(string $key, $min, $max, array $options = []): array
    {
        $members = array_filter($this->sorted[$key] ?? [], fn (float $score): bool => $score >= $min && $score <= $max);
        asort($members);
        [$offset, $count] = $options['limit'] ?? [0, null];

        return array_slice(array_keys($members), $offset, $count);
    }

    public function zRem(string $key, string $member): int
    {
        if (!isset($this->sorted[$key][$member])) {
            return 0;
        }

        unset($this->sorted[$key][$member]);

        return 1;
    }

    public function zCard(string $key): int
    {
        return count($this->sorted[$key] ?? []);
    }

    public function hSet(string $key, string $field, string $value): int
    {
        $this->hashes[$key][$field] = $value;

        return 1;
    }

    public function hGet(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hDel(string $key, string $field): int
    {
        $had = isset($this->hashes[$key][$field]);
        unset($this->hashes[$key][$field]);

        return $had ? 1 : 0;
    }

    public function hGetAll(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    public function del(string ...$keys): int
    {
        foreach ($keys as $key) {
            unset($this->sorted[$key], $this->hashes[$key]);
        }

        return count($keys);
    }

    public function keys(string $pattern): array
    {
        $all = array_unique(array_merge(array_keys($this->sorted), array_keys($this->hashes)));

        return array_values(array_filter($all, fn (string $key): bool => fnmatch($pattern, $key)));
    }
};

$tests->run('the redis queue deletes a job, and flushing it touches nothing else', function () use ($tests, $fakeRedis): void {
    $redis = $fakeRedis();
    $redis->hSet('cache:user:1', 'name', 'Ana');                      // someone else's data
    $redis->hSet('failed:login-attempts', 'ip', '10.0.0.1');          // a failed:* key that is not ours

    $queue = new \SfphpProject\src\Queue\RedisDriver($redis);

    $first = $queue->push(new QueueProbeInvoiceJob(1, 'a@example.com'));
    $queue->push(new QueueProbeInvoiceJob(2, 'b@example.com'));
    $tests->assertSame(2, $queue->size());

    // delete() removed a key that never existed, so the job stayed queued.
    $job = \SfphpProject\src\Queue\Job::fromPayload(
        json_decode((string) $redis->hGet('queue:jobs', $first), true),
        $first,
        0
    );
    $queue->delete($job);
    $tests->assertSame(1, $queue->size());
    $tests->assertSame(false, $redis->hGet('queue:jobs', $first));

    // A popped job comes back whole, and releasing it reschedules the same id.
    $popped = $queue->pop();
    $tests->assertTrue($popped instanceof QueueProbeInvoiceJob);
    $tests->assertSame(0, $queue->size());
    $queue->release($popped, 0);
    $tests->assertSame(1, $queue->size());

    // A job queued by the previous layout — payload as the member — still runs.
    $redis->zAdd('queue:default', time(), json_encode([
        'id' => 'job_legacy', 'class' => QueueProbeInvoiceJob::class,
        'data' => (new QueueProbeInvoiceJob(3, 'c@example.com'))->payload(), 'attempts' => 0,
    ]));
    $tests->assertSame(2, $queue->size());

    $queue->failed($popped, new RuntimeException('gateway down'));
    $tests->assertSame('gateway down', $queue->failedJobs()[0]['exception']);

    // flushDb() used to erase the whole database, cache and sessions included.
    $queue->flush();
    $tests->assertSame(0, $queue->size());
    $tests->assertSame([], $queue->failedJobs());
    $tests->assertSame('Ana', $redis->hGet('cache:user:1', 'name'));
    $tests->assertSame('10.0.0.1', $redis->hGet('failed:login-attempts', 'ip'));
});

$tests->run('the worker honours tries, enforces timeouts and stops on SIGTERM', function () use ($tests): void {
    if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill')) {
        return;
    }

    $driver = new QueueProbeDriver();
    $queue = new QueueManager($driver);

    // tries(4) set at dispatch: four runs, one failure — not the class's 3, and not one run short.
    QueueProbeFailingJob::$runs = 0;
    $queue->push((new QueueProbeFailingJob())->tries(4));

    // A job that overruns its timeout fails that attempt instead of holding the worker.
    $queue->push((new QueueProbeSlowJob())->tries(1)->timeout(1));

    /*
     * When the queue runs dry the driver sends SIGTERM. The handlers used to
     * be installed but never dispatched, so the worker ignored it and ran
     * until its own 30 s timeout.
     */
    ob_start();
    $startedAt = microtime(true);

    try {
        $queue->work(30);
    } finally {
        ob_end_clean();
    }

    $elapsed = microtime(true) - $startedAt;

    $failures = array_column($driver->failures, 'exception');
    sort($failures);

    $tests->assertSame(4, QueueProbeFailingJob::$runs);
    $tests->assertSame([RuntimeException::class, \SfphpProject\src\Queue\JobTimedOutException::class], $failures);
    $tests->assertTrue($elapsed < 4.0);
});

$tests->run('rate limiting uses the application cache unless given one', function () use ($tests): void {
    /*
     * It used to build its own file cache, so CACHE_DRIVER=redis still left
     * one counter per instance. The store is whatever cache() is.
     */
    $limit = new RateLimit(maxAttempts: 100, decaySeconds: 60, name: 'shared-' . bin2hex(random_bytes(4)));
    $request = Request::create('GET', '/', ['server' => ['REMOTE_ADDR' => '203.0.113.77']]);
    $limit->handle($request, static fn (Request $passed): Response => Response::text('ok'));

    $property = new ReflectionProperty(RateLimit::class, 'cache');
    $property->setAccessible(true);
    $tests->assertTrue($property->getValue($limit) === cache());
});

$tests->run('the client refuses https-to-http redirects and follows http-to-http ones', function () use ($tests): void {
    $protocols = new ReflectionMethod(\SfphpProject\src\Http\Client::class, 'redirectProtocols');
    $protocols->setAccessible(true);

    if (!extension_loaded('curl')) {
        return;
    }

    // A request that starts on https:// may only be redirected to https://.
    $tests->assertSame(CURLPROTO_HTTPS, $protocols->invoke(null, 'https://api.example.com/x'));
    $tests->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $protocols->invoke(null, 'http://127.0.0.1/x'));

    // And on the wire: a plain-http service that redirects is followed.
    $port = 9000 + (getmypid() % 900);
    $root = __DIR__ . '/fixtures';
    $pid = (int) trim((string) shell_exec(sprintf(
        'php -S 127.0.0.1:%d -t %s %s/http-server.php > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg($root),
        escapeshellarg($root)
    )));

    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if ($probe !== false) {
                fclose($probe);
                break;
            }

            usleep(100_000);
        }

        $response = Http::get('http://127.0.0.1:' . $port . '/redirect');
        $tests->assertSame(200, $response->status());
        $tests->assertSame('Redirected successfully', $response->body());
    } finally {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }
});

$tests->run('make:pwa builds from app/pwa/config.php, and flags override it', function () use ($tests): void {
    $base = sys_get_temp_dir() . '/sfphp-pwa-' . bin2hex(random_bytes(6));
    mkdir($base . '/app/pwa', 0777, true);
    mkdir($base . '/public', 0777, true);

    file_put_contents($base . '/app/pwa/config.php', '<?php return ' . var_export([
        'name' => "Joe's Café",
        'short_name' => 'Joe',
        'theme_color' => '#112233',
        'background_color' => '#445566',
        'service_worker' => [
            'version' => 'v7',
            'static_assets' => ['/offline.html'],
            'api_routes' => ['/api/*'],
            'enable_background_sync' => true,
        ],
    ], true) . ';');

    Bootstrap::load($base, ['env' => null]);

    try {
        ob_start();
        $status = (new Application(['sfphp', 'make:pwa']))->run();
        ob_end_clean();

        $tests->assertSame(0, $status);

        $manifest = json_decode((string) file_get_contents($base . '/public/manifest.json'), true);
        $tests->assertSame("Joe's Café", $manifest['name']);
        $tests->assertSame('Joe', $manifest['short_name']);
        $tests->assertSame('#112233', $manifest['theme_color']);
        $tests->assertSame('#445566', $manifest['background_color']);
        $tests->assertSame('/assets/icons/icon-192x192.png', $manifest['icons'][0]['src']);

        // The version names the cache, and the name is encoded, not pasted between quotes.
        $worker = (string) file_get_contents($base . '/public/service-worker.js');
        $tests->assertTrue(str_contains($worker, 'const CACHE_NAME = "joe\'s-café-v7";'));
        $tests->assertTrue(str_contains($worker, 'const STATIC_ASSETS = ["\/offline.html"];'));
        $tests->assertTrue(str_contains($worker, "addEventListener('sync'"));
        $tests->assertTrue(!str_contains($worker, "addEventListener('push'"));

        // A flag wins over the file, for that run.
        ob_start();
        (new Application(['sfphp', 'make:pwa', '--name=Other App', '--color=#000000', '--enable-push']))->run();
        ob_end_clean();

        $manifest = json_decode((string) file_get_contents($base . '/public/manifest.json'), true);
        $tests->assertSame('Other App', $manifest['name']);
        $tests->assertSame('Other App', $manifest['short_name']);
        $tests->assertSame('#000000', $manifest['theme_color']);
        $tests->assertTrue(str_contains((string) file_get_contents($base . '/public/service-worker.js'), "addEventListener('push'"));

        // install-sw.js points at the icons where they are generated.
        $installer = (string) file_get_contents($base . '/public/install-sw.js');
        $tests->assertTrue(!str_contains($installer, "'/icon-192x192.png'"));
        $tests->assertTrue(str_contains($installer, '/assets/icons/icon-192x192.png'));

        // A logo that cannot become icons is a warning, never a fatal error.
        file_put_contents($base . '/not-an-image.png', 'plain text');
        $status = (new Application(['sfphp', 'make:pwa', '--logo=' . $base . '/not-an-image.png']))->run();
        $tests->assertSame(0, $status);
        $tests->assertTrue(!is_file($base . '/public/assets/icons/icon-192x192.png'));
    } finally {
        Bootstrap::load(dirname(__DIR__), ['env' => null]);
        exec('rm -rf ' . escapeshellarg($base));
    }

    // Without a config file, --name is required.
    $empty = sys_get_temp_dir() . '/sfphp-pwa-' . bin2hex(random_bytes(6));
    mkdir($empty . '/public', 0777, true);
    Bootstrap::load($empty, ['env' => null]);

    try {
        ob_start();
        $status = (new Application(['sfphp', 'make:pwa']))->run();
        ob_end_clean();
        $tests->assertSame(1, $status);
    } finally {
        Bootstrap::load(dirname(__DIR__), ['env' => null]);
        exec('rm -rf ' . escapeshellarg($empty));
    }
});

$tests->run('a PWA config list replaces the default list instead of merging into it', function () use ($tests): void {
    $config = new \SfphpProject\src\Pwa\PwaConfig([
        'service_worker' => ['static_assets' => ['/a.css']],
        'icons' => [['src' => '/one.png', 'sizes' => '64x64']],
    ]);

    $tests->assertSame(['/a.css'], $config->staticAssets());
    $tests->assertSame(1, count($config->icons()));
    // A keyed section still merges: the version default survives.
    $tests->assertSame('v1', $config->version());
});

$tests->run('phpx ends a region by its markup, whatever the text inside holds', function () use ($tests): void {
    /*
     * The end of a region used to be found by counting parentheses and
     * stepping over quotes, as if the markup were PHP. The apostrophe in
     * "Don't" then opened a quote that never closed, and the ")" in "Step 1)"
     * closed the region in the middle of a paragraph. Text is text: only the
     * markup's own structure may end it.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\PhpxText;

    function Notes(string $step): \SfphpProject\src\View\Sfht
    {
        return sfht(
            <section title="a)b" data-x='it"s'>
                <p>Don't panic</p>
                <p>Step 1) open the {{ $step === 'x)' ? "lid" : 'box' }}</p>
                <ul><li>one (1<li>two</ul>
                <br>
                <!-- a comment with ) and ' in it -->
                <script>if (a < b) { c('it\'s'); }</script>
                @if ($step !== ')')<i>{{ $step }}</i>@endif
            </section>
        );
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        require $file;

        $html = (string) SfphpTest\PhpxText\Notes('lid');
        $tests->assertTrue(str_contains($html, "<p>Don't panic</p>"));
        $tests->assertTrue(str_contains($html, '<p>Step 1) open the box</p>'));
        $tests->assertTrue(str_contains($html, "c('it\\'s');"));
        $tests->assertTrue(str_contains($html, '<i>lid</i>'));
        $tests->assertTrue(str_ends_with($html, '</section>'));
    } finally {
        @unlink($file);
    }

    // An element left open is named, because it is why the ) was never seen.
    try {
        (new Phpx())->compile("<?php\nfunction U() {\n    return sfht(\n        <div><p>x</p>\n    );\n}\n");
        $tests->assertTrue(false);
    } catch (RuntimeException $exception) {
        $tests->assertTrue(str_contains($exception->getMessage(), '<div> on line 4 is still open'));
    }
});

$tests->run('phpx runs filters and refuses @include with the line of the .phpx', function () use ($tests): void {
    /*
     * A component is a function call with no template engine around it, so a
     * filter compiled to $__engine->filter() failed on null the first time it
     * ran, and @include did the same. Filters now apply directly; what cannot
     * work in a function is refused at build time, on the author's line.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\PhpxFilters;

    function Shout(string $name, array $items): \SfphpProject\src\View\Sfht
    {
        return sfht(
            <b>{{ $name | upper }}</b><i>{{ $name | truncate(3, '…') }}</i><s>{{ $items | length }}</s>
        );
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        require $file;

        $tests->assertSame(
            '<b>ÁGUA &amp; SAL</b><i>ág…</i><s>2</s>',
            (string) SfphpTest\PhpxFilters\Shout('água & sal', ['a', 'b'])
        );
    } finally {
        @unlink($file);
    }

    $messageOf = static function (string $markup): string {
        try {
            (new Phpx())->compile("<?php\nfunction I() {\n    return sfht(\n        <div>\n            {$markup}\n        </div>\n    );\n}\n");
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    };

    // Line 5 of the .phpx, not line 2 of the markup.
    $tests->assertTrue(str_contains($messageOf("@include('card')"), '@include on line 5 cannot be used in a .phpx component'));
    $tests->assertTrue(str_contains($messageOf('@extends(\'layout\')'), 'on line 5'));
    $tests->assertTrue(str_contains($messageOf('{{ $a | shout }}'), 'Unknown filter "shout" on line 5'));
    $tests->assertTrue(str_contains($messageOf('@if (true)'), 'Unclosed @if opened on line 5'));
});

$tests->run('phpx keeps the author\'s lines inside a region and after it', function () use ($tests): void {
    /*
     * Every {{ }} and directive used to compile to a statement of its own
     * line, so a region grew as it compiled and every line after it shifted
     * down: php -l named a line of the compiled file, not of the .phpx.
     */
    $source = implode("\n", [
        '<?php',                                                  // 1
        'function L(array $rows, string $a): \SfphpProject\src\View\Sfht',
        '{',
        '    return sfht(',
        '        <ul>',                                           // 5
        '            {{-- a comment',
        '                 over two lines --}}',
        '            @foreach ($rows as $row)',
        '                <li>{{ $row }} {{ $a | upper }}</li>',
        '            @endforeach',                                // 10
        '            @php $n = 1; // counted',
        '            @endphp',
        '            @forelse ($rows as $r) {{ $r }} @empty none @endforelse',
        '            {{',
        '                $a',                                     // 15
        '            }}',
        '            <b>{{ $marker }}</b>',
        '        </ul>',
        '    );',
        '}',                                                      // 20
        '// after',
        '',
    ]);

    $compiled = explode("\n", (new Phpx())->compile($source));

    $tests->assertSame(substr_count($source, "\n"), count($compiled) - 1);
    $tests->assertSame(20, array_search('// after', $compiled, true));
    $tests->assertTrue(str_contains($compiled[8], '($row)'));
    $tests->assertTrue(str_contains($compiled[16], '($marker)'));
});

$tests->run('a template imports a component with @use and calls it by its bare name', function () use ($tests): void {
    /*
     * A compiled template runs in the global namespace and a component is a
     * namespaced function, so {{ Card() }} was "Call to undefined function".
     * @use is PHP's own import, hoisted to the top of the compiled file, so
     * it works even written inside @if or @block.
     */
    eval('namespace SfphpTest\UseComponents; function Card(string $t): \SfphpProject\src\View\Sfht { return new \SfphpProject\src\View\Sfht("<b>" . $t . "</b>"); }');

    $directory = sys_get_temp_dir() . '/sfphp-use-' . bin2hex(random_bytes(6));
    mkdir($directory . '/views', 0777, true);
    file_put_contents(
        $directory . '/views/page.sfht',
        "<p>mail someone@use.example</p>\n@if (true)\n@use(function SfphpTest\\UseComponents\\Card)\n@use('function SfphpTest\\UseComponents\\Card')\n{{ Card(\$title) }}\n@endif"
    );

    try {
        $engine = new SfhtEngine([$directory . '/views'], $directory . '/cache');
        $html = $engine->render('page', ['title' => 'Hi']);

        $tests->assertTrue(str_contains($html, '<b>Hi</b>'));
        $tests->assertTrue(str_contains($html, 'someone@use.example'));
        $tests->assertThrows(
            fn () => (new \SfphpProject\src\View\Compiler())->compile('@use(1 + 1)'),
            RuntimeException::class
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
});

$tests->finish();
