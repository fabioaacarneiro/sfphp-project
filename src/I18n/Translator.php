<?php

namespace SfphpProject\src\I18n;

use InvalidArgumentException;

/**
 * Looks messages up by key, in the active locale.
 *
 * Catalogs are plain PHP files returning an array, grouped by file:
 * lang/pt_BR/validation.php holds the keys reached as "validation.required".
 * Nothing is parsed or compiled, so a catalog costs a require and lands in
 * OPcache like any other file.
 *
 * The framework ships its own catalogs under src/I18n/lang. An application
 * adds its own directory with addPath(), and paths added later win, so an
 * application can override a framework message without editing the framework.
 *
 * Only text that reaches an end user goes through here. Exception messages
 * aimed at whoever is writing the code — the CLI, the query builder, the
 * schema builder — stay in English on purpose: they are read in a stack trace
 * or a log, by a developer, and translating them would make searching for one
 * harder rather than easier.
 */
final class Translator
{
    /**
     * The locale messages are read in.
     */
    private static string $locale = 'en';

    /**
     * The locale consulted when the active one has no entry.
     */
    private static string $fallback = 'en';

    /**
     * Directories searched for catalogs, last added searched first.
     *
     * @var array<int, string>
     */
    private static array $paths = [];

    /**
     * Loaded catalogs, as locale => group => messages.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $loaded = [];

    /**
     * Plural selectors registered per locale.
     *
     * @var array<string, callable(int): int>
     */
    private static array $pluralizers = [];

    /**
     * Set the locale messages are read in.
     *
     * @param string $locale The locale, such as "pt_BR"
     * @return void
     * @throws InvalidArgumentException If the locale is not a valid tag
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = self::normalize($locale);
    }

    /**
     * Get the active locale.
     *
     * @return string The locale
     */
    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Set the locale consulted when the active one has no entry.
     *
     * @param string $locale The fallback locale
     * @return void
     */
    public static function setFallback(string $locale): void
    {
        self::$fallback = self::normalize($locale);
    }

    /**
     * Get the fallback locale.
     *
     * @return string The fallback locale
     */
    public static function fallback(): string
    {
        return self::$fallback;
    }

    /**
     * Add a directory to search for catalogs.
     *
     * The most recently added path is searched first, so an application can
     * override a message the framework ships without touching it.
     *
     * @param string $path The directory holding locale subdirectories
     * @return void
     */
    public static function addPath(string $path): void
    {
        $path = rtrim($path, '/');

        if (!in_array($path, self::$paths, true)) {
            array_unshift(self::$paths, $path);
            self::$loaded = [];
        }
    }

    /**
     * Get the directories searched for catalogs.
     *
     * @return array<int, string> The paths, in search order
     */
    public static function paths(): array
    {
        return self::$paths;
    }

    /**
     * Register how a locale chooses between plural forms.
     *
     * Ranges cover most languages, but not all: Polish picks its form from the
     * last digits rather than from a range, so 22 and 12 take different forms
     * although both are "more than five". Rather than ship an incomplete copy
     * of the CLDR rules and be quietly wrong, the selector is a hook:
     *
     *     Translator::pluralizer('pl', function (int $count): int {
     *         if ($count === 1) { return 0; }
     *         $mod10 = $count % 10;
     *         $mod100 = $count % 100;
     *         return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 1 : 2;
     *     });
     *
     * @param string $locale The locale the selector applies to
     * @param callable(int): int $selector Returns the index of the form to use
     * @return void
     */
    public static function pluralizer(string $locale, callable $selector): void
    {
        self::$pluralizers[self::normalize($locale)] = $selector;
    }

    /**
     * Translate a key.
     *
     * The key is "group.entry", optionally nested further. A key with no
     * entry anywhere is returned unchanged, so a missing translation shows
     * where it is missing instead of rendering an empty page.
     *
     * @param string $key The message key
     * @param array<string, string|int|float> $replace Placeholder values
     * @param string|null $locale The locale to read, or null for the active one
     * @return string The translated message
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale === null ? self::$locale : self::normalize($locale);

        $message = self::lookup($key, $locale);

        /*
         * The base language comes before the fallback: es_MX reads es before
         * it reads en. The fallback used to be tried first, so a Mexican
         * visitor got English although a Spanish catalog was right there.
         */
        if ($message === null && str_contains($locale, '_')) {
            $message = self::lookup($key, strstr($locale, '_', true));
        }

        if ($message === null && $locale !== self::$fallback) {
            $message = self::lookup($key, self::$fallback);
        }

        return self::interpolate($message ?? $key, $replace);
    }

    /**
     * Translate a key, choosing the form that matches a count.
     *
     * Forms are separated by "|". A form may be prefixed with an explicit
     * condition — "{0}" for an exact number, "[2,4]" for a range, "[5,*]" for
     * an open one — and those are matched first:
     *
     *     'itens' => '{0} nenhum item|{1} um item|[2,*] :count itens'
     *
     * Without a condition the locale's plural selector picks among the plain
     * forms, defaulting to the English rule: the first form for one, the
     * second for anything else.
     *
     * @param string $key The message key
     * @param int $count The number deciding the form
     * @param array<string, string|int|float> $replace Placeholder values
     * @param string|null $locale The locale to read, or null for the active one
     * @return string The translated message
     */
    public static function choice(
        string $key,
        int $count,
        array $replace = [],
        ?string $locale = null
    ): string {
        $locale = $locale === null ? self::$locale : self::normalize($locale);
        $message = self::get($key, [], $locale);
        $replace += ['count' => $count];

        $forms = explode('|', $message);
        $explicit = [];
        $plain = [];

        foreach ($forms as $form) {
            if (preg_match('/^\s*(\{\s*(-?\d+)\s*\}|\[\s*(-?\d+)\s*,\s*(\*|-?\d+)\s*\])\s*(.*)$/s', $form, $matches) === 1) {
                $explicit[] = [
                    'exact' => $matches[2] === '' ? null : (int) $matches[2],
                    'from' => $matches[3] === '' ? null : (int) $matches[3],
                    'to' => $matches[4] === '' ? null : ($matches[4] === '*' ? null : (int) $matches[4]),
                    'open' => $matches[4] === '*',
                    'text' => $matches[5],
                ];

                continue;
            }

            $plain[] = trim($form);
        }

        foreach ($explicit as $form) {
            if ($form['exact'] !== null && $form['exact'] === $count) {
                return self::interpolate($form['text'], $replace);
            }

            if ($form['from'] !== null
                && $count >= $form['from']
                && ($form['open'] || ($form['to'] !== null && $count <= $form['to']))
            ) {
                return self::interpolate($form['text'], $replace);
            }
        }

        /*
         * Only explicit forms, and none of them covers this count — zero or a
         * negative number against "{1}…|[2,*]…". The last form is the general
         * one; returning the whole string, bars and brackets included, showed
         * the raw catalog entry to the visitor.
         */
        if ($plain === []) {
            return $explicit === [] ? self::interpolate($message, $replace) : self::interpolate(end($explicit)['text'], $replace);
        }

        $index = self::selectPlural($locale, $count);

        return self::interpolate($plain[$index] ?? $plain[count($plain) - 1], $replace);
    }

    /**
     * Check whether a key has a translation.
     *
     * @param string $key The message key
     * @param string|null $locale The locale to check, or null for the active one
     * @return bool True when the key resolves
     */
    public static function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale === null ? self::$locale : self::normalize($locale);

        return self::lookup($key, $locale) !== null;
    }

    /**
     * Forget every loaded catalog and registered path.
     *
     * @internal Exposed for tests and for worker reloads in a persistent runtime.
     * @return void
     */
    public static function reset(): void
    {
        self::$locale = 'en';
        self::$fallback = 'en';
        self::$paths = [];
        self::$loaded = [];
        self::$pluralizers = [];
    }

    /**
     * Read a key from a locale's catalogs.
     *
     * @param string $key The message key
     * @param string $locale The locale to read
     * @return string|null The message, or null when absent
     */
    private static function lookup(string $key, string $locale): ?string
    {
        $segments = explode('.', $key);
        $group = array_shift($segments);

        if ($segments === []) {
            return null;
        }

        $messages = self::load($locale, $group);

        foreach ($segments as $segment) {
            if (!is_array($messages) || !array_key_exists($segment, $messages)) {
                return null;
            }

            $messages = $messages[$segment];
        }

        return is_string($messages) ? $messages : null;
    }

    /**
     * Load a catalog group, merging every path that provides it.
     *
     * @param string $locale The locale to load
     * @param string $group The catalog file name, without extension
     * @return array<string, mixed> The messages
     */
    private static function load(string $locale, string $group): array
    {
        if (isset(self::$loaded[$locale][$group])) {
            return self::$loaded[$locale][$group];
        }

        $messages = [];

        /*
         * The framework's own catalogs always sit at the end of the search, so
         * they are merged first and therefore have the lowest priority: an
         * application overrides a message by declaring the same key, without
         * having to copy the rest of the file.
         *
         * The list is then walked backwards, so the most recently added path
         * is merged last and wins.
         */
        $search = array_merge(self::$paths, [__DIR__ . '/lang']);

        foreach (array_reverse($search) as $path) {
            $file = $path . '/' . $locale . '/' . $group . '.php';

            if (!is_file($file)) {
                continue;
            }

            $loaded = require $file;

            if (is_array($loaded)) {
                $messages = array_replace_recursive($messages, $loaded);
            }
        }

        return self::$loaded[$locale][$group] = $messages;
    }

    /**
     * Replace ":placeholder" markers with their values.
     *
     * @param string $message The message
     * @param array<string, string|int|float> $replace The values
     * @return string The interpolated message
     */
    private static function interpolate(string $message, array $replace): string
    {
        if ($replace === []) {
            return $message;
        }

        $markers = [];
        foreach ($replace as $key => $value) {
            $markers[':' . $key] = (string) $value;
        }

        /*
         * Sorted longest first so that ":count" is replaced before ":co" could
         * consume part of it.
         */
        uksort($markers, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return strtr($message, $markers);
    }

    /**
     * Choose which plural form a count takes.
     *
     * @param string $locale The locale deciding
     * @param int $count The number
     * @return int The index of the form
     */
    private static function selectPlural(string $locale, int $count): int
    {
        $selector = self::$pluralizers[$locale]
            ?? self::$pluralizers[strstr($locale, '_', true) ?: $locale]
            ?? null;

        if ($selector !== null) {
            return max(0, $selector($count));
        }

        return $count === 1 ? 0 : 1;
    }

    /**
     * Normalize a locale tag to the "pt_BR" shape.
     *
     * @param string $locale The tag, in any common shape
     * @return string The normalized tag
     * @throws InvalidArgumentException If the tag is not a locale
     */
    private static function normalize(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));

        if (preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z]{2}|[a-zA-Z]{4}|\d{3}))?$/', $locale, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid locale \"$locale\".");
        }

        return isset($matches[2])
            ? strtolower($matches[1]) . '_' . strtoupper($matches[2])
            : strtolower($matches[1]);
    }
}
