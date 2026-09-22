<?php

namespace SfphpProject\src\Debug;

/**
 * The same dump, for a terminal.
 *
 * A queue worker, a console command and a test run have no browser to show a
 * page to, and a dump that only works in a request is a dump that is missing
 * exactly when a background job is the thing misbehaving.
 *
 * Colour is ANSI and only when the output is a terminal. Redirected to a file
 * or piped into `grep`, the escape codes would be noise in the result.
 */
final class TextDump
{
    /**
     * Render values as terminal text.
     *
     * @param list<mixed> $values The values dumped
     * @param array{file: string, line: int}|null $caller Where the dump was called
     * @param bool|null $colour Whether to colour, or null to decide from the stream
     * @return string The text
     */
    public static function render(array $values, ?array $caller = null, ?bool $colour = null): string
    {
        $colour ??= self::terminalSupportsColour();
        $out = '';

        if ($caller !== null) {
            $out .= self::paint($caller['file'] . ':' . $caller['line'], '90', $colour) . "\n";
        }

        foreach ($values as $value) {
            $out .= self::node(Dumper::describe($value), 0, $colour) . "\n";
        }

        return $out;
    }

    /**
     * Render one node.
     *
     * @param array<string, mixed> $node The described value
     * @param int $indent How far in it sits
     * @param bool $colour Whether to colour
     * @return string The text
     */
    private static function node(array $node, int $indent, bool $colour): string
    {
        return match ($node['type']) {
            'array' => self::branch($node, 'array', '[]', $indent, $colour),
            'object' => ($node['circular'] ?? false)
                ? self::paint($node['class'] . ' { already shown above }', '33', $colour)
                : self::branch($node, (string) $node['class'], '{}', $indent, $colour),
            'string' => $node['binary']
                ? self::paint('binary, ' . $node['length'] . ' bytes', '90', $colour)
                : self::paint('"' . $node['value'] . '"', '32', $colour)
                    . self::paint(' (' . $node['length'] . ')', '90', $colour),
            'enum' => self::paint($node['class'] . '::' . $node['value'], '36', $colour),
            'closure' => self::paint(
                'Closure' . ($node['file'] === null ? '' : ' ' . $node['file'] . ':' . $node['line']),
                '36',
                $colour
            ),
            'null', 'uninitialised' => self::paint((string) ($node['value'] ?? 'null'), '90', $colour),
            'bool' => self::paint((string) $node['value'], '36', $colour),
            'int', 'float' => self::paint((string) $node['value'], '33', $colour),
            'unreadable' => self::paint('unreadable: ' . $node['value'], '31', $colour),
            'resource' => self::paint('resource(' . $node['value'] . ')', '90', $colour),
            default => (string) ($node['value'] ?? $node['type']),
        };
    }

    /**
     * Render a node with children.
     *
     * @param array<string, mixed> $node The described value
     * @param string $label What it is
     * @param string $braces The pair of braces
     * @param int $indent How far in it sits
     * @param bool $colour Whether to colour
     * @return string The text
     */
    private static function branch(array $node, string $label, string $braces, int $indent, bool $colour): string
    {
        $open = $braces[0];
        $close = $braces[1];
        $head = self::paint($label, '36', $colour);

        if ($node['deep'] ?? false) {
            return $head . ' ' . self::paint($open . ' deeper than ' . Dumper::MAX_DEPTH . ' levels ' . $close, '33', $colour);
        }

        if ($node['children'] === []) {
            return $head . ' ' . $open . $close;
        }

        $pad = str_repeat('  ', $indent + 1);
        $out = $head . ' ' . $open . "\n";

        foreach ($node['children'] as $child) {
            $key = $child['keyType'] === 'property'
                ? self::paint((string) $child['visibility'] . ' $' . $child['key'], '90', $colour)
                : self::paint(is_numeric($child['key']) ? (string) $child['key'] : '"' . $child['key'] . '"', '35', $colour);

            $out .= $pad . $key . ' => ' . self::node($child['value'], $indent + 1, $colour) . "\n";
        }

        if ($node['truncated']) {
            $out .= $pad . self::paint('only the first ' . Dumper::MAX_ENTRIES . ' shown', '90', $colour) . "\n";
        }

        return $out . str_repeat('  ', $indent) . $close;
    }

    /**
     * Wrap text in an ANSI colour, when colour is wanted.
     *
     * @param string $text The text
     * @param string $code The ANSI code
     * @param bool $colour Whether to colour
     * @return string The text
     */
    private static function paint(string $text, string $code, bool $colour): string
    {
        return $colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    /**
     * Whether writing colour to standard output makes sense.
     *
     * @return bool True when it is a terminal that is not being redirected
     */
    private static function terminalSupportsColour(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            // https://no-color.org: an explicit request, so it wins.
            return false;
        }

        return PHP_SAPI === 'cli' && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
