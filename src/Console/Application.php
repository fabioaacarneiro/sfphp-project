<?php

namespace SfphpProject\src\Console;

use SfphpProject\src\Assets;
use SfphpProject\src\Bootstrap;
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

    /** What the routes file becomes once the example application is gone. */
    private const EMPTY_ROUTES = <<<'PHP'
        <?php

        /**
         * The application's routes.
         *
         * Example:
         *   Router::get('/', 'HomeController', 'index')->name('home');
         *   Router::post('/users', 'UserController', 'store');
         *   Router::get('/users/id:number', 'UserController', 'show');
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

            return match ($command) {
                'help', '--help', '-h' => $this->printHelp($arguments),
                'list', '--list' => $this->listCommands(),
                'version', '--version', '-v' => $this->printVersion(),
                'serve' => $this->serve($arguments),
                'env:example' => $this->envExample(),
                'routes' => $this->routes($arguments),
                'build' => $this->build($arguments),
                'reset' => $this->reset($arguments),
                'css:build' => $this->cssBuild($arguments),
                'js:build' => $this->jsBuild($arguments),
                'make:migration' => $this->makeMigration($arguments),
                'make:migration:create' => $this->makeMigrationCreate($arguments),
                'make:controller' => $this->makeController($arguments),
                'make:model' => $this->makeModel($arguments),
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
                'tinker' => $this->tinker(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * Print the CLI help screen.
     *
     * @return int
     */
    private function printHelp(array $arguments = []): int
    {
        $command = $this->firstArgument($arguments);

        if ($command === null) {
            $this->writeLine('SFPHP CLI');
            $this->writeLine('');
            $this->writeLine('Usage: ./sfphp <command> [arguments]');
            $this->writeLine('');
            $this->writeLine('Generation Commands:');
            $this->writeLine('  make:controller <name>     Generate a controller skeleton');
            $this->writeLine('  make:model <name>          Generate a model skeleton');
            $this->writeLine('  make:repository <name>     Generate a repository skeleton');
            $this->writeLine('  make:request <name>        Generate a form request class');
            $this->writeLine('  make:service <name>        Generate a service skeleton');
            $this->writeLine('  make:scaffold <name>       Generate full stack (controller, model, repository, service)');
            $this->writeLine('');
            $this->writeLine('Migration Commands:');
            $this->writeLine('  make:migration <name>            [--path=database/migrations]');
            $this->writeLine('  make:migration:create <table>    [--path=database/migrations]');
            $this->writeLine('  migrate                          [--path=database/migrations] [--step=N]');
            $this->writeLine('  rollback                         [--path=database/migrations] [--step=N]');
            $this->writeLine('  status                           [--path=database/migrations]');
            $this->writeLine('');
            $this->writeLine('Seeding & Factory Commands:');
            $this->writeLine('  make:seeder <name>    Generate a seeder class');
            $this->writeLine('  make:factory <name>   Generate a factory class');
            $this->writeLine('  db:seed               Run database seeders');
            $this->writeLine('');
            $this->writeLine('Cache Commands:');
            $this->writeLine('  assets:publish        Copy SFCSS and SFJS into public/assets');
            $this->writeLine('  cache:clear           Clear expired cache entries');
            $this->writeLine('  cache:flush           Flush all cache');
            $this->writeLine('');
            $this->writeLine('Queue Commands:');
            $this->writeLine('  queue:work            Start queue worker [--timeout=3600]');
            $this->writeLine('  queue:failed          List failed jobs');
            $this->writeLine('');
            $this->writeLine('Server & CSS Commands:');
            $this->writeLine('  serve                 Start development server (localhost:8000)');
            $this->writeLine('  env:example           Create .env from .env-example');
            $this->writeLine('  routes                List all registered routes');
            $this->writeLine('  build --phpx          Compile .phpx components into PHP');
            $this->writeLine('  reset                 Remove the example application [--force]');
            $this->writeLine('  css:build             Build SFCSS from config.json');
            $this->writeLine('  js:build              Minify SFJS');
            $this->writeLine('');
            $this->writeLine('Utility Commands:');
            $this->writeLine('  list                  Show all available commands');
            $this->writeLine('  version               Show framework version');
            $this->writeLine('  help [command]        Show help for a command');
            $this->writeLine('');
            $this->writeLine('Examples:');
            $this->writeLine('  ./sfphp make:controller Post');
            $this->writeLine('  ./sfphp make:scaffold User');
            $this->writeLine('  ./sfphp serve');
            $this->writeLine('  ./sfphp routes');
            $this->writeLine('  ./sfphp help migrate');

            return 0;
        }

        // Show help for specific command
        switch ($command) {
            case 'make:scaffold':
                $this->writeLine('Usage: ./sfphp make:scaffold <name>');
                $this->writeLine('');
                $this->writeLine('Generate a full CRUD stack (controller, model, repository, service)');
                $this->writeLine('');
                $this->writeLine('Example: ./sfphp make:scaffold Post');
                break;
            case 'make:migration:create':
                $this->writeLine('Usage: ./sfphp make:migration:create <table> [--path=dir]');
                $this->writeLine('');
                $this->writeLine('Create a migration with pre-filled schema (id, timestamps)');
                $this->writeLine('');
                $this->writeLine('Example: ./sfphp make:migration:create posts');
                break;
            case 'serve':
                $this->writeLine('Usage: ./sfphp serve');
                $this->writeLine('');
                $this->writeLine('Start the built-in PHP development server');
                $this->writeLine('Server runs on http://localhost:8000');
                break;
            case 'routes':
                $this->writeLine('Usage: ./sfphp routes');
                $this->writeLine('');
                $this->writeLine('Display a table of all registered application routes');
                break;
            case 'css:build':
                $this->writeLine('Usage: ./sfphp css:build');
                $this->writeLine('');
                $this->writeLine('Build SFCSS stylesheet from tools/css-builder/sfcss.config.json');
                $this->writeLine('Output: public/assets/css/sfcss.css');
                $this->writeLine('');
                $this->writeLine('Edit tools/css-builder/sfcss.config.json to customize colors and spacing.');
                break;
            case 'reset':
                $this->writeLine('Usage: ./sfphp reset [--force]');
                $this->writeLine('');
                $this->writeLine('Remove the example application, so the project starts from its own code.');
                $this->writeLine('');
                $this->writeLine('Emptied: app/components, app/controllers, app/models, app/Jobs,');
                $this->writeLine('         app/resources/views, database/migrations, database/seeders,');
                $this->writeLine('         database/factories.');
                $this->writeLine('The routes file is rewritten with no routes.');
                $this->writeLine('');
                $this->writeLine('Kept: app/config, lang, public, the framework, and the two migrations');
                $this->writeLine('the framework ships — users and sessions, which the authentication');
                $this->writeLine('guard and the database session driver are written against. Migrations');
                $this->writeLine('you wrote go with everything else.');
                $this->writeLine('');
                $this->writeLine('It lists what it will delete and asks you to type "reset" first.');
                $this->writeLine('--force skips the question, for a script. There is no undo.');
                break;
            default:
                $this->writeLine("Help for command '$command' not available");
                $this->writeLine("Run './sfphp help' to see all commands");
        }

        return 0;
    }

    private function listCommands(): int
    {
        $this->writeLine('Available Commands:');
        $this->writeLine('');
        $this->writeLine('Generation:');
        $this->writeLine('  make:controller');
        $this->writeLine('  make:model');
        $this->writeLine('  make:repository');
        $this->writeLine('  make:request');
        $this->writeLine('  make:service');
        $this->writeLine('  make:scaffold');
        $this->writeLine('');
        $this->writeLine('Generation Commands:');
        $this->writeLine('  make:controller <name>     Generate a controller skeleton');
        $this->writeLine('  make:model <name>          Generate a model skeleton');
        $this->writeLine('  make:repository <name>     Generate a repository skeleton');
        $this->writeLine('  make:service <name>        Generate a service skeleton');
        $this->writeLine('  make:request <name>        Generate a form request validation class');
        $this->writeLine('  make:scaffold <name>       Generate full stack (controller, model, repository, service)');
        $this->writeLine('  make:test <name>           Generate a test class');
        $this->writeLine('  make:middleware <name>     Generate a middleware class');
        $this->writeLine('  make:event <name>          Generate an event class');
        $this->writeLine('  make:listener <name>       Generate an event listener');
        $this->writeLine('  make:policy <name>         Generate an authorization policy');
        $this->writeLine('');
        $this->writeLine('Migration Commands:');
        $this->writeLine('  make:migration <name>            [--path=database/migrations]');
        $this->writeLine('  make:migration:create <table>    [--path=database/migrations]');
        $this->writeLine('  migrate                          [--path=database/migrations] [--step=N]');
        $this->writeLine('  rollback                         [--path=database/migrations] [--step=N]');
        $this->writeLine('  status                           [--path=database/migrations]');
        $this->writeLine('');
        $this->writeLine('Database Commands:');
        $this->writeLine('  db:seed                    Run database seeders');
        $this->writeLine('  db:fresh                   Reset database and run migrations');
        $this->writeLine('');
        $this->writeLine('Server & Development:');
        $this->writeLine('  serve                      Start development server (localhost:8000)');
        $this->writeLine('  env:example                Create .env from .env-example');
        $this->writeLine('  routes                     List all registered routes');
        $this->writeLine('  build --phpx               Compile .phpx components into PHP');
        $this->writeLine('  reset                      Remove the example application [--force]');
        $this->writeLine('  tinker                     Interactive PHP shell');
        $this->writeLine('');
        $this->writeLine('Utility Commands:');
        $this->writeLine('  list                       Show all available commands');
        $this->writeLine('  version                    Show framework version');
        $this->writeLine('  help [command]             Show help for a command');
        $this->writeLine('  tinker                     Interactive PHP shell');
        $this->writeLine('');
        $this->writeLine('Examples:');
        $this->writeLine('  ./sfphp make:scaffold Post');
        $this->writeLine('  ./sfphp make:test PostTest');
        $this->writeLine('  ./sfphp migrate');
        $this->writeLine('  ./sfphp db:fresh');
        $this->writeLine('  ./sfphp serve');
        $this->writeLine('  ./sfphp tinker');

        return 0;
    }

    private function printVersion(): int
    {
        $this->writeLine('SFPHP ' . self::version());
        $this->writeLine('');
        $this->writeLine('A minimal, educational PHP microframework');
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
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('fabioaacarneiro/sfphp-framework');

                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\OutOfBoundsException) {
                // Not installed as a dependency: this is a clone, handled below.
            }

            $root = \Composer\InstalledVersions::getRootPackage();
            $version = $root['pretty_version'] ?? '';

            if (is_string($version) && $version !== '' && !str_contains($version, 'no-version-set')) {
                return $version;
            }
        }

        // A clone with no tag reachable. Saying so beats inventing a number.
        return 'dev';
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

        $directory = $this->option($arguments, 'path') ?? 'database/migrations';
        $creator = new MigrationCreator($this->projectPath($directory));
        $file = $creator->create($name);

        $this->writeLine('Created migration: ' . $this->relativePath($file));

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

        $generator = new ControllerGenerator($this->rootPath());
        $file = $generator->generate($name);

        $this->writeLine('Created controller: ' . $this->relativePath($file));

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

        $generator = new ModelGenerator($this->rootPath());
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

        $generator = new RepositoryGenerator($this->rootPath());
        $file = $generator->generate($name);

        $this->writeLine('Created repository: ' . $this->relativePath($file));

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

        $generator = new ServiceGenerator($this->rootPath());
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

        $controller = new ControllerGenerator($this->rootPath());
        $controllerFile = $controller->generate($name);
        $this->writeLine('✓ Created controller: ' . $this->relativePath($controllerFile));

        $model = new ModelGenerator($this->rootPath());
        $modelFile = $model->generate($name);
        $this->writeLine('✓ Created model: ' . $this->relativePath($modelFile));

        $repository = new RepositoryGenerator($this->rootPath());
        $repositoryFile = $repository->generate($name);
        $this->writeLine('✓ Created repository: ' . $this->relativePath($repositoryFile));

        $service = new ServiceGenerator($this->rootPath());
        $serviceFile = $service->generate($name);
        $this->writeLine('✓ Created service: ' . $this->relativePath($serviceFile));

        $this->writeLine('');
        $this->writeLine('Full stack created successfully!');

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
        $host = 'localhost';
        $port = 8000;

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
        $cmd = "php -S $host:$port -t $root/public $root/server.php";

        passthru($cmd);

        return 0;
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
            /*
             * Where the route file is depends on whose project this is. This
             * repository keeps it in src/, `sfphp init` writes it at the root,
             * and an application may well have chosen routes/web.php — so all
             * three are looked for rather than one being assumed. Guessing
             * src/routes.php made `routes` fail in every installed project.
             */
            $file = $this->firstExisting([
                $this->projectPath('routes.php'),
                $this->projectPath('src/routes.php'),
                $this->projectPath('routes/web.php'),
            ]);

            if ($file === null) {
                $this->writeLine('No route file found. Looked for routes.php, src/routes.php and routes/web.php.');
                $this->writeLine('Pass one with --path=, or run ./sfphp init to scaffold a project.');

                return 1;
            }

            $custom = $this->option($arguments, 'path');

            if ($custom !== null) {
                $file = str_starts_with($custom, '/') ? $custom : $this->projectPath($custom);

                if (!is_file($file)) {
                    fwrite(STDERR, 'Error: ' . $file . ' not found' . PHP_EOL);

                    return 1;
                }
            }

            // Load routes to populate the static Router::$routes
            require $file;

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
                    $route->getAction()
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

        $generator = new RequestGenerator($this->rootPath());
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

        $directory = $this->option($arguments, 'path') ?? 'database/migrations';
        $creator = new \SfphpProject\src\Migrations\MigrationCreator($this->projectPath($directory));

        $timestamp = date('YmdHis');
        $name = "{$timestamp}_create_{$table}_table";
        $file = $creator->create($name);

        // Replace the stub with a pre-filled schema
        $tableSingular = rtrim($table, 's');
        $content = <<<PHP
<?php

use SfphpProject\\src\\Migrations\\Blueprint;
use SfphpProject\\src\\Migrations\\Migration;
use SfphpProject\\src\\Migrations\\Schema;

return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        \$schema->create('$table', function (Blueprint \$table): void {
            \$table->id();
            // \$table->string('name');
            \$table->timestamps();
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->dropIfExists('$table');
    }
};
PHP;

        file_put_contents($file, $content);

        $this->writeLine('Created migration: ' . $this->relativePath($file));
        $this->writeLine('');
        $this->writeLine('Next steps:');
        $this->writeLine('1. Edit the migration to add your columns');
        $this->writeLine('2. Run: ./sfphp migrate');

        return 0;
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
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                return $argument;
            }
        }

        return null;
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

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
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

        $generator = new TestGenerator($this->rootPath());
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

        $generator = new MiddlewareGenerator($this->rootPath());
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

        $generator = new EventGenerator($this->rootPath());
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

        $generator = new ListenerGenerator($this->rootPath());
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

        $generator = new PolicyGenerator($this->rootPath());
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

        $generator = new SeederGenerator($this->rootPath());
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

        $generator = new FactoryGenerator($this->rootPath());
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
            $runner = $this->runner($arguments);
            $runner->fresh();

            $this->writeLine('Database dropped and recreated successfully.');
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
     * Clear expired cache entries.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function cacheClear(array $arguments): int
    {
        try {
            $cache = cache();
            $cache->flush();

            $this->writeLine('Cache cleared successfully.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * Flush all cache.
     *
     * @param array<int, string> $arguments The command arguments
     * @return int
     */
    private function cacheFlush(array $arguments): int
    {
        try {
            $cache = cache();
            $cache->flush();

            $this->writeLine('All cache flushed successfully.');

            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
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
            exec('php ' . escapeshellarg($builderPath) . ' 2>&1', $output, $status);

            if ($status !== 0) {
                fwrite(STDERR, 'Error: Failed to minify SFJS' . PHP_EOL);
                fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

                return 1;
            }

            $this->writeLine(implode(PHP_EOL, $output));
            $this->writeLine('');
            $this->writeLine('Run ./sfphp assets:publish to copy it where the browser can reach it.');

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
                $php = $compiler->compile((string) file_get_contents($file));

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
                exec('php -l ' . escapeshellarg($out) . ' 2>&1', $output, $status);

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
            $this->writeLine('  Kept: app/config, lang, public, the framework, and the framework\'s own');
            $this->writeLine('        migrations — the users and sessions tables the authentication');
            $this->writeLine('        guard and the database session driver are written against.');
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
        $resolved = realpath($directory);
        $root = realpath($this->rootPath());

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
     * The project's routes file, wherever it keeps it.
     *
     * @return string|null The absolute path, or null when there is none
     */
    private function routesFile(): ?string
    {
        foreach (['routes.php', 'src/routes.php', 'routes/web.php'] as $candidate) {
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
                'php ' . escapeshellarg($builderPath)
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
            $this->writeLine('Run ./sfphp assets:publish to copy it where the browser can reach it.');

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
