<?php

/*
 * What every part of the suite needs before it runs: the autoloader, the
 * framework booted, the test runner and the fixtures. Loaded by tests/run.php.
 */

require __DIR__ . '/../vendor/autoload.php';

/*
 * Explicit, because the example application's config used to do it through
 * composer's autoload-dev files entry — which is loaded whenever this package
 * is the root one, including in a `composer create-project` install, where it
 * required a file the package does not ship.
 *
 * Without the project's .env. A developer's .env said APP_ENV=development, so
 * the suite skipped the assertions that only hold in production, and every
 * local run passed while CI — which has no .env — failed for weeks. The suite
 * now runs the same everywhere; a test that needs a setting sets it.
 */
SfphpProject\src\Bootstrap::load(dirname(__DIR__), ['env' => null]);

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
use SfphpProject\src\Http\Emitter;
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

    /** Every value bound, across statements, for tests that inspect them. */
    public static array $bound = [];

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        self::$bound[] = $value;

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
