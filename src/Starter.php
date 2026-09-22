<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * The files a new project needs before it can answer a request.
 *
 * `composer require` delivers a framework and nothing that runs: no front
 * controller, no route file, no view. Someone who installs it and types
 * `./vendor/bin/sfphp serve` deserves a page rather than a 404 and a hunt
 * through the documentation for what to create.
 *
 * So the package carries a starter and `sfphp init` writes it out, the same
 * shape as Assets::publish: copy what is missing, refuse to clobber what is
 * there, say what happened.
 *
 * What it writes is **the application's**, not the framework's. Nothing here is
 * updated by a later `composer update`, and deleting any of it is the expected
 * next step once you have your own.
 */
final class Starter
{
    private static ?string $root = null;

    /**
     * The directory the starter files are read from.
     *
     * @return string An absolute path
     */
    public static function path(): string
    {
        return self::$root ??= dirname(__DIR__) . '/resources/starter';
    }

    /**
     * Read the starter from somewhere else.
     *
     * @param string|null $path The directory, or null for the package's
     * @return void
     */
    public static function usePath(?string $path): void
    {
        self::$root = $path === null ? null : rtrim($path, '/');
    }

    /**
     * Every file the starter is made of.
     *
     * @return list<string> Paths relative to the starter directory
     */
    public static function files(): array
    {
        return [
            'public/index.php',
            'server.php',
            'routes.php',
            'app/Controllers/WelcomeController.php',
            'resources/views/welcome.sfht',
        ];
    }

    /**
     * Write the starter into a project.
     *
     * A file that already exists is left alone unless $force says otherwise.
     * Overwriting somebody's front controller because they ran a command twice
     * is the kind of help nobody asks for again.
     *
     * @param string $target The project directory
     * @param string $namespace The namespace the application's classes live in
     * @param bool $force Overwrite files that are already there
     * @return array{written: list<string>, skipped: list<string>} What happened
     * @throws RuntimeException When a directory or file cannot be written
     */
    public static function publish(string $target, string $namespace = 'App\\', bool $force = false): array
    {
        $target = rtrim($target, '/');
        $namespace = self::normaliseNamespace($namespace);
        $written = [];
        $skipped = [];

        foreach (self::files() as $relative) {
            $destination = $target . '/' . $relative;

            if (!$force && file_exists($destination)) {
                $skipped[] = $relative;

                continue;
            }

            $contents = self::read($relative);
            $contents = str_replace('{NAMESPACE}', $namespace, $contents);

            $directory = dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create ' . $directory . '.');
            }

            if (file_put_contents($destination, $contents) === false) {
                throw new RuntimeException('Could not write ' . $destination . '.');
            }

            $written[] = $relative;
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * The autoload entry a project needs for the starter's classes.
     *
     * Returned rather than written: editing somebody's composer.json from a
     * command is a surprise, and a line they paste is a line they understand.
     *
     * @param string $namespace The namespace
     * @return array<string, string> A PSR-4 mapping
     */
    public static function autoload(string $namespace = 'App\\'): array
    {
        return [self::normaliseNamespace($namespace) => 'app/'];
    }

    /**
     * Put a namespace in the shape PSR-4 wants.
     *
     * @param string $namespace What the caller gave
     * @return string The namespace, with exactly one trailing separator
     * @throws RuntimeException When it is not a usable namespace
     */
    private static function normaliseNamespace(string $namespace): string
    {
        $namespace = trim(str_replace('/', '\\', $namespace), '\\');

        if ($namespace === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
            throw new RuntimeException(
                'The namespace "' . $namespace . '" is not a valid PHP namespace. Try App or Acme\\Shop.'
            );
        }

        return $namespace . '\\';
    }

    /**
     * Read one starter file.
     *
     * @param string $relative The path inside the starter directory
     * @return string The contents
     * @throws RuntimeException When it is not there
     */
    private static function read(string $relative): string
    {
        $path = self::path() . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('The starter file ' . $relative . ' is missing from ' . self::path() . '.');
        }

        return $contents;
    }
}
