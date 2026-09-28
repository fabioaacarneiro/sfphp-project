<?php

namespace SfphpProject\src\View;

use RuntimeException;
use SfphpProject\src\Bootstrap;

/**
 * What @sfcss, @sfjs and @script write into a page.
 *
 *     <head>  @sfcss  </head>
 *     <body>  …  @script('home')  …  @sfjs  </body>
 *
 * @script can be written by any component, however deep, because a page is
 * rendered from the top down: every component inside the body has run by the
 * time @sfjs, at the end of it, is reached. @sfjs then writes SFJS, the
 * project's plugins and every script that was declared — each once, in the
 * order they were first asked for, so a chart used five times loads its
 * script once.
 *
 * The list is per request. Router::dispatch() clears it, which is what keeps
 * one visitor's scripts from reaching the next page in a persistent worker.
 */
final class PageScripts
{
    /** The builds a directive can ask for. */
    private const VARIANTS = ['min', 'normal'];

    /** Where the project's scripts are written from, and where they are built to. */
    public const PLUGINS_SOURCE = 'app/resources/js/plugins';
    public const SCRIPTS_SOURCE = 'app/resources/js/scripts';

    /** @var array<string, true> The scripts declared so far, by name */
    private static array $scripts = [];

    /** Whether @sfjs has already written them. */
    private static bool $sent = false;

    /** The project root to look in, when it is not the application's. */
    private static ?string $root = null;

    /**
     * Look for the built files and the sources somewhere else.
     *
     * @param string|null $root A project root, or null to go back to the application's
     * @return void
     */
    public static function usePath(?string $root): void
    {
        self::$root = $root === null ? null : rtrim($root, '/');
    }

    /**
     * Declare that the page needs one of the project's scripts.
     *
     * @param string $name The script, as app/resources/js/scripts/<name>.js names it: "home", "admin/users"
     * @return void
     * @throws RuntimeException If the name is not one, or @sfjs has already run
     */
    public static function script(string $name): void
    {
        $name = self::name($name);

        if (self::$sent) {
            throw new RuntimeException(
                "@script('{$name}') came after @sfjs, which has already written the page's scripts. "
                . 'Declare it before @sfjs, or move @sfjs to the end of the <body>.'
            );
        }

        self::$scripts[$name] = true;
    }

    /**
     * The <link> for SFCSS.
     *
     * @param mixed $variant "min" (the default) or "normal"
     * @return string The tag
     */
    public static function sfcss(mixed $variant = null): string
    {
        $warning = null;
        $suffix = self::variant('sfcss', $variant, $warning) === 'min' ? '.min' : '';

        return '<link rel="stylesheet" href="' . self::escape(asset('css/sfcss' . $suffix . '.css')) . '"'
            . self::warning($warning) . '>';
    }

    /**
     * The <script> tags: SFJS, then the project's plugins, then the scripts
     * the page declared.
     *
     * @param mixed $variant "min" (the default) or "normal"
     * @return string The tags, one per line
     */
    public static function sfjs(mixed $variant = null): string
    {
        $warning = null;
        $suffix = self::variant('sfjs', $variant, $warning) === 'min' ? '.min' : '';

        $tags = [self::tag('js/sfjs' . $suffix . '.js', $warning)];

        if (is_file(self::public('js/plugins' . $suffix . '.js'))) {
            $tags[] = self::tag('js/plugins' . $suffix . '.js', self::stale());
        } elseif (($missing = self::unbuilt()) !== null) {
            // No bundle, but plugins to put in one: SFJS carries the warning, since there is no plugins tag.
            $tags[0] = self::tag('js/sfjs' . $suffix . '.js', trim(($warning ?? '') . ' ' . $missing));
        }

        foreach (array_keys(self::$scripts) as $name) {
            $file = 'js/scripts/' . $name . $suffix . '.js';
            $problem = null;

            if (!is_file(self::public($file))) {
                $problem = "@script('{$name}') has no public/assets/{$file}. Write it at "
                    . self::SCRIPTS_SOURCE . "/{$name}.js and run ./sfphp js:build.";
                error_log('SFPHP: ' . $problem);
            }

            $tags[] = self::tag($file, $problem);
        }

        self::$scripts = [];
        self::$sent = true;

        return implode("\n", $tags);
    }

    /**
     * Forget the scripts of the previous request.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$scripts = [];
        self::$sent = false;
    }

    /**
     * A script's name, checked the way asset() checks a path.
     *
     * "home.js" is taken as "home": the extension is the directive's to add,
     * and writing it anyway is a slip, not an error worth a broken page.
     *
     * @param string $name The name as written
     * @return string The name
     * @throws RuntimeException If it is not a name
     */
    private static function name(string $name): string
    {
        $name = preg_replace('/\.js$/', '', trim($name)) ?? '';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\/[A-Za-z0-9][A-Za-z0-9_-]*)*$/', $name) !== 1) {
            throw new RuntimeException(
                "@script('{$name}') is not a script name. Use letters, digits, - and _, with / between folders: @script('admin/users')."
            );
        }

        return $name;
    }

    /**
     * Which build a directive asked for.
     *
     * A value that is not one of the two is not worth a broken page: the
     * minified file is used, and the page says so — in the console, through
     * the data-sf-warning SFJS reads, since an inline script would be refused
     * under a strict Content-Security-Policy, and in the PHP log, for a page
     * that has no SFJS to read it.
     *
     * @param string $directive sfcss or sfjs
     * @param mixed $variant What was asked for
     * @param string|null $warning Set to what went wrong, if anything
     * @return string min or normal
     */
    private static function variant(string $directive, mixed $variant, ?string &$warning): string
    {
        if ($variant === null || $variant === '') {
            return 'min';
        }

        if (is_string($variant) && in_array($variant, self::VARIANTS, true)) {
            return $variant;
        }

        $written = is_string($variant) ? "'" . $variant . "'" : get_debug_type($variant);
        $warning = "@{$directive}({$written}) expects 'min' or 'normal'; the minified file was used.";
        error_log('SFPHP: ' . $warning);

        return 'min';
    }

    /**
     * In development, whether the plugins changed after the bundle was built.
     *
     * A plugin edited and not rebuilt is the one that "does nothing", so the
     * page says it. Only in development: in production the sources are not
     * looked at, and a page does not pay for a directory listing.
     *
     * @return string|null The warning, or null
     */
    private static function stale(): ?string
    {
        if (!self::development()) {
            return null;
        }

        $bundle = self::public('js/plugins.js');
        $built = is_file($bundle) ? (int) filemtime($bundle) : 0;

        foreach (self::sources(self::PLUGINS_SOURCE) as $source) {
            if ((int) filemtime($source) > $built) {
                return 'The plugins changed since the last ./sfphp js:build; the page still has the old ones.';
            }
        }

        return null;
    }

    /**
     * Whether there are plugins that were never built.
     *
     * @return string|null The warning, or null
     */
    private static function unbuilt(): ?string
    {
        if (!self::development() || self::sources(self::PLUGINS_SOURCE) === []) {
            return null;
        }

        $warning = 'There are plugins in ' . self::PLUGINS_SOURCE . ' but no bundle; run ./sfphp js:build.';
        error_log('SFPHP: ' . $warning);

        return $warning;
    }

    /**
     * The .js files under one of the project's source directories.
     *
     * @param string $directory Relative to the project
     * @return list<string> Absolute paths
     */
    private static function sources(string $directory): array
    {
        $root = self::base($directory);

        return is_dir($root) ? (glob($root . '/*.js') ?: []) : [];
    }

    private static function development(): bool
    {
        return defined('APP_ENV') && APP_ENV === 'development';
    }

    private static function public(string $asset): string
    {
        return self::base('public/assets/' . $asset);
    }

    private static function base(string $relative): string
    {
        return self::$root === null ? Bootstrap::basePath($relative) : self::$root . '/' . $relative;
    }

    private static function tag(string $asset, ?string $warning = null): string
    {
        return '<script src="' . self::escape(asset($asset)) . '"' . self::warning($warning) . '></script>';
    }

    private static function warning(?string $warning): string
    {
        return $warning === null || $warning === '' ? '' : ' data-sf-warning="' . self::escape($warning) . '"';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
