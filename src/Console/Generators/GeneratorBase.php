<?php

namespace SfphpProject\src\Console\Generators;

use InvalidArgumentException;

/**
 * Base class for code generators.
 */
abstract class GeneratorBase
{
    protected string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Validate and normalize a class name.
     *
     * @param string $name The raw name
     * @return string
     */
    protected function validateName(string $name): string
    {
        if (trim($name) === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid class name: $name");
        }

        return $name;
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
        $dir = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $name . $suffix . '.php';
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
        $namespace = 'SfphpProject\\app';
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
        file_put_contents($filePath, $content);
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
