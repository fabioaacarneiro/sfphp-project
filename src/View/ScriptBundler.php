<?php

namespace SfphpProject\src\View;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SfphpProject\src\JsMinifier;

/**
 * Builds a project's own JavaScript into what @sfjs loads.
 *
 *     app/resources/js/plugins/*.js     →  public/assets/js/plugins.js, plugins.min.js
 *     app/resources/js/scripts/**.js    →  public/assets/js/scripts/<name>.js, <name>.min.js
 *
 * Plugins go on every page, so they are one file and one request. Each is
 * wrapped in a scope of its own: two plugins that both declare `const format`
 * would otherwise make the bundle a syntax error, and a plugin that throws
 * while loading would stop the ones after it. Scripts are for the pages that
 * ask for them with @script, so each stays a file of its own.
 *
 * Every file is checked with node before it is bundled, so a mistake is
 * reported with the name of the file that has it rather than as a bundle that
 * does not parse. What the minifier writes is checked too: it does not
 * understand regular expressions, and a regex holding "//" or a quote would
 * come out broken. When that happens, or when there is no node to ask, the
 * .min.js is the source unchanged — larger, never broken.
 */
final class ScriptBundler
{
    /**
     * @param string $root The project's root
     */
    public function __construct(private string $root)
    {
    }

    /**
     * Build everything.
     *
     * @return list<string> What was done, a line each
     * @throws RuntimeException If a source file is not valid JavaScript
     */
    public function build(): array
    {
        return array_merge($this->plugins(), $this->scripts());
    }

    /**
     * Build the plugins bundle.
     *
     * @return list<string>
     */
    private function plugins(): array
    {
        $output = $this->path('public/assets/js/plugins');
        $sources = glob($this->path(PageScripts::PLUGINS_SOURCE) . '/*.js') ?: [];
        sort($sources);

        if ($sources === []) {
            // No plugins any more: a bundle left behind would still be loaded by @sfjs.
            $removed = false;

            foreach (['.js', '.min.js'] as $suffix) {
                if (is_file($output . $suffix)) {
                    unlink($output . $suffix);
                    $removed = true;
                }
            }

            return $removed ? ['Removed public/assets/js/plugins.js: there are no plugins in ' . PageScripts::PLUGINS_SOURCE] : [];
        }

        $bundle = '';

        foreach ($sources as $source) {
            $name = 'plugins/' . basename($source);

            $this->ensureValid($source, $name);

            $bundle .= "// {$name}\n(() => {\ntry {\n"
                . rtrim((string) file_get_contents($source))
                . "\n} catch (error) {\n  console.error('SFJS: {$name} failed to load —', error);\n}\n})();\n\n";
        }

        $lines = [sprintf('✓ public/assets/js/plugins.js: %d plugin%s', count($sources), count($sources) === 1 ? '' : 's')];

        return array_merge($lines, $this->write($output, rtrim($bundle) . "\n", 'plugins'));
    }

    /**
     * Build each page script, and remove what was built from a script that is gone.
     *
     * @return list<string>
     */
    private function scripts(): array
    {
        $sourceRoot = $this->path(PageScripts::SCRIPTS_SOURCE);
        $outputRoot = $this->path('public/assets/js/scripts');
        $lines = [];
        $wanted = [];

        foreach ($this->files($sourceRoot) as $relative) {
            $name = substr($relative, 0, -3);
            $source = $sourceRoot . '/' . $relative;

            if (str_ends_with($name, '.min')) {
                throw new RuntimeException("scripts/{$relative}: a name ending in .min.js is where the minified copy of another script goes. Rename it.");
            }

            $this->ensureValid($source, 'scripts/' . $relative);

            $wanted[$name . '.js'] = true;
            $wanted[$name . '.min.js'] = true;

            $lines[] = '✓ public/assets/js/scripts/' . $relative;
            $lines = array_merge($lines, $this->write($outputRoot . '/' . $name, (string) file_get_contents($source), 'scripts/' . $relative));
        }

        foreach ($this->files($outputRoot) as $relative) {
            if (!isset($wanted[$relative])) {
                unlink($outputRoot . '/' . $relative);
                $lines[] = 'Removed public/assets/js/scripts/' . $relative . ': its source is gone';
            }
        }

        // And the folders that emptied.
        if (is_dir($outputRoot)) {
            $folders = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($outputRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($folders as $folder) {
                if ($folder->isDir() && (scandir($folder->getPathname()) ?: []) === ['.', '..']) {
                    rmdir($folder->getPathname());
                }
            }

            if ((scandir($outputRoot) ?: []) === ['.', '..']) {
                rmdir($outputRoot);
            }
        }

        return $lines;
    }

    /**
     * Write a script and its minified copy.
     *
     * @param string $base Where, without the extension
     * @param string $source The script
     * @param string $name How to name it in a message
     * @return list<string> Anything worth saying
     */
    private function write(string $base, string $source, string $name): array
    {
        $directory = dirname($base);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        file_put_contents($base . '.js', $source);
        file_put_contents($base . '.min.js', JsMinifier::minify($source));

        $check = JsMinifier::check($base . '.min.js');

        if ($check === null) {
            return [];
        }

        file_put_contents($base . '.min.js', $source);

        return $check === false
            ? ["  {$name}: node is not installed, so the minified copy could not be checked and is the source unchanged"]
            : ["  {$name}: the minifier broke it — most likely a regular expression with // or a quote in it — so the minified copy is the source unchanged"];
    }

    /**
     * Refuse a source that is not valid JavaScript, naming it.
     *
     * @param string $file The file
     * @param string $name How to name it
     * @return void
     * @throws RuntimeException If it is not valid
     */
    private function ensureValid(string $file, string $name): void
    {
        $check = JsMinifier::check($file);

        if (is_string($check)) {
            throw new RuntimeException("{$name} is not valid JavaScript:\n{$check}");
        }
    }

    /**
     * The .js files under a directory, relative to it, in a stable order.
     *
     * @param string $directory The directory
     * @return list<string>
     */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            }
        }

        sort($found);

        return $found;
    }

    private function path(string $relative): string
    {
        return rtrim($this->root, '/') . '/' . $relative;
    }
}
