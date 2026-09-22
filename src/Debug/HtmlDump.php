<?php

namespace SfphpProject\src\Debug;

use SfphpProject\src\Assets;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Config;

/**
 * The screen a dump replaces the response with.
 *
 * Built out of SFCSS, which is the framework's own stylesheet — the same
 * classes an application writes its pages with, so this screen is not a second
 * visual language living inside the framework.
 *
 * The stylesheet is **inlined** rather than linked. A page the framework renders
 * because you asked it to stop and look has to render when the rest of the
 * application is broken, and a `<link>` to an asset route is one more thing that
 * can be the broken part. There is a test asserting the error page issues no
 * external request; this holds itself to the same rule.
 *
 * The only script is a few lines for the collapsing, written inline for the same
 * reason.
 */
final class HtmlDump
{
    /** Whether a fragment has already carried the stylesheet in this response. */
    private static bool $stylesheetSent = false;

    /**
     * Render one or more values as a full page.
     *
     * @param list<mixed> $values The values dumped
     * @param array{file: string, line: int}|null $caller Where the dump was called
     * @return string The page
     */
    public static function render(array $values, ?array $caller = null): string
    {
        $title = count($values) === 1 ? 'Dump' : 'Dump (' . count($values) . ' values)';
        $body = '';

        foreach ($values as $index => $value) {
            $body .= self::card(Dumper::describe($value), $index, count($values));
        }

        return self::page($title, $body, $caller);
    }

    /**
     * Render values to drop into a page that is already being written.
     *
     * dump() appends to whatever the response had printed so far, so it cannot
     * send a second `<!DOCTYPE html>` and a second `<head>` into the middle of
     * a document. It sends the cards, and the stylesheet **once** — repeating
     * ninety kilobytes of CSS for every dump in a loop would be its own
     * problem.
     *
     * @param list<mixed> $values The values dumped
     * @param array{file: string, line: int}|null $caller Where the dump was called
     * @return string The markup
     */
    public static function fragment(array $values, ?array $caller = null): string
    {
        $out = '';

        if (!self::$stylesheetSent) {
            self::$stylesheetSent = true;
            $out .= '<style>' . Assets::css() . '</style>'
                . '<style>' . self::styles() . '</style>';
        }

        $where = $caller === null
            ? ''
            : '<div class="text-xs text-muted mb-2"><code>'
                . self::e(self::shorten($caller['file'])) . ':' . $caller['line'] . '</code></div>';

        $cards = '';

        foreach ($values as $index => $value) {
            $cards .= self::card(Dumper::describe($value), $index, count($values));
        }

        return $out . '<div class="sf-dump-fragment my-4">' . $where . $cards . '</div>';
    }

    /**
     * Forget that the stylesheet was sent.
     *
     * For tests, and for a persistent runtime, where the flag would otherwise
     * carry from one request into the next and the second visitor would get a
     * dump with no styling at all.
     *
     * @return void
     */
    public static function forgetStylesheet(): void
    {
        self::$stylesheetSent = false;
    }

    /**
     * One card per dumped value.
     *
     * @param array<string, mixed> $node The described value
     * @param int $index Its position among the dumped values
     * @param int $total How many were dumped
     * @return string The card
     */
    private static function card(array $node, int $index, int $total): string
    {
        $heading = $total === 1
            ? self::summary($node)
            : '<span class="text-muted">#' . ($index + 1) . '</span> ' . self::summary($node);

        return '<div class="card mb-4">'
            . '<div class="card-header d-flex items-center justify-between gap-3">'
            . '<div class="font-mono text-sm">' . $heading . '</div>'
            . '</div>'
            . '<div class="card-body font-mono text-sm sf-dump-body">'
            . self::node($node)
            . '</div>'
            . '</div>';
    }

    /**
     * A one-line summary of a value, for a card heading.
     *
     * @param array<string, mixed> $node The described value
     * @return string The summary
     */
    private static function summary(array $node): string
    {
        return match ($node['type']) {
            'array' => self::badge('array', 'badge-info') . ' <span class="text-muted">'
                . $node['count'] . ' ' . ($node['count'] === 1 ? 'entry' : 'entries') . '</span>',
            'object', 'enum', 'closure' => self::badge(self::e((string) $node['class']), 'badge-primary'),
            'string' => self::badge('string', 'badge-success') . ' <span class="text-muted">'
                . $node['length'] . ' ' . ($node['length'] === 1 ? 'character' : 'characters') . '</span>',
            default => self::badge(self::e($node['type']), 'badge-secondary'),
        };
    }

    /**
     * Render one node of the tree.
     *
     * @param array<string, mixed> $node The described value
     * @return string The markup
     */
    private static function node(array $node): string
    {
        return match ($node['type']) {
            'array' => self::branch($node, 'array', '[', ']', (string) $node['count']),
            'object' => self::objectNode($node),
            'string' => self::stringNode($node),
            'enum' => self::leaf(
                self::e((string) $node['class']) . '::' . self::e((string) $node['value'])
                    . ($node['backing'] === null ? '' : ' <span class="text-muted">= '
                        . self::e((string) $node['backing']) . '</span>'),
                'text-primary'
            ),
            'closure' => self::leaf(
                'Closure'
                . ($node['file'] === null ? '' : ' <span class="text-muted">'
                    . self::e(self::shorten((string) $node['file'])) . ':' . (int) $node['line'] . '</span>'),
                'text-primary'
            ),
            'null' => self::leaf('null', 'text-muted'),
            'bool' => self::leaf(self::e((string) $node['value']), 'text-info'),
            'int', 'float' => self::leaf(self::e((string) $node['value']), 'text-warning'),
            'uninitialised' => self::leaf('uninitialised', 'text-muted'),
            'unreadable' => self::leaf('unreadable: ' . self::e((string) $node['value']), 'text-danger'),
            'resource' => self::leaf('resource(' . self::e((string) $node['value']) . ')', 'text-muted'),
            default => self::leaf(self::e((string) ($node['value'] ?? $node['type'])), 'text-muted'),
        };
    }

    /**
     * Render an object, which may be circular or too deep to follow.
     *
     * @param array<string, mixed> $node The described object
     * @return string The markup
     */
    private static function objectNode(array $node): string
    {
        $class = self::e((string) $node['class']);

        if ($node['circular'] ?? false) {
            return self::leaf($class . ' <span class="badge badge-warning">already shown above</span>', 'text-primary');
        }

        return self::branch($node, $class, '{', '}', (string) count($node['children']));
    }

    /**
     * Render a string, with its length and any truncation.
     *
     * @param array<string, mixed> $node The described string
     * @return string The markup
     */
    private static function stringNode(array $node): string
    {
        if ($node['binary']) {
            // Not valid UTF-8, so it cannot go into the page as text.
            return self::leaf(
                '<span class="text-muted">binary, ' . (int) $node['length'] . ' bytes</span>',
                'text-muted'
            );
        }

        $body = '<span class="sf-dump-str">"' . self::e((string) $node['value']) . '"</span>';
        $meta = '<span class="text-muted text-xs"> ' . (int) $node['length']
            . ($node['truncated'] ? ' characters, cut at ' . Dumper::MAX_STRING : ' characters') . '</span>';

        return '<div class="sf-dump-line">' . $body . $meta . '</div>';
    }

    /**
     * Render a node that has children, collapsible.
     *
     * @param array<string, mixed> $node The described value
     * @param string $label What it is
     * @param string $open The opening brace
     * @param string $close The closing brace
     * @param string $count How many children
     * @return string The markup
     */
    private static function branch(array $node, string $label, string $open, string $close, string $count): string
    {
        if ($node['deep'] ?? false) {
            return self::leaf(
                $label . ' <span class="badge badge-warning">deeper than ' . Dumper::MAX_DEPTH . ' levels</span>',
                'text-primary'
            );
        }

        if ($node['children'] === []) {
            return self::leaf($label . ' <span class="text-muted">' . $open . $close . '</span>', 'text-primary');
        }

        $rows = '';

        foreach ($node['children'] as $child) {
            $rows .= '<div class="sf-dump-row">'
                . '<span class="sf-dump-key">' . self::key($child) . '</span>'
                . '<span class="sf-dump-arrow text-muted">' . ($child['keyType'] === 'property' ? ':' : '=&gt;') . '</span>'
                . '<div class="sf-dump-value">' . self::node($child['value']) . '</div>'
                . '</div>';
        }

        if ($node['truncated']) {
            $rows .= '<div class="sf-dump-row text-muted">'
                . 'only the first ' . Dumper::MAX_ENTRIES . ' shown</div>';
        }

        /*
         * <details> rather than a click handler: it collapses with no script at
         * all, so the screen still works if the inline script is ever blocked by
         * a content security policy.
         */
        return '<details class="sf-dump-branch" open>'
            . '<summary class="sf-dump-summary">'
            . '<span class="text-primary">' . $label . '</span> '
            . '<span class="text-muted">' . $open . $count . $close . '</span>'
            . '</summary>'
            . '<div class="sf-dump-children">' . $rows . '</div>'
            . '</details>';
    }

    /**
     * Render a key, coloured by what kind of key it is.
     *
     * @param array<string, mixed> $child The child node
     * @return string The markup
     */
    private static function key(array $child): string
    {
        $name = self::e((string) $child['key']);

        return match ($child['keyType']) {
            'property' => '<span class="text-muted text-xs">' . self::e((string) $child['visibility'])
                . '</span> <span class="sf-dump-prop">$' . $name . '</span>',
            'int' => '<span class="text-warning">' . $name . '</span>',
            default => '<span class="sf-dump-str">"' . $name . '"</span>',
        };
    }

    /**
     * Render a value with no children.
     *
     * @param string $html The already-escaped markup
     * @param string $class The SFCSS colour class
     * @return string The markup
     */
    private static function leaf(string $html, string $class): string
    {
        return '<div class="sf-dump-line ' . $class . '">' . $html . '</div>';
    }

    /**
     * A badge, using SFCSS's own component.
     *
     * @param string $text The already-escaped text
     * @param string $variant The SFCSS badge class
     * @return string The markup
     */
    private static function badge(string $text, string $variant): string
    {
        return '<span class="badge ' . $variant . '">' . $text . '</span>';
    }

    /**
     * Wrap the cards in a page.
     *
     * @param string $title The page title
     * @param string $body The cards
     * @param array{file: string, line: int}|null $caller Where the dump was called
     * @return string The page
     */
    private static function page(string $title, string $body, ?array $caller): string
    {
        $where = $caller === null
            ? ''
            : '<code>' . self::e(self::shorten($caller['file'])) . ':' . $caller['line'] . '</code>';

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . self::e(self::locale()) . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::e($title) . '</title>' . "\n"
            . '<style>' . Assets::css() . '</style>' . "\n"
            . '<style>' . self::styles() . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body class="bg-light">' . "\n"
            . '<div class="container py-8 max-w-full">' . "\n"
            . '<header class="mb-6 d-flex items-center justify-between gap-3 flex-wrap">' . "\n"
            . '<h1 class="text-2xl font-bold m-0">' . self::e($title) . '</h1>' . "\n"
            . '<div class="text-sm text-muted">' . $where . '</div>' . "\n"
            . '</header>' . "\n"
            . $body
            . '<p class="text-xs text-muted mt-6 mb-0">'
            . 'Execution stopped here. Remove the <code>dd()</code> call to carry on.'
            . '</p>' . "\n"
            . '</div>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * The handful of rules SFCSS has no utility for.
     *
     * A dump is a tree of rows, and a tree needs a guide line and a disclosure
     * marker. Everything with an SFCSS class above uses SFCSS; this is what is
     * left, and it is written against SFCSS's own variables so it follows the
     * theme rather than fighting it.
     *
     * @return string The rules
     */
    private static function styles(): string
    {
        return <<<'CSS'
        .sf-dump-body{overflow-x:auto}
        .sf-dump-row{display:flex;gap:.5rem;align-items:flex-start;padding:.0625rem 0}
        .sf-dump-key{flex-shrink:0}
        .sf-dump-arrow{flex-shrink:0}
        .sf-dump-value{min-width:0;flex:1}
        .sf-dump-line{white-space:pre-wrap;word-break:break-word}
        .sf-dump-children{margin-left:.75rem;padding-left:.75rem;border-left:1px solid var(--surface-border)}
        .sf-dump-summary{cursor:pointer;list-style:none;padding:.0625rem 0}
        .sf-dump-summary::-webkit-details-marker{display:none}
        .sf-dump-summary::before{content:"\25be";display:inline-block;width:1rem;color:var(--body-color-muted)}
        .sf-dump-branch:not([open])>.sf-dump-summary::before{content:"\25b8"}
        .sf-dump-str{color:var(--code-color)}
        .sf-dump-prop{color:var(--body-color)}
        CSS;
    }

    /**
     * Shorten a path to the part that identifies it.
     *
     * @param string $path The absolute path
     * @return string The shortened path
     */
    private static function shorten(string $path): string
    {
        $root = Bootstrap::basePath();

        if ($root !== '' && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * The document language.
     *
     * @return string A BCP 47 tag
     */
    private static function locale(): string
    {
        return str_replace('_', '-', Config::string('APP_LOCALE', 'en'));
    }

    /**
     * Escape for HTML.
     *
     * @param string $value The value
     * @return string The escaped value
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
