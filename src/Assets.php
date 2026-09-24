<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * The stylesheet and the script the framework ships.
 *
 * SFCSS and SFJS are not the example application's files. They are tools that
 * travel with the framework, so they live in the package rather than in a
 * public directory a consumer never receives, and `./sfphp assets:publish`
 * copies them into whatever a project serves.
 *
 * Two ways to reach them, for two different situations:
 *
 *     asset('css/sfcss.css')   // a normal page: a URL the browser fetches
 *     Assets::css()            // a framework screen: the bytes, inlined
 *
 * The second exists because the pages the framework renders itself — the error
 * page, the dump screen — have to work when the thing that is broken is
 * everything else. A page that fetches a stylesheet renders unstyled exactly
 * when you need to read it.
 */
final class Assets
{
    /** Where the published copies are expected to live inside a project. */
    public const PUBLIC_PATH = 'public/assets';

    private static ?string $root = null;

    /**
     * The directory the shipped assets are read from.
     *
     * @return string An absolute path
     */
    public static function path(): string
    {
        return self::$root ??= dirname(__DIR__) . '/resources/assets';
    }

    /**
     * Read the assets from somewhere else.
     *
     * @param string|null $path The directory, or null to go back to the package's
     * @return void
     */
    public static function usePath(?string $path): void
    {
        self::$root = $path === null ? null : rtrim($path, '/');
    }

    /**
     * SFCSS, as bytes.
     *
     * The minified build, because this is inlined into a page rather than
     * cached by a browser: a reader of a dump screen pays for it every time.
     *
     * @param bool $minified Whether to read the minified build
     * @return string The stylesheet
     */
    public static function css(bool $minified = true): string
    {
        return self::read('css/' . ($minified ? 'sfcss.min.css' : 'sfcss.css'));
    }

    /**
     * SFJS, as bytes.
     *
     * @param bool $minified Whether to read the minified build
     * @return string The script
     */
    public static function js(bool $minified = true): string
    {
        return self::read('js/' . ($minified ? 'sfjs.min.js' : 'sfjs.js'));
    }

    /**
     * Copy the shipped assets into a project.
     *
     * @param string $target The directory to write into
     * @param bool $force Overwrite files that are already there
     * @return array{written: list<string>, kept: list<string>} What happened
     * @throws RuntimeException When the target cannot be created or written
     */
    public static function publish(string $target, bool $force = false): array
    {
        $target = rtrim($target, '/');
        $written = [];
        $kept = [];

        foreach (self::files() as $relative) {
            $source = self::path() . '/' . $relative;
            $destination = $target . '/' . $relative;

            if (!$force && is_file($destination)) {
                if (md5_file($destination) === md5_file($source)) {
                    // Already the same file. Saying nothing beats reporting
                    // work that did not happen.
                    continue;
                }

                /*
                 * Different, so somebody changed it — `css:build` against their
                 * own config writes here. Overwriting would throw their palette
                 * away on the next composer install, silently, which is the
                 * worst way to lose work. It is reported instead.
                 */
                $kept[] = $relative;

                continue;
            }

            $directory = dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create ' . $directory . '.');
            }

            if (!copy($source, $destination)) {
                throw new RuntimeException('Could not write ' . $destination . '.');
            }

            $written[] = $relative;
        }

        return ['written' => $written, 'kept' => $kept];
    }

    /**
     * Point a project's public directory at the package's copy instead.
     *
     * Publishing copies, which means the same bytes exist twice on disk: once
     * in the package, where they are version-controlled and where a composer
     * update replaces them, and once under a document root, where a browser can
     * reach them. That is the arrangement every distributable package ends up
     * with, because a browser cannot read out of vendor/ and a package cannot
     * write into somebody's public/ at install time.
     *
     * On a system with symbolic links the copy can be a link instead, and then
     * there is one file. It is not the default because a link is a deployment
     * decision: it breaks when the deploy copies files rather than moving them,
     * it needs care on Windows, and an upgrade silently changes what a running
     * site is serving instead of waiting for a publish.
     *
     * @param string $target The directory to link into
     * @param bool $force Replace what is already there
     * @return list<string> The links made, relative to the target
     * @throws RuntimeException When a link cannot be made
     */
    public static function link(string $target, bool $force = false): array
    {
        $target = rtrim($target, '/');
        $made = [];

        foreach (['css', 'js'] as $directory) {
            $destination = $target . '/' . $directory;
            $source = self::path() . '/' . $directory;

            if (is_link($destination)) {
                if (readlink($destination) === $source) {
                    continue;
                }

                unlink($destination);
            } elseif (file_exists($destination)) {
                if (!$force) {
                    throw new RuntimeException(
                        $destination . ' is a real directory. Use --force to replace it with a link.'
                    );
                }

                foreach (glob($destination . '/*') ?: [] as $file) {
                    unlink($file);
                }

                rmdir($destination);
            }

            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create ' . $target . '.');
            }

            if (!symlink($source, $destination)) {
                throw new RuntimeException('Could not link ' . $destination . ' to ' . $source . '.');
            }

            $made[] = $directory;
        }

        return $made;
    }

    /**
     * Every file publishing copies.
     *
     * @return list<string> Paths relative to the asset directory
     */
    public static function files(): array
    {
        return [
            'css/sfcss.css',
            'css/sfcss.min.css',
            'js/sfjs.js',
            'js/sfjs.min.js',
            'js/sfjs-stream.js',
            'js/sfjs-stream.min.js',
        ];
    }

    /**
     * Read one shipped file.
     *
     * @param string $relative The path inside the asset directory
     * @return string The contents
     * @throws RuntimeException When the file is not there
     */
    private static function read(string $relative): string
    {
        $path = self::path() . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException(
                'The asset ' . $relative . ' is missing from ' . self::path() . '. '
                . 'Rebuild it with tools/css-builder/sfcss-builder.php, or point Assets::usePath() at your copy.'
            );
        }

        return $contents;
    }
}
