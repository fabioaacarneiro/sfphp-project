<?php

namespace SfphpProject\src\Console;

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
                'help', '--help', '-h' => $this->printHelp(),
                'make:migration' => $this->makeMigration($arguments),
                'migrate' => $this->migrate($arguments),
                'rollback' => $this->rollback($arguments),
                'status' => $this->status($arguments),
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
    private function printHelp(): int
    {
        $this->writeLine('SFPHP CLI');
        $this->writeLine('');
        $this->writeLine('Usage: ./sfphp <command> [options]');
        $this->writeLine('');
        $this->writeLine('Commands:');
        $this->writeLine('  make:migration <name> [--path=database/migrations]');
        $this->writeLine('  migrate [--path=database/migrations] [--step=1]');
        $this->writeLine('  rollback [--path=database/migrations] [--step=1]');
        $this->writeLine('  status [--path=database/migrations]');

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
