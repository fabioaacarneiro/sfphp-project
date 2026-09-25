<?php

namespace SfphpProject\src\Console\Generators;

use InvalidArgumentException;

/**
 * Base class for code generators.
 */
abstract class GeneratorBase
{
    /** The namespace this repository's own example application uses. */
    private const EXAMPLE_NAMESPACE = 'SfphpProject\\app\\';

    protected string $projectRoot;

    private ?string $applicationNamespace = null;

    /** Whether a file that already exists may be replaced. */
    private bool $overwrite = false;

    public function __construct(string $projectRoot, bool $overwrite = false)
    {
        $this->projectRoot = $projectRoot;
        $this->overwrite = $overwrite;
    }

    /**
     * The word every class this generator writes ends in.
     *
     * Taken from the generator's own name — ControllerGenerator writes
     * ...Controller — except for models, which are named as the thing itself.
     *
     * @return string The suffix, or '' for none
     */
    protected function suffix(): string
    {
        $kind = substr((string) strrchr('\\' . static::class, '\\'), 1, -strlen('Generator'));

        return $kind === 'Model' ? '' : $kind;
    }

    /**
     * Validate and normalize a class name.
     *
     * The suffix is taken off when it was typed, because the generator adds
     * it: `make:test PostTest` used to write PostTestTest, and
     * `make:controller ProductController` a ProductControllerController. The
     * first letter is upper-cased, so `make:controller product` does not
     * write productController.php — a file that collides with
     * ProductController.php on macOS and Windows.
     *
     * @param string $name The raw name
     * @return string The name, without the suffix
     */
    protected function validateName(string $name): string
    {
        if (trim($name) === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid class name: $name");
        }

        $suffix = $this->suffix();

        if ($suffix !== '' && strlen($name) > strlen($suffix) && strcasecmp(substr($name, -strlen($suffix)), $suffix) === 0) {
            $name = substr($name, 0, -strlen($suffix));
        }

        return ucfirst($name);
    }

    /**
     * Get the full path where the file should be written.
     *
     * @param string $directory The directory (relative to project root)
     * @param string $name The class name
     * @param string $suffix Optional suffix before .php
     * @return string
     */
    protected function getFilePath(string $directory, string $name, string $suffix = ''): string
    {
        $dir = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . ltrim($this->directoryFor($directory), DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $name . $suffix . '.php';
    }

    /**
     * The namespace the project's own classes live in.
     *
     * This was the string "SfphpProject\app", hardcoded — the namespace of the
     * example application inside this repository. Every generator therefore
     * wrote a class into the framework's namespace, which is unusable in any
     * project that installed the framework rather than cloning it: the class
     * could not be autoloaded, and a generated controller extended a base class
     * the package does not even ship.
     *
     * It comes from the project's own composer.json now: the PSR-4 prefix that
     * maps to app/, or the first one there is.
     *
     * @return string The prefix, with a trailing separator
     */
    protected function applicationNamespace(): string
    {
        if ($this->applicationNamespace !== null) {
            return $this->applicationNamespace;
        }

        $composer = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        $decoded = is_file($composer)
            ? json_decode((string) file_get_contents($composer), true)
            : null;

        $maps = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) ($decoded[$section]['psr-4'] ?? []) as $prefix => $path) {
                $maps[$prefix] = is_array($path) ? ($path[0] ?? '') : $path;
            }
        }

        foreach ($maps as $prefix => $path) {
            if (rtrim((string) $path, '/\\') === 'app') {
                return $this->applicationNamespace = rtrim($prefix, '\\') . '\\';
            }
        }

        // Nothing maps to app/. "App\" is what `sfphp init` writes and what
        // the advice it prints tells you to add.
        return $this->applicationNamespace = 'App\\';
    }

    /**
     * Where a file goes, spelled the way the namespace needs.
     *
     * @param string $directory The directory this generator was written against
     * @return string The directory to use
     */
    protected function directoryFor(string $directory): string
    {
        $parts = explode('/', trim(str_replace('\\', '/', $directory), '/'));

        /*
         * Only app/ follows the project's own PSR-4 prefix, so only app/ has a
         * spelling to match. database/seeders and database/factories are read
         * back by `db:seed` and by the factory loader at paths this framework
         * decides, and capitalising them wrote a seeder into database/Seeders
         * where nothing ever looked for it.
         */
        if (($parts[0] ?? '') !== 'app' || $this->applicationNamespace() === self::EXAMPLE_NAMESPACE) {
            return $directory;
        }

        return implode('/', array_map(
            static fn (string $part, int $index): string => $index === 0 ? $part : ucfirst($part),
            $parts,
            array_keys($parts)
        ));
    }

    /**
     * Get the namespace for a directory path.
     *
     * @param string $directory The directory (relative to project root)
     * @return string
     */
    protected function getNamespace(string $directory): string
    {
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($directory, DIRECTORY_SEPARATOR));
        $parts = explode(DIRECTORY_SEPARATOR, $dir);

        if ($parts[0] === 'app') {
            array_shift($parts);
        }

        /*
         * The segment is used exactly as the directory is spelled. It used to
         * be ucfirst()'d, which produced "SfphpProject\app\Models" for a file
         * written to app/models: under PSR-4 on a case-sensitive filesystem
         * the autoloader then looked for app/Models/ and found nothing, so
         * every generator produced a class that could not be loaded. The
         * controller case was worse still, because the router looks for
         * "SfphpProject\app\controllers\" in lower case and would never have
         * matched a generated controller.
         */
        $prefix = $this->applicationNamespace();

        if ($prefix !== self::EXAMPLE_NAMESPACE) {
            /*
             * Somebody else's project. Their prefix maps to app/ under PSR-4,
             * so the segment has to be spelled the way PSR-4 expects — App\
             * plus Controllers, matching app/Controllers.
             */
            $namespace = rtrim($prefix, '\\');

            foreach ($parts as $part) {
                if ($part !== '') {
                    $namespace .= '\\' . ucfirst($part);
                }
            }

            return $namespace;
        }

        $namespace = rtrim($prefix, '\\');
        foreach ($parts as $part) {
            if ($part !== '') {
                $namespace .= '\\' . $part;
            }
        }

        return $namespace;
    }

    /**
     * Write a file and return its path.
     *
     * @param string $filePath The file path
     * @param string $content The file content
     * @return string
     */
    protected function writeFile(string $filePath, string $content): string
    {
        /*
         * A generator never replaces a file silently. Running make:model a
         * second time used to overwrite the model — and make:scaffold four
         * files at once — with the edits made since lost without a word.
         */
        if (is_file($filePath) && !$this->overwrite) {
            throw new GeneratorFileExists(sprintf(
                '%s already exists. Nothing was written; pass --force to replace it.',
                $filePath
            ));
        }

        if (@file_put_contents($filePath, rtrim($content, "\n") . "\n") === false) {
            throw new \RuntimeException('Could not write ' . $filePath . '.');
        }

        return $filePath;
    }

    /**
     * Generate a file and return its relative path.
     *
     * @param string $name The class name
     * @return string The relative path to the generated file
     */
    abstract public function generate(string $name): string;
}
