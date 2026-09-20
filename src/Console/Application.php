<?php

namespace SfphpProject\src\Console;

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
use SfphpProject\src\Migrations\MigrationCreator;
use SfphpProject\src\Migrations\MigrationRunner;
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
            $this->writeLine('Server & Database Commands:');
            $this->writeLine('  serve                 Start development server (localhost:8000)');
            $this->writeLine('  env:example           Create .env from .env-example');
            $this->writeLine('  routes                List all registered routes');
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
        $this->writeLine('SFPHP v1.0.0');
        $this->writeLine('');
        $this->writeLine('A minimal, educational PHP microframework');
        $this->writeLine('GitHub: https://github.com/fabioaacarneiro/sfphp-project');

        return 0;
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

        copy($example, $env);
        $this->writeLine('Created .env from .env-example');

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
            $file = $this->rootPath() . '/src/routes.php';

            if (!file_exists($file)) {
                $this->writeLine('Error: src/routes.php not found');

                return 1;
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
     * Convert a relative project path to an absolute path.
     *
     * @param string $path The relative project path
     * @return string
     */
    private function projectPath(string $path): string
    {
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
        return dirname(__DIR__, 2);
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

        $files = array_diff(scandir($seedPath), ['.', '..']);
        if (empty($files)) {
            $this->writeLine('No seeders found.');
            return 0;
        }

        $this->writeLine('Seeders found:');
        foreach ($files as $file) {
            if (str_ends_with($file, '.php')) {
                $this->writeLine('  - ' . $file);
            }
        }

        $this->writeLine('To run a seeder, create a seeder class in database/seeders/');
        $this->writeLine('Example: ./sfphp make:seeder UsersSeeder (not yet implemented)');

        return 0;
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
