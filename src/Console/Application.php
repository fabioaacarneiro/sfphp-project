<?php

namespace SfphpProject\src\Console;

use SfphpProject\src\Assets;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Config;
use SfphpProject\src\Console\Generators\ControllerGenerator;
use SfphpProject\src\Console\Generators\EventGenerator;
use SfphpProject\src\Console\Generators\FactoryGenerator;
use SfphpProject\src\Console\Generators\ListenerGenerator;
use SfphpProject\src\Console\Generators\MiddlewareGenerator;
use SfphpProject\src\Console\Generators\ModelGenerator;
use SfphpProject\src\Console\Generators\PolicyGenerator;
use SfphpProject\src\Console\Generators\RepositoryGenerator;
use SfphpProject\src\Console\Generators\RequestGenerator;
use SfphpProject\src\Console\Generators\SeederGenerator;
use SfphpProject\src\Console\Generators\ServiceGenerator;
use SfphpProject\src\Console\Generators\TestGenerator;
use SfphpProject\src\Database;
use SfphpProject\src\Database\Seeder;
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationDraft;
use SfphpProject\src\Migrations\MigrationRunner;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Minimal CLI application for framework commands.
 */
final class Application
{
    /**
     * Create a CLI application.
     *
     * @param array<int, string> $argv The raw CLI arguments
     */
    /**
     * The example application's directories, whose contents `reset` removes.
     *
     * @var list<string>
     */
    private const RESET_DIRECTORIES = [
        'app/components',
        'app/controllers',
        'app/models',
        'app/Jobs',
        'app/resources/views',
        'database/migrations',
        'database/seeders',
        'database/factories',
    ];

    /**
     * The migrations `reset` keeps, because the framework itself needs them.
     *
     * The users and sessions tables are what the authentication guard and the
     * database session driver are written against, so a project that dropped
     * them would discover it at its first login rather than here. Everything
     * else under database/migrations was written by the project and goes.
     *
     * Matched by name rather than by timestamp, so renaming the file's date
     * does not quietly turn a kept migration into a deleted one.
     *
     * @var list<string>
     */
    private const RESET_KEEP_MIGRATIONS = [
        'create_users_table',
        'create_sessions_table',
    ];

    /**
     * Every command, once: what `list`, `help` and `help <command>` print.
     *
     * `list` and `help` used to be two lists written by hand, and each had
     * lost commands the other still had — ten were missing from one and seven
     * from the other — while `help <command>` knew eight. A test checks this
     * table against the dispatcher, so a command cannot be added to one and
     * not the other.
     *
     * @var array<string, array{group: string, usage: string, summary: string, details?: list<string>}>
     */
    private const COMMANDS = [
        'serve' => ['group' => 'Server', 'usage' => 'serve [--host=127.0.0.1] [--port=8000]', 'summary' => 'Start the development server',
            'details' => ['Serves public/ through server.php with PHP\'s built-in server, on 127.0.0.1:8000 unless told otherwise.', 'The port is checked first: a port already in use is an error, not a banner.']],
        'routes' => ['group' => 'Server', 'usage' => 'routes [--path=app/routes/web.php]', 'summary' => 'List the registered routes',
            'details' => ['Reads app/routes/web.php and app/routes/api.php, or the file --path names.']],
        'build' => ['group' => 'Server', 'usage' => 'build --phpx [--from=app/components] [--to=app/components/compiled]', 'summary' => 'Compile .phpx components into PHP',
            'details' => ['Mirrors the folders the components live in, and runs php -l over every result.']],
        'env:example' => ['group' => 'Server', 'usage' => 'env:example', 'summary' => 'Create .env from .env-example, with a generated JWT_KEY'],
        'tinker' => ['group' => 'Server', 'usage' => 'tinker', 'summary' => 'Interactive PHP shell with the framework loaded',
            'details' => ['Variables last for the session. A line is shown as a value when it is an expression, and run as a statement when it is not.']],
        'test' => ['group' => 'Server', 'usage' => 'test [filter] [--path=tests]', 'summary' => 'Run the project\'s tests (tests/*Test.php)',
            'details' => ['Runs every TestCase under tests/ — see make:test. A filter keeps the tests whose class or method name contains it.']],

        'make:controller' => ['group' => 'Generate', 'usage' => 'make:controller <Name> [--force]', 'summary' => 'A controller, and the view its action renders'],
        'make:model' => ['group' => 'Generate', 'usage' => 'make:model <Name> [--force]', 'summary' => 'A model'],
        'make:request' => ['group' => 'Generate', 'usage' => 'make:request <Name> [--force]', 'summary' => 'A validation class for a form'],
        'make:middleware' => ['group' => 'Generate', 'usage' => 'make:middleware <Name> [--force]', 'summary' => 'A middleware'],
        'make:event' => ['group' => 'Generate', 'usage' => 'make:event <Name> [--force]', 'summary' => 'An event'],
        'make:listener' => ['group' => 'Generate', 'usage' => 'make:listener <Name> [--force]', 'summary' => 'An event listener'],
        'make:policy' => ['group' => 'Generate', 'usage' => 'make:policy <Name> [--force]', 'summary' => 'An authorization policy that denies until told otherwise'],
        'make:repository' => ['group' => 'Generate', 'usage' => 'make:repository <Name> [--force]', 'summary' => 'A repository'],
        'make:service' => ['group' => 'Generate', 'usage' => 'make:service <Name> [--force]', 'summary' => 'A service'],
        'make:scaffold' => ['group' => 'Generate', 'usage' => 'make:scaffold <Name> [--force]', 'summary' => 'Controller, view, model, repository and service at once',
            'details' => ['A part that already exists is kept and named; --force replaces it.']],
        'make:test' => ['group' => 'Generate', 'usage' => 'make:test <Name> [--force]', 'summary' => 'A test for ./sfphp test'],
        'make:seeder' => ['group' => 'Generate', 'usage' => 'make:seeder <Name> [--force]', 'summary' => 'A seeder'],
        'make:factory' => ['group' => 'Generate', 'usage' => 'make:factory <Name> [--force]', 'summary' => 'A factory for a model'],
        'make:pwa' => ['group' => 'Generate', 'usage' => 'make:pwa [--name="My App"] [--short=] [--description=] [--color=#hex] [--background=#hex] [--logo=path.png] [--enable-push] [--enable-sync]', 'summary' => 'Manifest, service worker and icons for a PWA',
            'details' => ['Reads app/pwa/config.php when it exists; the flags override it. --name is required without one.']],

        'make:migration' => ['group' => 'Database', 'usage' => 'make:migration <name> [field ...] [--path=database/migrations] [--force]', 'summary' => 'A migration, drafted from its name and fields',
            'details' => [
                'The name says what the migration does:',
                '  create_users            creates the table',
                '  add_phone_to_users      alters it',
                '  drop_users_table        drops it',
                'Anything else gets an empty migration to fill in.',
                '',
                'A field is name:type; numbers after it are the type\'s arguments, words are modifiers:',
                '  surname:string:255             $table->string(\'surname\', 255)',
                '  email:string:unique            $table->string(\'email\')->unique()',
                '  price:decimal:8,2              $table->decimal(\'price\', 8, 2)',
                '  active:boolean:default=true    $table->boolean(\'active\')->default(true)',
                '  author_id:foreignId:constrained  $table->foreignId(\'author_id\')->constrained()',
                'A bare word takes no column name: timestamps, softDeletes, rememberToken, id.',
                '',
                'Example: ./sfphp make:migration create_posts title:string body:text timestamps',
            ]],
        'make:migration:create' => ['group' => 'Database', 'usage' => 'make:migration:create <table>', 'summary' => 'Deprecated: make:migration create_<table> does the same'],
        'migrate' => ['group' => 'Database', 'usage' => 'migrate [--path=database/migrations] [--step=N]', 'summary' => 'Run the pending migrations'],
        'rollback' => ['group' => 'Database', 'usage' => 'rollback [--path=database/migrations] [--step=N]', 'summary' => 'Undo the last N migrations (one by default)',
            'details' => ['--step counts migrations, not batches: --step=3 undoes the three most recent, whichever batch they ran in.']],
        'status' => ['group' => 'Database', 'usage' => 'status [--path=database/migrations]', 'summary' => 'Which migrations have run'],
        'db:seed' => ['group' => 'Database', 'usage' => 'db:seed [--class=DatabaseSeeder]', 'summary' => 'Run a seeder'],
        'db:fresh' => ['group' => 'Database', 'usage' => 'db:fresh [--force]', 'summary' => 'Roll back every migration and run them again',
            'details' => ['Asks first, and refuses in production without --force. Only what migrations created is dropped.']],

        'cache:clear' => ['group' => 'Cache & queue', 'usage' => 'cache:clear', 'summary' => 'Remove the cache entries that have expired'],
        'cache:flush' => ['group' => 'Cache & queue', 'usage' => 'cache:flush', 'summary' => 'Remove every cache entry — revoked tokens and rate limits included'],
        'queue:table' => ['group' => 'Cache & queue', 'usage' => 'queue:table', 'summary' => 'Create the database queue\'s tables'],
        'queue:work' => ['group' => 'Cache & queue', 'usage' => 'queue:work [--timeout=3600]', 'summary' => 'Run queued jobs'],
        'queue:failed' => ['group' => 'Cache & queue', 'usage' => 'queue:failed', 'summary' => 'List the jobs that ran out of attempts'],

        'css:build' => ['group' => 'Assets', 'usage' => 'css:build [--config=sfcss.config.json] [--output=dir]', 'summary' => 'Build SFCSS and publish it',
            'details' => ['Reads sfcss.config.json at the project root, or --config. Writes resources/assets/css and copies the result to public/assets/css.']],
        'js:build' => ['group' => 'Assets', 'usage' => 'js:build', 'summary' => 'Bundle SFJS and publish it'],
        'assets:publish' => ['group' => 'Assets', 'usage' => 'assets:publish [--path=public/assets] [--force] [--symlink]', 'summary' => 'Copy SFCSS and SFJS where the browser can reach them',
            'details' => ['A published file you changed yourself is kept unless --force; one the framework published is replaced.']],

        'init' => ['group' => 'Project', 'usage' => 'init', 'summary' => 'Finish a new project: .env, assets, .gitignore and composer scripts',
            'details' => ['composer create-project runs it. Safe to run again: it only adds what is missing.']],
        'reset' => ['group' => 'Project', 'usage' => 'reset [--force]', 'summary' => 'Remove the example application',
            'details' => [
                'Empties app/components, app/controllers, app/models, app/Jobs, app/resources/views,',
                'database/migrations, database/seeders and database/factories, and rewrites the routes file.',
                'Keeps the users migration and a create_sessions_table migration if you made one.',
                'It lists what it will delete and asks you to type "reset". There is no undo.',
            ]],
        'upgrade' => ['group' => 'Project', 'usage' => 'upgrade [--to=v0.32.0] [--from=dir] [--dry-run] [--force]', 'summary' => 'Replace the framework, keep the application',
            'details' => [
                'Replaced whole: src/, sfphp, server.php.',
                'Merged in:      resources/, lang/, tools/ — your files there stay.',
                'Compared:       public/index.php, composer.json and sfcss.config.json are written as <file>.new.',
                'Untouched:      app/, database/, the rest of public/, .env, vendor/.',
                '',
                'Without --to, the latest release. --from= is a copy you already have; --dry-run changes nothing.',
                'Commit before running it: a change you made under src/ is lost.',
            ]],

        'list' => ['group' => 'Help', 'usage' => 'list', 'summary' => 'Every command, briefly'],
        'help' => ['group' => 'Help', 'usage' => 'help [command]', 'summary' => 'This screen, or one command in detail'],
        'version' => ['group' => 'Help', 'usage' => 'version', 'summary' => 'The framework\'s version'],
    ];

    /** Where a release is fetched from, when no local copy is given. */
    private const REPOSITORY = 'https://github.com/fabioaacarneiro/sfphp-project';

    /**
     * What `upgrade` replaces wholesale, because it is entirely the framework.
     *
     * Nothing under here is meant to be edited by a project: a change made to
     * it would be lost at the next release anyway, and silently.
     *
     * @var list<string>
     */
    private const UPGRADE_REPLACE = [
        'src',
        'sfphp',
        'server.php',
    ];

    /**
     * What `upgrade` copies over without deleting what it does not recognise.
     *
     * A project adds its own language files and its own assets beside the
     * framework's, so emptying these directories first would take those with
     * them. The framework's files win; anything else is left alone.
     *
     * @var list<string>
     */
    private const UPGRADE_MERGE = [
        'resources',
        'lang',
        'tools',
    ];

    /**
     * What `upgrade` refuses to touch, and writes beside instead.
     *
     * The front controller and the composer manifest are the project's, and
     * they are also where releases change things. Overwriting them would take
     * a project's own middleware, its bindings and its dependencies with it, so
     * the new version is written as `<file>.new` for a person to read.
     *
     * @var list<string>
     */
    private const UPGRADE_COMPARE = [
        'public/index.php',
        'composer.json',
        /*
         * The documentation tells you to edit this one to change the palette
         * and the spacing, so it is yours — and it sits inside a merged
         * directory, where it was being overwritten by the release's copy. A
         * project that had customised its colours lost them to an upgrade.
         */
        'tools/css-builder/sfcss.config.json',
    ];

    /** What the routes file becomes once the example application is gone. */
    private const EMPTY_ROUTES = <<<'PHP'
        <?php

        /**
         * The application's routes.
         *
         * Example:
         *   Router::get('/', [HomeController::class, 'index'])->name('home');
         *   Router::post('/users', [UserController::class, 'store']);
         *   Router::get('/users/id:number', [UserController::class, 'show']);
         *
         * @package SfphpProject
         */

        use SfphpProject\src\Router;

        PHP;

    public function __construct(private array $argv) {}

    /**
     * Run the CLI application.
     *
     * @return int The process exit code
     */
    public function run(): int
    {
        try {
            $args = $this->argv;
            array_shift($args);

            $command = $args[0] ?? 'help';
            $arguments = array_slice($args, 1);

            /*
             * "<command> --help" explains the command instead of running it.
             * No command read --help, so `upgrade --help` fetched a release.
             */
            if (isset(self::COMMANDS[$command]) && (in_array('--help', $arguments, true) || in_array('-h', $arguments, true))) {
                return $this->printHelp([$command]);
            }

            return match ($command) {
                'help', '--help', '-h' => $this->printHelp($arguments),
                'list', '--list' => $this->listCommands(),
                'version', '--version', '-v' => $this->printVersion(),
                'serve' => $this->serve($arguments),
                'env:example' => $this->envExample(),
                'routes' => $this->routes($arguments),
                'build' => $this->build($arguments),
                'init' => $this->init(),
                'reset' => $this->reset($arguments),
                'upgrade' => $this->upgrade($arguments),
                'css:build' => $this->cssBuild($arguments),
                'js:build' => $this->jsBuild($arguments),
                'make:migration' => $this->makeMigration($arguments),
                'make:migration:create' => $this->makeMigrationCreate($arguments),
                'make:controller' => $this->makeController($arguments),
                'make:model' => $this->makeModel($arguments),
                'make:pwa' => $this->makePwa($arguments),
                'make:repository' => $this->makeRepository($arguments),
                'make:request' => $this->makeRequest($arguments),
                'make:service' => $this->makeService($arguments),
                'make:scaffold' => $this->makeScaffold($arguments),
                'make:test' => $this->makeTest($arguments),
                'make:middleware' => $this->makeMiddleware($arguments),
                'make:event' => $this->makeEvent($arguments),
                'make:listener' => $this->makeListener($arguments),
                'make:policy' => $this->makePolicy($arguments),
                'make:seeder' => $this->makeSeeder($arguments),
                'make:factory' => $this->makeFactory($arguments),
                'migrate' => $this->migrate($arguments),
                'rollback' => $this->rollback($arguments),
                'status' => $this->status($arguments),
                'db:seed' => $this->dbSeed($arguments),
                'db:fresh' => $this->dbFresh($arguments),
                'assets:publish' => $this->assetsPublish($arguments),
                'cache:clear' => $this->cacheClear($arguments),
                'cache:flush' => $this->cacheFlush($arguments),
                'queue:work' => $this->queueWork($arguments),
                'queue:failed' => $this->queueFailed($arguments),
                'queue:table' => $this->queueTable(),
                'test' => $this->test($arguments),
                'tinker' => $this->tinker(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Print the CLI help screen, or one command's.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function printHelp(array $arguments = []): int
    {
        $command = $this->firstArgument($arguments);

        if ($command === null) {
            $this->writeLine('SFPHP ' . self::version());
            $this->writeLine('');
            $this->writeLine('Usage: ./sfphp <command> [arguments]');
            $this->writeLine('       ./sfphp help <command>   (or <command> --help) for one command in detail');

            $group = null;

            foreach (self::COMMANDS as $name => $entry) {
                if ($entry['group'] !== $group) {
                    $group = $entry['group'];
                    $this->writeLine('');
                    $this->writeLine($group . ':');
                }

                $this->writeLine(sprintf('  %-22s %s', $name, $entry['summary']));
            }

            $this->writeLine('');
            $this->writeLine('Examples:');
            $this->writeLine('  ./sfphp make:controller Post');
            $this->writeLine('  ./sfphp make:migration create_posts title:string timestamps');
            $this->writeLine('  ./sfphp serve --port=8080');
            $this->writeLine('  ./sfphp help make:migration');

            return 0;
        }

        $entry = self::COMMANDS[$command] ?? null;

        if ($entry === null) {
            fwrite(STDERR, "Unknown command: $command" . PHP_EOL);
            fwrite(STDERR, 'Run "./sfphp list" to see every command.' . PHP_EOL);

            return 1;
        }

        $this->writeLine('Usage: ./sfphp ' . $entry['usage']);
        $this->writeLine('');
        $this->writeLine($entry['summary'] . '.');

        if (($entry['details'] ?? []) !== []) {
            $this->writeLine('');

            foreach ($entry['details'] as $line) {
                $this->writeLine($line);
            }
        }

        return 0;
    }

    /**
     * Every command, one per line.
     *
     * @return int
     */
    private function listCommands(): int
    {
        foreach (self::COMMANDS as $entry) {
            $this->writeLine('  ./sfphp ' . $entry['usage']);
        }

        return 0;
    }

    /**
     * The commands the console answers.
     *
     * @internal For the test that keeps COMMANDS and the dispatcher in step.
     * @return list<string>
     */
    public static function commandNames(): array
    {
        return array_keys(self::COMMANDS);
    }

    private function printVersion(): int
    {
        $this->writeLine('SFPHP ' . self::version());
        $this->writeLine('');
        $this->writeLine('A zero-dependency PHP framework');
        $this->writeLine('GitHub: https://github.com/fabioaacarneiro/sfphp-project');

        return 0;
    }

    /**
     * Which version of the framework this is.
     *
     * Asked rather than written down. It used to be a literal in the line above,
     * which means every release depends on somebody remembering to edit a string
     * — and the release where they forget is the one that ships a console
     * claiming to be the previous version.
     *
     * Composer's InstalledVersions is generated into the autoloader rather than
     * required as a package, so reading it costs no dependency. It knows the tag
     * because the tag is what Packagist resolved.
     *
     * @return string The version, as well as it can be known
     */
    public static function version(): string
    {
        return \SfphpProject\src\Sfphp::VERSION;
    }

    /**
     * Create a new migration file.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeMigration(array $arguments): int
    {
        $name = $this->firstArgument($arguments);

        if ($name === null) {
            throw new \InvalidArgumentException('Migration name is required.');
        }

        /*
         * Everything after the name that is not an option is a field. The name
         * is the instruction — create_users, add_phone_to_users,
         * drop_sessions_table — so there is nothing else to ask for.
         */
        $fields = [];
        $seenName = false;

        foreach ($this->positionals($arguments) as $argument) {
            if (!$seenName) {
                $seenName = true;

                continue;
            }

            $fields[] = $argument;
        }

        $draft = new MigrationDraft($name, $fields);

        /*
         * The file is written only once the fields have all been read. A typo
         * in the fourth column used to leave a half-written migration behind
         * for somebody to find later.
         */
        $body = $draft->body();

        $directory = $this->option($arguments, 'path') ?? 'database/migrations';
        $creator = new MigrationCreator($this->projectPath($directory));
        $file = $creator->create($name);

        file_put_contents($file, $body);

        $this->writeLine('Created migration: ' . $this->relativePath($file));

        if ($draft->action() === 'plain' && $fields !== []) {
            $this->writeLine('');
            $this->writeLine('  The name does not say which table this is about, so the fields were');
            $this->writeLine('  not used. Name it create_<table>, add_<what>_to_<table> or');
            $this->writeLine('  drop_<table> and they will be.');
        }

        return 0;
    }

    /**
     * Apply pending migrations.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function migrate(array $arguments): int
    {
        $runner = $this->runner($arguments);
        $step = $this->optionInt($arguments, 'step');
        $applied = $runner->migrate($step);

        $this->printResult($applied, 'No migrations were applied.', 'Applied');

        return 0;
    }

    /**
     * Roll back applied migrations.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function rollback(array $arguments): int
    {
        $runner = $this->runner($arguments);
        $step = $this->optionInt($arguments, 'step') ?? 1;
        $reverted = $runner->rollback($step);

        $this->printResult($reverted, 'No migrations were reverted.', 'Reverted');

        return 0;
    }

    /**
     * Show the current migration status.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function status(array $arguments): int
    {
        $runner = $this->runner($arguments);
        $rows = $runner->status();

        if ($rows === []) {
            $this->writeLine('No migrations found.');

            return 0;
        }

        $nameWidth = max(
            10,
            max(array_map(
                static fn (array $row): int => strlen($row['migration']),
                $rows
            ))
        );

        $this->writeLine(str_pad('Migration', $nameWidth) . '  Status');
        $this->writeLine(str_repeat('-', $nameWidth) . '  ------');
        foreach ($rows as $row) {
            $this->writeLine(
                str_pad($row['migration'], $nameWidth)
                . '  '
                . strtoupper($row['status'])
            );
        }

        return 0;
    }

    /**
     * Handle an unknown command.
     *
     * @param string $command The command name
     * @return int
     */
    private function unknownCommand(string $command): int
    {
        $this->writeLine("Unknown command: $command");
        $this->writeLine('Run "./sfphp help" to see available commands.');

        return 1;
    }

    /**
     * Generate a controller.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeController(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Controller name is required.');
        }

        $generator = new ControllerGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created controller: ' . $this->relativePath($file));

        if ($generator->view !== null) {
            $this->writeLine('Created view:       ' . $this->relativePath($generator->view));
        }

        return 0;
    }

    /**
     * Generate a model.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeModel(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Model name is required.');
        }

        $generator = new ModelGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created model: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a repository.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeRepository(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Repository name is required.');
        }

        $generator = new RepositoryGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created repository: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate PWA (Progressive Web App) setup.
     *
     * Settings come from app/pwa/config.php when the project has one, and a
     * flag overrides the file for that run. The command used to ignore the
     * file entirely, so the one place the project was told to configure its
     * PWA changed nothing, and --name was the only way to name the app.
     *
     * It writes into public/ and never edits a template: the <link> and
     * <script> tags that load the manifest and install-sw.js are the
     * layout's to add.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makePwa(array $arguments): int
    {
        $configPath = $this->projectPath('app/pwa/config.php');
        $hasConfig = is_file($configPath);

        try {
            $config = \SfphpProject\src\Pwa\PwaConfig::fromFile($configPath);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: could not read app/pwa/config.php: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

        $name = $this->option($arguments, 'name') ?? ($hasConfig ? $config->get('name') : null);

        if (!is_string($name) || trim($name) === '') {
            $this->writeLine('Error: --name is required when there is no app/pwa/config.php');
            $this->writeLine('Usage: ./sfphp make:pwa --name="My App" [--logo=path/to/logo.png]');
            return 1;
        }

        /*
         * The short name follows --name when --name is given and --short is
         * not: keeping the file's short name would pair a new name with the
         * old app's abbreviation.
         */
        $shortName = $this->option($arguments, 'short')
            ?? ($this->option($arguments, 'name') === null && $hasConfig ? $config->shortName() : mb_substr($name, 0, 12));
        $description = $this->option($arguments, 'description') ?? $config->description();
        $color = $this->option($arguments, 'color') ?? $config->themeColor();
        $background = $this->option($arguments, 'background') ?? $config->backgroundColor();
        $logo = $this->option($arguments, 'logo');
        $push = in_array('--enable-push', $arguments, true) || $config->pushNotificationsEnabled();
        $sync = in_array('--enable-sync', $arguments, true) || $config->backgroundSyncEnabled();

        /*
         * The colours go into the manifest and a <meta> tag as they are, so a
         * typo is caught here rather than by a browser that silently ignores it.
         */
        foreach (['color' => $color, 'background' => $background] as $option => $value) {
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $value) !== 1) {
                fwrite(STDERR, "Error: --{$option} must be a hex colour such as #0d6efd, not \"{$value}\"." . PHP_EOL);

                return 1;
            }
        }

        $publicPath = $this->projectPath('public');
        @mkdir($publicPath . '/assets/icons', 0755, true);

        if ($hasConfig) {
            $this->writeLine('✓ Reading app/pwa/config.php');
        }

        try {
            // 1. Generate manifest.json
            $this->writeLine('✓ Generating manifest.json');
            $manifest = new \SfphpProject\src\Pwa\ManifestGenerator();
            $manifest
                ->name($name)
                ->shortName($shortName)
                ->description($description)
                ->startUrl($config->startUrl())
                ->scope($config->scope())
                ->display($config->display())
                ->orientation($config->orientation())
                ->themeColor($color)
                ->backgroundColor($background);

            foreach ($config->icons() as $icon) {
                $manifest->icon(
                    (string) $icon['src'],
                    (string) $icon['sizes'],
                    (string) ($icon['type'] ?? 'image/png'),
                    (string) ($icon['purpose'] ?? 'any')
                );
            }

            $manifest->save($publicPath . '/manifest.json');

            // 2. Generate service-worker.js
            $this->writeLine('✓ Generating service-worker.js');
            $sw = new \SfphpProject\src\Pwa\ServiceWorkerGenerator($config->version());
            $sw->appName(mb_strtolower(str_replace(' ', '-', $name)))
                ->staticAssets($config->staticAssets())
                ->apiRoutes($config->apiRoutes())
                ->offlineFallback($config->offlineFallback());

            if ($push) {
                $sw->enablePushNotifications();
                $this->writeLine('  ├─ Push notifications enabled');
            }

            if ($sync) {
                $sw->enableBackgroundSync();
                $this->writeLine('  └─ Background sync enabled');
            }

            $sw->save($publicPath . '/service-worker.js');
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

        // 3. Copy offline page
        $this->writeLine('✓ Generating offline.html');
        $template = __DIR__ . '/../../resources/pwa/offline-template.html';
        if (is_file($template)) {
            copy($template, $publicPath . '/offline.html');
        }

        // 4. Copy install script
        $this->writeLine('✓ Copying install-sw.js');
        $script = __DIR__ . '/../../resources/pwa/install-sw.js';
        if (is_file($script)) {
            copy($script, $publicPath . '/install-sw.js');
        }

        // 5. Generate icons (if logo provided)
        if ($logo !== null && !is_file($logo)) {
            $this->writeLine('⚠ Skipping icon generation: ' . $logo . ' not found');
        } elseif ($logo !== null) {
            $this->writeLine('✓ Generating icons from ' . basename($logo));
            /*
             * Throwable, not Exception: a missing GD function or a GD build
             * without WebP ends in an Error, and catching only Exception let
             * it out as a fatal stack trace instead of this message.
             */
            try {
                $iconGen = new \SfphpProject\src\Pwa\IconGenerator($logo);
                $iconGen->generate($publicPath . '/assets/icons');
            } catch (Throwable $e) {
                $this->writeLine('⚠ Could not generate icons: ' . $e->getMessage());
                $this->writeLine('  Add icons manually to public/assets/icons/');
            }
        } else {
            $this->writeLine('⚠ Skipping icon generation (no logo provided)');
            $this->writeLine('  Usage: ./sfphp make:pwa --name="..." --logo=path/to/logo.png');
        }

        $this->writeLine('');
        $this->writeLine('✨ PWA setup complete!');
        $this->writeLine('');
        $this->writeLine('📋 Next Steps:');
        $this->writeLine('  1. Add to your layout\'s <head>:');
        $this->writeLine('       <link rel="manifest" href="/manifest.json">');
        $this->writeLine('       <meta name="theme-color" content="' . $color . '">');

        // Only when it was made: the line used to be printed for a file that did not exist.
        if (is_file($publicPath . '/assets/icons/apple-touch-icon.png')) {
            $this->writeLine('       <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">');
        }

        $this->writeLine('       <script src="/install-sw.js" defer></script>');
        $this->writeLine($hasConfig
            ? '  2. Settings live in app/pwa/config.php; run make:pwa again after changing it'
            : '  2. Copy resources/pwa/config.php to app/pwa/config.php to keep these settings in a file');
        $this->writeLine('  3. Test in DevTools: F12 → Application → Manifest');
        // The guide is not shipped inside a project, so the link is to where it lives.
        $this->writeLine('  4. Read the complete guide: https://github.com/fabioaacarneiro/sfphp-project/blob/master/docs/en/PWA_GUIDE.md');
        $this->writeLine('');

        return 0;
    }

    /**
     * Generate a service.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeService(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Service name is required.');
        }

        $generator = new ServiceGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created service: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a full stack (controller, model, repository, service).
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeScaffold(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Name is required.');
        }

        $this->writeLine('Creating full stack for ' . $name . '...');
        $this->writeLine('');

        $force = in_array('--force', $arguments, true);

        /*
         * A part that already exists is kept and named, and the rest are
         * still written: scaffolding a model you already have should give you
         * the controller, not overwrite the model.
         */
        foreach ([
            'controller' => new ControllerGenerator($this->rootPath(), $force),
            'model' => new ModelGenerator($this->rootPath(), $force),
            'repository' => new RepositoryGenerator($this->rootPath(), $force),
            'service' => new ServiceGenerator($this->rootPath(), $force),
        ] as $part => $generator) {
            try {
                $file = $generator->generate($name);
                $this->writeLine('✓ Created ' . $part . ': ' . $this->relativePath($file));

                if ($generator instanceof ControllerGenerator && $generator->view !== null) {
                    $this->writeLine('✓ Created view: ' . $this->relativePath($generator->view));
                }
            } catch (\SfphpProject\src\Console\Generators\GeneratorFileExists $exists) {
                $this->writeLine('• Kept existing ' . $part . ' (--force replaces it)');
            }
        }

        $this->writeLine('');
        $this->writeLine('Done.');

        return 0;
    }

    /**
     * Start the development server.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function serve(array $arguments): int
    {
        $host = $this->option($arguments, 'host') ?? '127.0.0.1';
        $portOption = $this->option($arguments, 'port') ?? '8000';

        if (!ctype_digit($portOption) || (int) $portOption < 1 || (int) $portOption > 65535) {
            fwrite(STDERR, "Error: --port must be a number from 1 to 65535, not \"{$portOption}\"." . PHP_EOL);

            return 1;
        }

        $port = (int) $portOption;

        if (preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host) !== 1) {
            fwrite(STDERR, "Error: --host \"{$host}\" is not a host name or address." . PHP_EOL);

            return 1;
        }

        /*
         * The port is tried before announcing anything. The banner used to say
         * "Server running" and PHP then failed with "Address already in use",
         * and the command still exited 0.
         */
        $probe = @stream_socket_server('tcp://' . $host . ':' . $port, $errorCode, $errorMessage);

        if ($probe === false) {
            fwrite(STDERR, "Error: cannot listen on {$host}:{$port} ({$errorMessage}). Choose another with --port." . PHP_EOL);

            return 1;
        }

        fclose($probe);

        /*
         * The stylesheet and the script live in the package and are copied into
         * public/ on install. A checkout that has not been installed, or one
         * where public/assets was cleaned, would otherwise serve a page whose
         * <link> 404s — and an unstyled first impression reads as a broken
         * framework rather than as a missing step.
         */
        $published = Assets::publish($this->projectPath(Assets::PUBLIC_PATH));

        if ($published['written'] !== []) {
            $this->writeLine('Published ' . count($published['written']) . ' asset file(s).');
        }

        if ($published['kept'] !== []) {
            // Somebody built their own. Serving it is right; saying nothing
            // about it is not, because an upgrade may have moved on without it.
            $this->writeLine('Kept your own ' . implode(', ', $published['kept'])
                . ' (run assets:publish --force to take the framework\'s).');
        }

        $this->writeLine('Starting development server...');
        $this->writeLine("Server running at http://$host:$port");
        $this->writeLine('Press Ctrl+C to stop');
        $this->writeLine('');

        $root = $this->rootPath();

        /*
         * Quoted, so a project in "My Projects" is served rather than read as
         * two arguments, and with the PHP running this command rather than
         * whichever `php` comes first on the PATH.
         */
        $cmd = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($host . ':' . $port)
            . ' -t ' . escapeshellarg($root . '/public') . ' ' . escapeshellarg($root . '/server.php');

        passthru($cmd, $status);

        return $status;
    }

    /**
     * Create .env from .env-example.
     *
     * @return int
     */
    private function envExample(): int
    {
        $root = $this->rootPath();
        $example = $root . '/.env-example';
        $env = $root . '/.env';

        if (!file_exists($example)) {
            $this->writeLine('Error: .env-example not found');

            return 1;
        }

        if (file_exists($env)) {
            $this->writeLine('.env already exists');

            return 0;
        }

        $contents = (string) file_get_contents($example);

        /*
         * The placeholder is rejected on purpose, so copying the example
         * verbatim leaves a project that cannot issue a token — and the first
         * thing anybody does after creating a project is not read about key
         * generation. A fresh 32-byte key costs nothing and removes the step.
         */
        $contents = str_replace(
            'JWT_KEY=your_secret_token_here',
            'JWT_KEY=' . bin2hex(random_bytes(32)),
            $contents
        );

        if (file_put_contents($env, $contents) === false) {
            fwrite(STDERR, 'Error: could not write ' . $env . PHP_EOL);

            return 1;
        }

        $this->writeLine('Created .env from .env-example, with a generated JWT_KEY');

        return 0;
    }

    /**
     * Display all registered routes.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function routes(array $arguments): int
    {
        try {
            $custom = $this->option($arguments, 'path');

            if ($custom !== null) {
                $file = str_starts_with($custom, '/') ? $custom : $this->projectPath($custom);

                if (!is_file($file)) {
                    fwrite(STDERR, 'Error: ' . $file . ' not found' . PHP_EOL);

                    return 1;
                }

                // Load custom routes file
                require $file;
            } else {
                // Load default routes: web.php and api.php
                $webFile = $this->projectPath('app/routes/web.php');
                $apiFile = $this->projectPath('app/routes/api.php');

                if (!is_file($webFile) && !is_file($apiFile)) {
                    $this->writeLine('No route file found. Looked for app/routes/web.php and app/routes/api.php.');
                    $this->writeLine('Pass one with --path=, or create app/routes/web.php.');

                    return 1;
                }

                if (is_file($webFile)) {
                    require $webFile;
                }

                if (is_file($apiFile)) {
                    require $apiFile;
                }
            }

            // Create a temporary router instance to access the routes
            $container = new \SfphpProject\src\Container();
            $router = new \SfphpProject\src\Router($container);
            $routes = $router->routes();

            if (empty($routes)) {
                $this->writeLine('No routes registered');

                return 0;
            }

            $this->writeLine('Registered Routes:');
            $this->writeLine('');

            $methodWidth = 6;
            $pathWidth = max(20, max(array_map(fn ($r) => strlen($r->getPath()), $routes)));
            $nameWidth = max(10, max(array_map(fn ($r) => strlen($r->getName() ?? ''), $routes)));

            $this->writeLine(
                str_pad('Method', $methodWidth) . '  ' .
                str_pad('Path', $pathWidth) . '  ' .
                str_pad('Name', $nameWidth) . '  ' .
                'Action'
            );
            $this->writeLine(str_repeat('-', $methodWidth + $pathWidth + $nameWidth + 40));

            foreach ($routes as $route) {
                $this->writeLine(
                    str_pad(strtoupper($route->getMethod()), $methodWidth) . '  ' .
                    str_pad($route->getPath(), $pathWidth) . '  ' .
                    str_pad($route->getName() ?? '-', $nameWidth) . '  ' .
                    // The class without its namespace: the column is read to
                    // find the code, and the short name is what one searches for.
                    substr((string) strrchr('\\' . $route->getController(), '\\'), 1) . '::' . $route->getAction()
                );
            }

            return 0;
        } catch (\Throwable $e) {
            $this->writeLine('Error listing routes: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Generate a request class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeRequest(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Request name is required.');
        }

        $generator = new RequestGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created request: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a migration with pre-filled schema.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeMigrationCreate(array $arguments): int
    {
        $table = $this->firstArgument($arguments);

        if ($table === null) {
            throw new \InvalidArgumentException('Table name is required.');
        }

        /*
         * Superseded by `make:migration create_<table> [fields]`, which reads
         * the name instead of taking the table as a separate argument — and
         * which can also alter and drop. Kept because it shipped, forwarding
         * so there is one code path rather than two that drift.
         */
        $this->writeLine('  make:migration:create is deprecated. Use:');
        $this->writeLine('    ./sfphp make:migration create_' . $table . ' title:string timestamps');
        $this->writeLine('');

        $forwarded = ['create_' . $table];

        foreach (array_slice($arguments, 1) as $argument) {
            $forwarded[] = $argument;
        }

        return $this->makeMigration($forwarded);
    }

    /**
     * Build a runner for the given arguments.
     *
     * @param array<int, string> $arguments The command arguments
     * @return MigrationRunner
     */
    private function runner(array $arguments): MigrationRunner
    {
        $directory = $this->projectPath($this->option($arguments, 'path') ?? 'database/migrations');

        return new MigrationRunner(Database::connect(), $directory);
    }

    /**
     * Return the first positional argument.
     *
     * @param array<int, string> $arguments The command arguments
     * @return string|null
     */
    private function firstArgument(array $arguments): ?string
    {
        return $this->positionals($arguments)[0] ?? null;
    }

    /**
     * The options that take a value, which may follow them after a space.
     */
    private const VALUE_OPTIONS = [
        'background', 'class', 'color', 'config', 'description', 'from', 'host', 'logo',
        'name', 'output', 'path', 'port', 'short', 'step', 'timeout', 'to', 'filter',
    ];

    /**
     * The arguments that are not options, and not an option's value.
     *
     * @param array<int, string> $arguments The command arguments
     * @return list<string> The positional arguments, in order
     */
    private function positionals(array $arguments): array
    {
        $positionals = [];
        $arguments = array_values($arguments);

        for ($i = 0, $count = count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--')) {
                // "--path dir": the next word is this option's value, not a name.
                if (!str_contains($argument, '=') && in_array(substr($argument, 2), self::VALUE_OPTIONS, true)) {
                    $i++;
                }

                continue;
            }

            $positionals[] = $argument;
        }

        return $positionals;
    }

    /**
     * Read a string option from the arguments.
     *
     * @param array<int, string> $arguments The command arguments
     * @param string $name The option name
     * @return string|null
     */
    private function option(array $arguments, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        $arguments = array_values($arguments);

        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }

            /*
             * "--port 8001" as well as "--port=8001". The spaced form used to
             * be ignored without a word — the server started on 8000 and
             * make:pwa --name "My App" said --name was missing.
             */
            if ($argument === '--' . $name && isset($arguments[$index + 1]) && !str_starts_with($arguments[$index + 1], '--')) {
                return $arguments[$index + 1];
            }
        }

        return null;
    }

    /**
     * Read an integer option from the arguments.
     *
     * @param array<int, string> $arguments The command arguments
     * @param string $name The option name
     * @return int|null
     */
    private function optionInt(array $arguments, string $name): ?int
    {
        $value = $this->option($arguments, $name);
        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException("Option --$name must be a positive integer.");
        }

        return (int) $value;
    }

    /**
     * The first of these paths that exists.
     *
     * @param list<string> $paths The candidates, nearest first
     * @return string|null The first that is a file, or null
     */
    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert a relative project path to an absolute path.
     *
     * @param string $path The relative project path
     * @return string
     */
    private function projectPath(string $path): string
    {
        /*
         * An absolute path is already the answer. Hanging it off the root used
         * to produce a directory nobody has, and a command handed one then
         * reported there was nothing to do — a silence that reads as success.
         */
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($this->rootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Format a project-relative path for output.
     *
     * @param string $path The absolute path
     * @return string
     */
    private function relativePath(string $path): string
    {
        $root = $this->rootPath() . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root)
            ? substr($path, strlen($root))
            : $path;
    }

    /**
     * Get the project root path.
     *
     * @return string
     */
    private function rootPath(): string
    {
        /*
         * The project, not the package. Installed under vendor/, dirname of
         * this file names the framework's own directory, so `make:controller`
         * would write into vendor/fabioaacarneiro/sfphp-framework/app/ and the file would vanish on
         * the next `composer update`. Bootstrap knows where the application is
         * because the entry point told it.
         */
        return Bootstrap::basePath();
    }

    /**
     * Print a list of migration names under a label.
     *
     * @param array<int, string> $items The items to print
     * @param string $emptyMessage The message when no items are present
     * @param string $label The list label
     * @return void
     */
    private function printResult(array $items, string $emptyMessage, string $label): void
    {
        if ($items === []) {
            $this->writeLine($emptyMessage);

            return;
        }

        $this->writeLine($label . ':');
        foreach ($items as $item) {
            $this->writeLine('  - ' . $item);
        }
    }

    /**
     * Generate a test class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeTest(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Test name is required.');
        }

        $generator = new TestGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created test: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a middleware class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeMiddleware(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Middleware name is required.');
        }

        $generator = new MiddlewareGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created middleware: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate an event class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeEvent(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Event name is required.');
        }

        $generator = new EventGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created event: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate an event listener class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeListener(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Listener name is required.');
        }

        $generator = new ListenerGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created listener: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a policy class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makePolicy(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Policy name is required.');
        }

        $generator = new PolicyGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created policy: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a seeder class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeSeeder(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Seeder name is required.');
        }

        $generator = new SeederGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created seeder: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Generate a factory class.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function makeFactory(array $arguments): int
    {
        $name = $this->firstArgument($arguments);
        if ($name === null) {
            throw new \InvalidArgumentException('Factory name is required.');
        }

        $generator = new FactoryGenerator($this->rootPath(), in_array('--force', $arguments, true));
        $file = $generator->generate($name);

        $this->writeLine('Created factory: ' . $this->relativePath($file));

        return 0;
    }

    /**
     * Run database seeders.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function dbSeed(array $arguments): int
    {
        $seedPath = $this->projectPath('database/seeders');

        if (!is_dir($seedPath)) {
            $this->writeLine('No seeders directory found.');
            return 0;
        }

        try {
            $requested = $this->option($arguments, 'class') ?? 'DatabaseSeeder';
            $class = str_contains((string) $requested, '\\')
                ? (string) $requested
                : 'Database\\Seeders\\' . $requested;

            if (!class_exists($class)) {
                fwrite(STDERR, "Seeder not found: {$class}" . PHP_EOL);
                $this->writeLine('');
                $this->writeLine('Available seeders in database/seeders:');

                foreach (glob($seedPath . '/*.php') ?: [] as $file) {
                    $this->writeLine('  - ' . basename($file, '.php'));
                }

                return 1;
            }

            $seeder = new $class();

            if (!$seeder instanceof Seeder) {
                fwrite(STDERR, "{$class} must extend " . Seeder::class . PHP_EOL);

                return 1;
            }

            $this->writeLine("Seeding: {$class}");
            $seeder->run();
            $this->writeLine('Database seeded successfully.');

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Error: ' . $throwable->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Reset the database and run migrations.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function dbFresh(array $arguments): int
    {
        try {
            /*
             * The one database command that throws data away, and it used to
             * do so without a word. It asks, like reset and upgrade, and it
             * will not run in production without --force.
             */
            if (!in_array('--force', $arguments, true)) {
                if (Config::get('APP_ENV') === 'production') {
                    fwrite(STDERR, 'Refusing to run db:fresh in production. Pass --force if that is what you mean.' . PHP_EOL);

                    return 1;
                }

                if (!stream_isatty(STDIN)) {
                    fwrite(STDERR, 'Refusing to run db:fresh with no terminal to confirm at. Pass --force if that is what you mean.' . PHP_EOL);

                    return 1;
                }

                fwrite(STDOUT, 'db:fresh rolls back every migration — dropping the tables they created and their data — and runs them again.' . PHP_EOL);
                fwrite(STDOUT, 'Type "fresh" to confirm: ');

                if (strtolower(trim((string) fgets(STDIN))) !== 'fresh') {
                    $this->writeLine('Nothing was changed.');

                    return 0;
                }
            }

            $runner = $this->runner($arguments);
            $missing = $runner->fresh();

            $this->writeLine('Every migration was rolled back.');

            foreach ($missing as $migration) {
                fwrite(STDERR, "Warning: {$migration} was recorded but its file is gone, so what it created was not dropped." . PHP_EOL);
            }

            $this->writeLine('Running migrations...');

            $applied = $runner->migrate();
            $this->printResult($applied, 'No migrations were applied.', 'Applied');

            return 0;
        } catch (Throwable $e) {
            $this->writeLine('Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Copy the framework's stylesheet and script into the project.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function assetsPublish(array $arguments): int
    {
        try {
            /*
             * SFCSS and SFJS ship inside the package, where a browser cannot
             * reach them. This is how they get to a directory a project serves
             * — the same command for a fresh clone and for an installation,
             * because they are the same problem.
             */
            $force = in_array('--force', $arguments, true);
            $target = $this->option($arguments, 'path') ?? $this->projectPath(Assets::PUBLIC_PATH);

            if (in_array('--symlink', $arguments, true)) {
                $linked = Assets::link($target, $force);

                if ($linked === []) {
                    $this->writeLine('Already linked to ' . $this->relativePath(Assets::path()) . '.');

                    return 0;
                }

                foreach ($linked as $directory) {
                    $this->writeLine('  ' . $this->relativePath($target . '/' . $directory)
                        . ' -> ' . $this->relativePath(Assets::path() . '/' . $directory));
                }

                $this->writeLine('');
                $this->writeLine('Linked. There is now one copy on disk, and an upgrade changes it immediately.');

                return 0;
            }

            $result = Assets::publish($target, $force);

            foreach ($result['kept'] as $relative) {
                $this->writeLine('  kept   ' . $this->relativePath($target . '/' . $relative)
                    . ' — yours, and different from the framework\'s');
            }

            if ($result['written'] === []) {
                $this->writeLine($result['kept'] === []
                    ? 'Assets are already up to date. Use --force to copy them anyway.'
                    : 'Nothing copied. Use --force to replace your files with the framework\'s.');

                return 0;
            }

            foreach ($result['written'] as $relative) {
                $this->writeLine('  wrote  ' . $this->relativePath($target . '/' . $relative));
            }

            $this->writeLine('');
            $this->writeLine('Published ' . count($result['written']) . ' file(s).');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Remove the cache entries that have expired.
     *
     * This used to call flush(), the same as cache:flush, while its help said
     * "clear expired entries". The cache is not only cached pages: it holds
     * the revoked-token list, the rate-limit counters and, with
     * SESSION_DRIVER=cache, every session. Flushing it brought revoked tokens
     * back, reset every limit and logged everyone out.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function cacheClear(array $arguments): int
    {
        try {
            $removed = cache()->prune();

            $this->writeLine(sprintf('Removed %d expired cache %s.', $removed, $removed === 1 ? 'entry' : 'entries'));

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Remove every cache entry.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function cacheFlush(array $arguments): int
    {
        try {
            cache()->flush();

            $this->writeLine('All cache entries removed. Revoked tokens, rate-limit counters and cache-held sessions were reset with them.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Finish a project that was just created.
     *
     * The package is the project, so a created project started with the
     * framework's own repository files: no .gitignore — it is export-ignored —
     * so the first `git add .` committed .env with its JWT key and vendor/;
     * and composer scripts that run the framework's test suite, which is not
     * shipped, so `composer test` failed. This puts the project's versions in
     * place. Each step only adds what is missing, so running it twice is safe.
     *
     * @return int
     */
    private function init(): int
    {
        $status = $this->envExample();
        $this->publishAfterBuild();

        $gitignore = $this->projectPath('.gitignore');
        $stub = $this->rootPath() . '/resources/project/gitignore';

        if (!is_file($gitignore) && is_file($stub) && copy($stub, $gitignore)) {
            $this->writeLine('Created .gitignore');
        }

        /*
         * Only in a created project: the framework's own checkout has its
         * suite at tests/run.php and keeps its scripts.
         */
        $composer = $this->projectPath('composer.json');

        if (!is_file($this->projectPath('tests/run.php')) && is_file($composer)) {
            $decoded = json_decode((string) file_get_contents($composer), true);

            if (is_array($decoded) && isset($decoded['scripts']) && is_array($decoded['scripts'])) {
                $scripts = $decoded['scripts'];
                unset($scripts['test:db'], $scripts['test:all'], $scripts['docs']);
                $scripts['test'] = '@php sfphp test';
                $scripts['lint'] = "find app database public -type f -name '*.php' -print0 | xargs -0 -n1 php -l";
                $decoded['scripts'] = $scripts;

                file_put_contents($composer, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                $this->writeLine('Set the composer scripts for this project (composer test runs ./sfphp test)');
            }
        }

        return $status;
    }

    /**
     * Publish what a build just wrote, so the browser gets it.
     *
     * A build used to end with "run assets:publish", and assets:publish then
     * kept the old file as if it were the user's own. The build publishes
     * itself now; a file under public/ that was changed by hand is still kept,
     * and named.
     *
     * @return void
     */
    private function publishAfterBuild(): void
    {
        $result = Assets::publish($this->projectPath(Assets::PUBLIC_PATH));

        foreach ($result['written'] as $relative) {
            $this->writeLine('Published public/assets/' . $relative);
        }

        foreach ($result['kept'] as $relative) {
            $this->writeLine('Kept public/assets/' . $relative . ' — it was changed by hand. assets:publish --force replaces it.');
        }
    }

    /**
     * Create the database queue's tables.
     *
     * @return int
     */
    private function queueTable(): int
    {
        try {
            (new \SfphpProject\src\Queue\DatabaseDriver(
                table: (string) (Config::get('QUEUE_TABLE') ?: 'jobs')
            ))->createTables();

            $this->writeLine('The queue tables exist.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Run the project's tests.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function test(array $arguments): int
    {
        $directory = $this->projectPath($this->option($arguments, 'path') ?? 'tests');

        if (!is_dir($directory)) {
            fwrite(STDERR, 'No tests directory at ' . $this->relativePath($directory) . '. Create one with ./sfphp make:test Example.' . PHP_EOL);

            return 1;
        }

        $passed = (new \SfphpProject\src\Testing\Runner())->run(
            $directory,
            $this->firstArgument($arguments),
            fn (string $line) => $this->writeLine($line)
        );

        return $passed ? 0 : 1;
    }

    /**
     * Start queue worker.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function queueWork(array $arguments): int
    {
        try {
            $timeout = $this->optionInt($arguments, 'timeout') ?? 3600;
            $queue = queue();

            $this->writeLine('Starting queue worker (timeout: ' . $timeout . 's)...');
            $this->writeLine('Press CTRL+C to stop.');

            /*
             * Said up front, because both are silent otherwise: a worker
             * without pcntl looks the same until a deploy waits on it or a
             * job hangs past its timeout.
             */
            if (!function_exists('pcntl_async_signals')) {
                $this->writeLine('ext-pcntl is not loaded: SIGTERM stops the worker immediately, not after the current job, and job timeouts are not enforced.');
            }

            $this->writeLine('');

            $queue->work((int) $timeout);

            $this->writeLine('Queue worker stopped.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Show failed jobs.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function queueFailed(array $arguments): int
    {
        try {
            $queue = queue();
            $failed = $queue->failed();

            if (empty($failed)) {
                $this->writeLine('No failed jobs.');
                return 0;
            }

            $this->writeLine('Failed Jobs:');
            foreach ($failed as $job) {
                $this->writeLine('  - ' . $job['id'] . ' (failed at ' . date('Y-m-d H:i:s', $job['failed_at']) . ')');
            }

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Build SFCSS from config.json.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function jsBuild(array $arguments): int
    {
        try {
            $builderPath = dirname(Assets::path(), 2) . "/tools/js-builder/sfjs-builder.php";

            if (!is_file($builderPath)) {
                fwrite(STDERR, "Error: sfjs-builder.php not found at {$builderPath}" . PHP_EOL);

                return 1;
            }

            $output = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($builderPath) . ' 2>&1', $output, $status);

            if ($status !== 0) {
                fwrite(STDERR, 'Error: Failed to minify SFJS' . PHP_EOL);
                fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

                return 1;
            }

            $this->writeLine(implode(PHP_EOL, $output));
            $this->writeLine('');
            $this->publishAfterBuild();

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Build SFCSS from its configuration.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function build(array $arguments): int
    {
        if (!in_array('--phpx', $arguments, true)) {
            fwrite(STDERR, 'Nothing to build. Pass --phpx to compile .phpx components.' . PHP_EOL);

            return 1;
        }

        try {
            $from = $this->option($arguments, 'from') ?? 'app/components';
            $to = $this->option($arguments, 'to') ?? 'app/components/compiled';

            $source = $this->projectPath($from);
            $target = $this->projectPath($to);

            if (!is_dir($source)) {
                $this->writeLine('No ' . $from . ' directory. Nothing to compile.');

                return 0;
            }

            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                fwrite(STDERR, 'Error: could not create ' . $target . PHP_EOL);

                return 1;
            }

            $compiler = new \SfphpProject\src\View\Phpx();
            $built = 0;

            foreach ($this->componentFiles($source, $target) as $file) {
                /*
                 * The compiler's messages carry the line of the .phpx; the
                 * file is named here, where it is known, the same way the
                 * lint failure below names it.
                 */
                try {
                    $php = $compiler->compile((string) file_get_contents($file));
                } catch (RuntimeException $exception) {
                    fwrite(STDERR, 'Error in ' . $this->relativePath($file) . ': ' . $exception->getMessage() . PHP_EOL);

                    return 1;
                }

                /*
                 * The tree under the source is mirrored under the target, so a
                 * page whose components live together in a folder compiles to
                 * a folder rather than to six files loose among everything
                 * else. One component per file is the convention; the
                 * directory is what keeps that from becoming a pile.
                 */
                $out = $target . '/' . substr($file, strlen($source) + 1, -strlen('.phpx')) . '.php';
                $directory = dirname($out);

                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    fwrite(STDERR, 'Error: could not create ' . $directory . PHP_EOL);

                    return 1;
                }

                if (file_put_contents($out, $php) === false) {
                    fwrite(STDERR, 'Error: could not write ' . $out . PHP_EOL);

                    return 1;
                }

                /*
                 * Linted here rather than trusted. A .phpx cannot be checked by
                 * php -l, but what it produces can, and catching a broken
                 * component at build time is the whole reason this step exists.
                 */
                $status = 0;
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($out) . ' 2>&1', $output, $status);

                if ($status !== 0) {
                    fwrite(STDERR, 'Error in ' . $this->relativePath($file) . ':' . PHP_EOL);
                    fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

                    return 1;
                }

                $this->writeLine('  ' . $this->relativePath($file) . ' -> ' . $this->relativePath($out));
                $built++;
            }

            $this->writeLine('');
            $this->writeLine($built === 0 ? 'No .phpx files found.' : 'Compiled ' . $built . ' component(s).');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Every .phpx under a directory, in a stable order.
     *
     * Recursive, because the components of one page belong in one folder. The
     * target is skipped: it usually sits inside the source, and compiling what
     * was just compiled would be a loop with output.
     *
     * @param string $source The directory to scan
     * @param string $target The directory being written to
     * @return list<string> The absolute paths
     */
    private function componentFiles(string $source, string $target): array
    {
        $files = [];

        $directories = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directories,
            static fn (SplFileInfo $file): bool => $file->getPathname() !== $target
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile() && $file->getExtension() === 'phpx') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Remove the example application, so a project starts from its own code.
     *
     * The package ships an application: a home page, a controller, components,
     * a model, a seeder. It is there to be read and run, and it is in the way
     * the moment somebody starts writing their own — so this takes it out.
     *
     * Migrations are kept. The users and sessions tables are what the database
     * session driver and the authentication guard are written against, and a
     * project that deleted them would find out at the first login rather than
     * here.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function reset(array $arguments): int
    {
        try {
            $plan = [];
            $total = 0;

            foreach (self::RESET_DIRECTORIES as $relative) {
                $files = array_values(array_filter(
                    $this->filesUnder($this->projectPath($relative)),
                    fn (string $file): bool => $this->resetRemoves($file)
                ));

                if ($files === []) {
                    continue;
                }

                $plan[$relative] = $files;
                $total += count($files);
            }

            $routes = $this->routesFile();

            /*
             * A routes file already reduced to the stub is nothing to do, so
             * running this twice says so rather than announcing a deletion it
             * is not going to make.
             */
            if ($routes !== null && file_get_contents($routes) === self::EMPTY_ROUTES) {
                $routes = null;
            }

            if ($total === 0 && $routes === null) {
                $this->writeLine('Nothing to reset. The example application is already gone.');

                return 0;
            }

            $this->writeLine('');
            $this->writeLine('  RESET — this removes the example application from this project.');
            $this->writeLine('');

            foreach ($plan as $relative => $files) {
                $this->writeLine(sprintf('    %-24s %d file(s)', $relative . '/', count($files)));
            }

            if ($routes !== null) {
                $this->writeLine(sprintf('    %-24s rewritten with no routes', $this->relativePath($routes)));
            }

            $this->writeLine('');
            $this->writeLine('  Kept: app/config, lang, public, the framework, the users migration the');
            $this->writeLine('        authentication guard is written against, and a create_sessions_table');
            $this->writeLine('        migration if you made one for the database session driver.');
            $this->writeLine('  Not kept: your migrations, and anything of your own already living in');
            $this->writeLine('            the directories above.');
            $this->writeLine('');
            $this->writeLine('  This cannot be undone. Nothing is backed up and nothing goes to a trash bin.');
            $this->writeLine('');

            if (!in_array('--force', $arguments, true)) {
                /*
                 * A pipe or a CI job has nobody to answer, and a command that
                 * deletes a project's application must not proceed on silence.
                 */
                if (!stream_isatty(STDIN)) {
                    fwrite(STDERR, 'Refusing to reset with no terminal to confirm at. Pass --force if that is what you mean.' . PHP_EOL);

                    return 1;
                }

                fwrite(STDOUT, '  Type "reset" to confirm: ');
                $answer = strtolower(trim((string) fgets(STDIN)));

                if ($answer !== 'reset') {
                    $this->writeLine('');
                    $this->writeLine('Nothing was removed.');

                    return 0;
                }

                $this->writeLine('');
            }

            foreach ($plan as $relative => $files) {
                $directory = $this->projectPath($relative);
                $this->assertInsideProject($directory);

                foreach ($files as $file) {
                    unlink($file);
                }

                $this->pruneEmptyDirectories($directory);
                $this->writeLine('  removed  ' . count($files) . ' from ' . $relative . '/');
            }

            if ($routes !== null && file_put_contents($routes, self::EMPTY_ROUTES) === false) {
                fwrite(STDERR, 'Error: could not rewrite ' . $this->relativePath($routes) . PHP_EOL);

                return 1;
            }

            if ($routes !== null) {
                $this->writeLine('  rewrote  ' . $this->relativePath($routes));
            }

            $this->writeLine('');
            $this->writeLine('Reset. ' . $total . ' file(s) removed.');
            $this->writeLine('');
            $this->writeLine('Next: ./sfphp make:controller Home, then add a route and a view.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Every file under a directory, deepest first.
     *
     * @param string $directory The directory
     * @return list<string> The absolute paths
     */
    private function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Decide whether `reset` removes a file.
     *
     * @param string $file The absolute path
     * @return bool True when the file goes
     */
    private function resetRemoves(string $file): bool
    {
        $name = basename($file);

        /*
         * A .gitkeep is there to keep an empty directory in version control,
         * which is exactly the state this leaves behind.
         */
        if ($name === '.gitkeep') {
            return false;
        }

        $inMigrations = str_contains(
            str_replace('\\', '/', $file),
            '/database/migrations/'
        );

        if (!$inMigrations) {
            return true;
        }

        foreach (self::RESET_KEEP_MIGRATIONS as $kept) {
            if (str_contains($name, $kept)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuse to touch anything outside the project.
     *
     * This deletes recursively, so a path that resolved somewhere unexpected —
     * a symbolic link, a mistaken option — is the one mistake worth refusing
     * outright rather than reporting afterwards.
     *
     * @param string $directory The directory
     * @return void
     * @throws RuntimeException When the directory is not inside the project
     */
    private function assertInsideProject(string $directory): void
    {
        $root = realpath($this->rootPath());
        $resolved = realpath($directory);

        if ($resolved === false) {
            /*
             * A path that does not exist yet — the destination of a copy — is
             * resolved through its parent, so that it can still be checked
             * rather than waved through.
             */
            $parent = realpath(dirname($directory));
            $resolved = $parent === false ? false : $parent . DIRECTORY_SEPARATOR . basename($directory);
        }

        if ($resolved === false || $root === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to touch ' . $directory . ', which is not inside the project.');
        }
    }

    /**
     * Remove the empty directories left under a directory, keeping it.
     *
     * The directory itself stays because it is where the next thing goes, and
     * an application that has to guess which directories to recreate is one
     * that fails on the first `make:` command.
     *
     * @param string $directory The directory
     * @return void
     */
    private function pruneEmptyDirectories(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            // glob() would report "." and ".." here; the iterator does not.
            if (!(new FilesystemIterator($entry->getPathname(), FilesystemIterator::SKIP_DOTS))->valid()) {
                rmdir($entry->getPathname());
            }
        }
    }

    /**
     * Replace the framework in place, keeping the application around it.
     *
     * A project created from this package does not have the framework as a
     * dependency — the framework's files *are* the project — so `composer
     * update` has nothing to update. Upgrading means replacing those files,
     * which is a job of knowing which ones they are.
     *
     * Three kinds of path, and the difference is the whole command:
     *
     * - **Replaced** — `src/`, the binary, the dev server. Entirely the
     *   framework. A change made there was going to be lost at the next
     *   release anyway.
     * - **Merged** — `resources/`, `lang/`, `tools/`. The framework's files
     *   land on top; a language file or an asset a project added beside them
     *   stays.
     * - **Compared** — `public/index.php` and `composer.json`. The project's,
     *   and also where releases change things, so the new version is written as
     *   `<file>.new` for a person to read rather than applied.
     *
     * Everything else — `app/`, `database/`, the rest of `public/`, `.env` —
     * is never touched.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function upgrade(array $arguments): int
    {
        try {
            $source = $this->option($arguments, 'from');
            $temporary = null;

            if ($source === null) {
                /*
                 * The latest release unless one is named. It used to be
                 * master — unreleased code — and a plain `upgrade` offered to
                 * install whatever had been pushed that morning.
                 */
                $reference = $this->option($arguments, 'to') ?? $this->latestRelease();

                if ($reference === null) {
                    fwrite(STDERR, 'Error: could not find the latest release. Name one with --to=v0.32.0, or pass --from=<directory>.' . PHP_EOL);

                    return 1;
                }

                $temporary = $this->fetchFramework($reference);

                if ($temporary === null) {
                    return 1;
                }

                $source = $temporary;
            }

            $source = rtrim($source, DIRECTORY_SEPARATOR);

            if (!is_file($source . '/src/Bootstrap.php') || !is_file($source . '/sfphp')) {
                fwrite(STDERR, 'Error: ' . $source . ' does not look like SFPHP.' . PHP_EOL);

                return 1;
            }

            $status = $this->applyUpgrade($source, $arguments);

            if ($temporary !== null) {
                exec('rm -rf ' . escapeshellarg($temporary) . ' 2>/dev/null');
            }

            return $status;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Get a copy of the framework at some version.
     *
     * @param string $reference A tag or a branch
     * @return string|null The directory, or null when it could not be fetched
     */
    private function fetchFramework(string $reference): ?string
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            fwrite(STDERR, 'Error: git is needed to fetch a release. Pass --from=<directory> instead.' . PHP_EOL);

            return null;
        }

        $directory = sys_get_temp_dir() . '/sfphp-upgrade-' . bin2hex(random_bytes(6));

        $this->writeLine('  fetching ' . $reference . ' …');

        $command = sprintf(
            'git clone --quiet --depth 1 --branch %s %s %s 2>&1',
            escapeshellarg($reference),
            escapeshellarg(self::REPOSITORY),
            escapeshellarg($directory)
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        if ($status !== 0) {
            fwrite(STDERR, 'Error: could not fetch ' . $reference . ':' . PHP_EOL);
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

            return null;
        }

        /*
         * A clone carries everything in the repository, including what the
         * package leaves out — the framework's own tests, its tooling, its
         * documentation. The paths .gitattributes marks export-ignore are
         * removed, so the upgrade brings what an install would have.
         */
        $attributes = $directory . '/.gitattributes';

        if (is_file($attributes)) {
            foreach (file($attributes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('#^\s*/?([^\s#]+)\s+export-ignore\b#', $line, $match) === 1) {
                    $path = $directory . '/' . trim($match[1], '/');

                    if (str_starts_with(realpath($path) ?: '', $directory . '/')) {
                        exec('rm -rf ' . escapeshellarg($path) . ' 2>/dev/null');
                    }
                }
            }
        }

        return $directory;
    }

    /**
     * The newest released version, from the repository's tags.
     *
     * @return string|null The tag, or null when it cannot be found
     */
    private function latestRelease(): ?string
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            return null;
        }

        $output = [];
        exec('git ls-remote --tags --refs ' . escapeshellarg(self::REPOSITORY) . ' 2>/dev/null', $output);

        $tags = [];

        foreach ($output as $line) {
            if (preg_match('#refs/tags/(v?\d+\.\d+\.\d+)$#', $line, $match) === 1) {
                $tags[] = $match[1];
            }
        }

        if ($tags === []) {
            return null;
        }

        usort($tags, static fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $tags[0];
    }

    /**
     * Show what an upgrade would do, ask, and then do it.
     *
     * @param string $source A copy of the framework to upgrade to
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function applyUpgrade(string $source, array $arguments): int
    {
        $replace = [];
        $merge = [];
        $compare = [];

        foreach (self::UPGRADE_REPLACE as $relative) {
            if (file_exists($source . '/' . $relative)) {
                $replace[$relative] = is_dir($source . '/' . $relative)
                    ? count($this->filesUnder($source . '/' . $relative))
                    : 1;
            }
        }

        foreach (self::UPGRADE_MERGE as $relative) {
            if (is_dir($source . '/' . $relative)) {
                $merge[$relative] = count($this->filesUnder($source . '/' . $relative));
            }
        }

        foreach (self::UPGRADE_COMPARE as $relative) {
            $theirs = $source . '/' . $relative;
            $ours = $this->projectPath($relative);

            if (is_file($theirs) && is_file($ours) && file_get_contents($theirs) !== file_get_contents($ours)) {
                $compare[] = $relative;
            }
        }

        $this->writeLine('');
        $this->writeLine('  UPGRADE — this replaces the framework inside this project.');
        $this->writeLine('');

        foreach ($replace as $relative => $count) {
            $this->writeLine(sprintf('    %-22s %4d file(s), replaced', $relative, $count));
        }

        $overwritten = $this->upgradeOverwrites($source, array_keys($merge));

        foreach ($merge as $relative => $count) {
            $this->writeLine(sprintf('    %-22s %4d file(s), merged in', $relative . '/', $count));
        }

        if ($overwritten !== []) {
            /*
             * The merged directories are where a project's files sit beside
             * the framework's, so "merged" sounds safer than it is: a file of
             * yours with the same name as one of theirs is replaced. Naming
             * them is the difference between a warning and an informed
             * decision.
             */
            $this->writeLine('');
            $this->writeLine('    Your versions of these will be replaced:');

            foreach (array_slice($overwritten, 0, 12) as $file) {
                $this->writeLine('      ' . $file);
            }

            if (count($overwritten) > 12) {
                $this->writeLine(sprintf('      … and %d more', count($overwritten) - 12));
            }
        }

        if ($compare !== []) {
            $this->writeLine('');
            $this->writeLine('    Yours, and changed by this release — left alone, the new one beside it:');

            foreach ($compare as $relative) {
                $this->writeLine(sprintf('      %s  →  %s.new', $relative, $relative));
            }
        }

        $this->writeLine('');
        $this->writeLine('  Untouched: app/, database/, the rest of public/, .env, vendor/.');
        $this->writeLine('  Anything you changed under the replaced paths is lost. Commit first.');
        $this->writeLine('');

        if (in_array('--dry-run', $arguments, true)) {
            $this->writeLine('Dry run: nothing was changed.');

            return 0;
        }

        if (!in_array('--force', $arguments, true)) {
            if (!stream_isatty(STDIN)) {
                fwrite(STDERR, 'Refusing to upgrade with no terminal to confirm at. Pass --force if that is what you mean.' . PHP_EOL);

                return 1;
            }

            fwrite(STDOUT, '  Type "upgrade" to confirm: ');

            if (strtolower(trim((string) fgets(STDIN))) !== 'upgrade') {
                $this->writeLine('');
                $this->writeLine('Nothing was changed.');

                return 0;
            }

            $this->writeLine('');
        }

        foreach (array_keys($replace) as $relative) {
            $target = $this->projectPath($relative);
            $this->assertInsideProject($target);

            if (is_dir($target)) {
                $this->emptyTree($target);
                rmdir($target);
            } elseif (is_file($target)) {
                unlink($target);
            }

            $this->copyTree($source . '/' . $relative, $target);
            $this->writeLine('  replaced  ' . $relative);
        }

        $protected = [];

        foreach (self::UPGRADE_COMPARE as $relative) {
            $protected[$this->projectPath($relative)] = true;
        }

        foreach (array_keys($merge) as $relative) {
            $this->copyTree($source . '/' . $relative, $this->projectPath($relative), $protected);
            $this->writeLine('  merged    ' . $relative . '/');
        }

        foreach ($compare as $relative) {
            copy($source . '/' . $relative, $this->projectPath($relative . '.new'));
            $this->writeLine('  wrote     ' . $relative . '.new — compare it with yours');
        }

        if (is_file($this->projectPath('sfphp'))) {
            chmod($this->projectPath('sfphp'), 0755);
        }

        $this->writeLine('');
        $this->writeLine('Upgraded. Next:');
        $this->writeLine('  composer dump-autoload');
        $this->writeLine('  ./sfphp assets:publish --force');

        if (is_dir($this->projectPath('app/components'))) {
            $this->writeLine('  ./sfphp build --phpx');
        }

        $this->upgradeNotes();

        return 0;
    }

    /**
     * The project's own files that a merge would replace.
     *
     * @param string $source The release being upgraded to
     * @param list<string> $directories The merged directories
     * @return list<string> Paths relative to the project
     */
    private function upgradeOverwrites(string $source, array $directories): array
    {
        $protected = [];

        foreach (self::UPGRADE_COMPARE as $relative) {
            $protected[$relative] = true;
        }

        $files = [];

        foreach ($directories as $directory) {
            foreach ($this->filesUnder($source . '/' . $directory) as $theirs) {
                $relative = $directory . substr($theirs, strlen($source . '/' . $directory));
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                if (isset($protected[$relative])) {
                    continue;
                }

                $ours = $this->projectPath($relative);

                if (is_file($ours) && file_get_contents($ours) !== file_get_contents($theirs)) {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Say what a release moved, where a project would otherwise not notice.
     *
     * A file that changed place is the one thing this command cannot do for
     * you: your routes are yours, and putting them where the new front
     * controller looks is a decision about your application.
     *
     * @return void
     */
    private function upgradeNotes(): void
    {
        $notes = [];

        if (is_file($this->projectPath('src/routes.php')) && !is_file($this->projectPath('app/routes/web.php'))) {
            $notes[] = 'Routes moved from src/routes.php to app/routes/web.php and app/routes/api.php.'
                . ' Copy yours across: the new public/index.php loads the new location.';
        }

        if ($notes === []) {
            return;
        }

        $this->writeLine('');
        $this->writeLine('Worth knowing:');

        foreach ($notes as $note) {
            $this->writeLine('  - ' . $note);
        }
    }

    /**
     * Copy a file, or a directory and everything under it.
     *
     * @param string $from The source
     * @param string $to The destination
     * @param array<string, bool> $protected Destination paths to leave alone
     * @return void
     */
    private function copyTree(string $from, string $to, array $protected = []): void
    {
        if (isset($protected[$to])) {
            return;
        }

        if (is_file($from)) {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }

            copy($from, $to);

            return;
        }

        if (!is_dir($from)) {
            return;
        }

        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new RuntimeException('Could not create ' . $to);
        }

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->copyTree($entry->getPathname(), $to . '/' . $entry->getFilename(), $protected);
        }
    }

    /**
     * Remove everything under a directory, leaving the directory.
     *
     * @param string $directory The directory
     * @return void
     */
    private function emptyTree(string $directory): void
    {
        $this->assertInsideProject($directory);

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());

                continue;
            }

            unlink($entry->getPathname());
        }
    }

    /**
     * The project's routes file, wherever it keeps it.
     *
     * @return string|null The absolute path, or null when there is none
     */
    private function routesFile(): ?string
    {
        foreach (['app/routes/web.php', 'app/routes/api.php', 'routes/web.php', 'routes.php'] as $candidate) {
            $path = $this->projectPath($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Build SFCSS from its configuration.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function cssBuild(array $arguments): int
    {
        try {
            /*
             * The builder lives in the package, wherever that is: in this
             * repository it is the project, and in somebody else's it is under
             * vendor/. Looking only in the project was why `css:build` failed
             * for everyone who installed the framework rather than cloning it.
             */
            $packageRoot = dirname(Assets::path(), 2);
            $builderPath = $packageRoot . '/tools/css-builder/sfcss-builder.php';

            /*
             * A project's own config wins. Editing the one inside vendor/ would
             * work until the next composer update threw the edit away, so a
             * project that wants a different palette keeps its own copy.
             */
            $configPath = $this->option($arguments, 'config')
                ?? $this->firstExisting([
                    $this->projectPath('sfcss.config.json'),
                    $this->projectPath('tools/css-builder/sfcss.config.json'),
                    $packageRoot . '/tools/css-builder/sfcss.config.json',
                ]);

            /*
             * In this repository the build updates what the package ships. In a
             * project that installed it, writing into vendor/ would be thrown
             * away by the next update, so the build goes where the browser
             * reads from.
             */
            $outputPath = $this->option($arguments, 'output')
                ?? ($packageRoot === $this->rootPath()
                    ? $packageRoot . '/resources/assets/css'
                    : $this->projectPath(Assets::PUBLIC_PATH . '/css'));

            if ($configPath === null || !is_file($configPath)) {
                fwrite(STDERR, 'Error: no sfcss.config.json found. Copy one from '
                    . $packageRoot . '/tools/css-builder/sfcss.config.json'
                    . ' or pass --config=path.' . PHP_EOL);

                return 1;
            }

            if (!is_file($builderPath)) {
                fwrite(STDERR, "Error: sfcss-builder.php not found at {$builderPath}" . PHP_EOL);
                return 1;
            }

            if (!is_dir($outputPath) && !mkdir($outputPath, 0755, true) && !is_dir($outputPath)) {
                fwrite(STDERR, 'Error: could not create ' . $outputPath . PHP_EOL);

                return 1;
            }

            /*
             * The builder writes both stylesheets itself and prints a summary.
             * This command used to capture that stdout and write it over
             * sfcss.css, so every run replaced the stylesheet with two lines
             * of build log. Run it and let it write; only report the result.
             */
            $output = [];
            $status = 0;
            exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($builderPath)
                . ' ' . escapeshellarg($configPath)
                . ' ' . escapeshellarg($outputPath) . ' 2>&1',
                $output,
                $status
            );

            if ($status !== 0) {
                fwrite(STDERR, 'Error: Failed to build CSS' . PHP_EOL);
                fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

                return 1;
            }

            /*
             * A successful build can still have something to say: the builder
             * warns on stderr about a colour pair that fails contrast or a
             * utility produced twice. The output used to be shown only on
             * failure, so those warnings were swallowed on exactly the builds
             * that shipped. Everything except the builder's own "Generated"
             * summary, which is reprinted below with sizes, goes to stderr.
             */
            foreach ($output as $line) {
                if (trim($line) !== '' && !str_starts_with($line, '✓ Generated:')) {
                    fwrite(STDERR, $line . PHP_EOL);
                }
            }

            $stylesheet = $outputPath . '/sfcss.css';

            if (!is_file($stylesheet)) {
                fwrite(STDERR, "Error: builder did not produce {$stylesheet}" . PHP_EOL);

                return 1;
            }

            clearstatcache(true, $stylesheet);
            $outputPath = $stylesheet;
            $minifiedPath = dirname($stylesheet) . '/sfcss.min.css';

            $this->writeLine('SFCSS built successfully.');
            $this->writeLine('  ' . $outputPath . ' (' . number_format(filesize($outputPath)) . ' bytes)');

            if (is_file($minifiedPath)) {
                clearstatcache(true, $minifiedPath);
                $this->writeLine('  ' . $minifiedPath . ' (' . number_format(filesize($minifiedPath)) . ' bytes)');
            }

            $this->writeLine('');
            $this->publishAfterBuild();

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Start the interactive Tinker shell.
     *
     * @return int
     */
    private function tinker(): int
    {
        if (!function_exists('readline')) {
            $this->writeLine('Error: readline extension is required for tinker.');
            $this->writeLine('Install it with: apt-get install php-cli-common');
            return 1;
        }

        $tinker = new Tinker($this->rootPath());
        $tinker->start();

        return 0;
    }

    /**
     * Write a single line to STDOUT.
     *
     * @param string $line The line to write
     * @return void
     */
    private function writeLine(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
