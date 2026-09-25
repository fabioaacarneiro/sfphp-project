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
     * The part of SFCSS a page needs, given the classes it uses.
     *
     * The framework's own screens — the error pages above all — inline their
     * stylesheet, and inlining all of SFCSS made every 404 weigh 195 KB: every
     * missing favicon, every scanner probing for wp-admin. This keeps the
     * design tokens, the dark theme, the element rules and the rules for the
     * listed classes, and drops the rest.
     *
     * @param list<string> $classes The classes the page uses, without the dot
     * @return string The stylesheet
     */
    public static function cssFor(array $classes): string
    {
        static $memo = [];

        $key = implode(' ', $classes);

        if (!isset($memo[$key])) {
            $memo[$key] = self::subset(self::css(), array_fill_keys($classes, true));
        }

        return $memo[$key];
    }

    /**
     * Keep the rules of a stylesheet that apply to a set of classes.
     *
     * @param string $css Minified CSS
     * @param array<string, true> $allowed The classes to keep
     * @return string The kept rules
     */
    private static function subset(string $css, array $allowed): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $open = strpos($css, '{', $i);

            if ($open === false) {
                break;
            }

            $prelude = trim(substr($css, $i, $open - $i));
            $close = self::matchingBrace($css, $open);
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;

            if (str_starts_with($prelude, '@media') || str_starts_with($prelude, '@supports')) {
                $inner = self::subset($body, $allowed);

                if ($inner !== '') {
                    $out .= $prelude . '{' . $inner . '}';
                }

                continue;
            }

            if ($prelude === '' || $prelude[0] === '@') {
                continue;
            }

            $kept = [];

            foreach (explode(',', $prelude) as $selector) {
                preg_match_all('/\.((?:\\.|[A-Za-z0-9_-])+)/', $selector, $matches);
                $wanted = true;

                foreach ($matches[1] as $class) {
                    if (!isset($allowed[stripslashes($class)])) {
                        $wanted = false;

                        break;
                    }
                }

                if ($wanted) {
                    $kept[] = $selector;
                }
            }

            if ($kept !== []) {
                $out .= implode(',', $kept) . '{' . $body . '}';
            }
        }

        return $out;
    }

    /**
     * The offset of the brace that closes the one at $open.
     */
    private static function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $length = strlen($css);

        for ($j = $open; $j < $length; $j++) {
            if ($css[$j] === '{') {
                $depth++;
            } elseif ($css[$j] === '}' && --$depth === 0) {
                return $j;
            }
        }

        return $length - 1;
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

        /*
         * What the last publish wrote, by hash. A published file that still
         * matches is the framework's own and is replaced; one that differs was
         * changed by hand and is kept. "Different from the package" used to be
         * read as "yours" — true when css:build wrote into public/, and wrong
         * once it wrote into resources/: a rebuilt stylesheet was then always
         * different, so assets:publish kept the old one and the new colours
         * never reached a browser.
         */
        $manifestPath = $target . '/.sfphp-published.json';
        $manifest = is_file($manifestPath) ? (json_decode((string) file_get_contents($manifestPath), true) ?: []) : [];

        foreach (self::files() as $relative) {
            $source = self::path() . '/' . $relative;
            $destination = $target . '/' . $relative;

            if (!$force && is_file($destination)) {
                $current = md5_file($destination);

                if ($current === md5_file($source)) {
                    // Already the same file. Saying nothing beats reporting
                    // work that did not happen.
                    $manifest[$relative] = $current;

                    continue;
                }

                if (($manifest[$relative] ?? null) !== $current) {
                    $kept[] = $relative;

                    continue;
                }
            }

            $directory = dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create ' . $directory . '.');
            }

            if (!copy($source, $destination)) {
                throw new RuntimeException('Could not write ' . $destination . '.');
            }

            $manifest[$relative] = md5_file($destination);
            $written[] = $relative;
        }

        if (is_dir($target)) {
            @file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
