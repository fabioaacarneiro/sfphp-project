<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * Turns SFHT source into a flat token stream.
 *
 * The parser scans the document as a single stream, tracking byte offsets, and
 * emits every character it does not recognise as literal text. An earlier
 * version split the source into lines and gave each line exactly one token
 * type, which silently dropped any line that mixed markup with a directive and
 * treated every "@word" in the document as a directive: the "@300" inside a
 * Google Fonts URL turned the whole line into an unknown directive and erased
 * it from the output. Scanning by offset and only recognising known directive
 * names removes both failure modes.
 */
final class Parser
{
    /**
     * Directive names the compiler knows how to emit code for.
     *
     * An "@word" that is not in this list is literal text, which is what makes
     * an e-mail address or a "wght@300" query parameter survive the parser.
     */
    public const DIRECTIVES = [
        'if', 'elseif', 'else', 'endif',
        'unless', 'endunless',
        'foreach', 'endforeach',
        'forelse', 'empty', 'endforelse',
        'for', 'endfor',
        'while', 'endwhile',
        'extends', 'block', 'endblock',
        'include', 'includeWhen',
        'component', 'use',
        'php', 'endphp',
    ];

    /**
     * Directives that must be followed by an argument list.
     */
    private const REQUIRE_ARGUMENTS = [
        'if', 'elseif', 'unless', 'foreach', 'forelse', 'for', 'while',
        'extends', 'block', 'include', 'includeWhen', 'component',
    ];

    /**
     * Directives that must not be followed by an argument list.
     */
    private const REJECT_ARGUMENTS = [
        'else', 'endif', 'endunless', 'endforeach', 'empty', 'endforelse',
        'endfor', 'endwhile', 'endblock', 'php', 'endphp',
    ];

    /**
     * Tokenize template source.
     *
     * @param string $content The template source
     * @return array<int, array<string, mixed>> The token stream
     * @throws RuntimeException If a construct is opened but never closed
     */
    public function parse(string $content): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($content);
        // "@@" writes a literal "@", for text that needs "@if" to show as it is.
        $pattern = '/\{\{--|\{!!|\{\{|@@|@[A-Za-z_][A-Za-z0-9_]*/';

        while (
            $offset < $length
            && preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset) === 1
        ) {
            [$marker, $position] = $matches[0];

            if ($position > $offset) {
                $tokens[] = [
                    'type' => 'text',
                    'value' => substr($content, $offset, $position - $offset),
                ];
            }

            $line = substr_count($content, "\n", 0, $position) + 1;

            $offset = match (true) {
                $marker === '@@' => $this->literalAt($position, $tokens),
                $marker === '{{--' => $this->skipComment($content, $position, $line),
                $marker === '{!!' => $this->readEcho($content, $position, $line, $tokens, true),
                $marker === '{{' => $this->readEcho($content, $position, $line, $tokens, false),
                default => $this->readDirective($content, $position, $marker, $line, $tokens),
            };
        }

        if ($offset < $length) {
            $tokens[] = ['type' => 'text', 'value' => substr($content, $offset)];
        }

        return $tokens;
    }

    /**
     * Write the "@" that "@@" stands for.
     *
     * @param int $position The offset of "@@"
     * @param array<int, array<string, mixed>> $tokens The token stream, appended to
     * @return int The offset just past "@@"
     */
    private function literalAt(int $position, array &$tokens): int
    {
        $tokens[] = ['type' => 'text', 'value' => '@'];

        return $position + 2;
    }

    /**
     * Skip over a template comment.
     *
     * @param string $content The template source
     * @param int $position The offset of the opening marker
     * @param int $line The line the marker starts on
     * @return int The offset just past the comment
     * @throws RuntimeException If the comment is never closed
     */
    private function skipComment(string $content, int $position, int $line): int
    {
        $end = strpos($content, '--}}', $position);
        if ($end === false) {
            throw new RuntimeException("Unclosed template comment on line {$line}.");
        }

        return $end + 4;
    }

    /**
     * Read an escaped or raw output expression.
     *
     * @param string $content The template source
     * @param int $position The offset of the opening marker
     * @param int $line The line the marker starts on
     * @param array<int, array<string, mixed>> $tokens The token stream, appended to
     * @param bool $raw Whether the expression is unescaped
     * @return int The offset just past the expression
     * @throws RuntimeException If the expression is never closed
     */
    private function readEcho(
        string $content,
        int $position,
        int $line,
        array &$tokens,
        bool $raw
    ): int {
        $open = $raw ? '{!!' : '{{';
        $close = $raw ? '!!}' : '}}';
        $start = $position + strlen($open);

        $end = $this->findClose($content, $close, $start);
        if ($end === null) {
            throw new RuntimeException(
                "Unclosed \"{$open}\" expression on line {$line}."
            );
        }

        $expression = trim(substr($content, $start, $end - $start));
        if ($expression === '') {
            throw new RuntimeException("Empty expression on line {$line}.");
        }

        $tokens[] = [
            'type' => $raw ? 'raw' : 'echo',
            'expression' => $expression,
            'line' => $line,
        ];

        return $end + strlen($close);
    }

    /**
     * Read a directive and its optional argument list.
     *
     * @param string $content The template source
     * @param int $position The offset of the "@"
     * @param string $marker The matched "@name" text
     * @param int $line The line the directive starts on
     * @param array<int, array<string, mixed>> $tokens The token stream, appended to
     * @return int The offset just past the directive
     * @throws RuntimeException If the argument list is malformed
     */
    private function readDirective(
        string $content,
        int $position,
        string $marker,
        int $line,
        array &$tokens
    ): int {
        $name = substr($marker, 1);

        /*
         * Anything that is not a known directive is content, not syntax. This
         * is what lets "wght@300", "@media" in an inline stylesheet and an
         * e-mail address pass through untouched.
         */
        /*
         * An e-mail address is text too, even when its domain begins with a
         * directive's name: "webmaster@php.net" used to open a @php block and
         * "me@if.io" was an @if with no condition. The tell is both sides — a
         * word character before the "@" and the domain carrying on after the
         * name with "." or "-". "sim@endif" right against a word is still
         * the directive.
         */
        $before = $position > 0 ? $content[$position - 1] : '';
        $next = $content[$position + strlen($marker)] ?? '';
        $inAddress = preg_match('/[A-Za-z0-9._%+\-]/', $before) === 1 && ($next === '.' || $next === '-');

        if ($inAddress || !in_array($name, self::DIRECTIVES, true)) {
            $tokens[] = ['type' => 'text', 'value' => $marker];

            return $position + strlen($marker);
        }

        $after = $position + strlen($marker);

        /*
         * "@php" opens a raw PHP region. It is consumed whole here rather than
         * tokenized, because everything up to "@endphp" is code, not template
         * text: letting the normal scanner see it would emit the statements as
         * literal output.
         */
        if ($name === 'php') {
            $end = strpos($content, '@endphp', $after);
            if ($end === false) {
                throw new RuntimeException("Unclosed @php block opened on line {$line}.");
            }

            $tokens[] = [
                'type' => 'php',
                'code' => substr($content, $after, $end - $after),
                'line' => $line,
            ];

            return $end + strlen('@endphp');
        }

        if ($name === 'endphp') {
            throw new RuntimeException("@endphp without a matching @php on line {$line}.");
        }

        $cursor = $after;

        /*
         * A directive that takes no arguments reads a "(" only when it is
         * right against the name. "@else (optional)" is the word else
         * followed by text in brackets, and it used to fail as arguments
         * given to @else.
         */
        if (!in_array($name, self::REJECT_ARGUMENTS, true)) {
            while ($cursor < strlen($content) && ($content[$cursor] === ' ' || $content[$cursor] === "\t")) {
                $cursor++;
            }
        }

        $arguments = null;
        $end = $after;

        if ($cursor < strlen($content) && $content[$cursor] === '(') {
            $closing = $this->matchParenthesis($content, $cursor, $line, $name);
            $arguments = trim(substr($content, $cursor + 1, $closing - $cursor - 1));
            $end = $closing + 1;
        }

        if ($arguments === null && in_array($name, self::REQUIRE_ARGUMENTS, true)) {
            throw new RuntimeException("@{$name} requires arguments on line {$line}.");
        }

        if ($arguments !== null && in_array($name, self::REJECT_ARGUMENTS, true)) {
            throw new RuntimeException("@{$name} does not take arguments on line {$line}.");
        }

        $tokens[] = [
            'type' => 'directive',
            'name' => $name,
            'args' => $arguments ?? '',
            'line' => $line,
        ];

        return $end;
    }

    /**
     * Find where an expression closes, stepping over quoted strings.
     *
     * A "}}" inside a string — {{ $open ? '}}' : '' }} — used to end the
     * expression there, and the PHP that came out did not parse.
     *
     * @param string $content The template source
     * @param string $close The closing marker
     * @param int $start Where the expression starts
     * @return int|null The offset of the closing marker, or null when there is none
     */
    private function findClose(string $content, string $close, int $start): ?int
    {
        $length = strlen($content);
        $quote = null;

        for ($i = $start; $i < $length; $i++) {
            $character = $content[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if (substr_compare($content, $close, $i, strlen($close)) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the parenthesis closing the one at the given offset.
     *
     * Nesting and quoted strings are both tracked, so "@if(count($a) > 0)" and
     * "@include('a(b)')" are read correctly. A regular expression stopping at
     * the first ")" truncated both.
     *
     * @param string $content The template source
     * @param int $open The offset of the opening parenthesis
     * @param int $line The line the directive starts on
     * @param string $name The directive name, for error messages
     * @return int The offset of the matching closing parenthesis
     * @throws RuntimeException If the parenthesis is never closed
     */
    private function matchParenthesis(string $content, int $open, int $line, string $name): int
    {
        $depth = 0;
        $length = strlen($content);
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $character = $content[$i];

            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;

                continue;
            }

            if ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        throw new RuntimeException("Unclosed argument list for @{$name} on line {$line}.");
    }

    /**
     * Split an output expression into its base expression and filter chain.
     *
     * The "|" separating filters is distinguished from PHP's bitwise or by
     * only splitting at depth zero and outside quotes, so "{{ $a | upper }}"
     * yields a filter while "{{ $flags | MASK }}" is left as one expression
     * when it appears inside parentheses.
     *
     * @param string $expression The full expression
     * @return array{expression: string, filters: array<int, array{name: string, args: string}>}
     */
    public function extractFilters(string $expression): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $character = $expression[$i];

            if ($quote !== null) {
                $current .= $character;

                if ($character === '\\' && $i + 1 < $length) {
                    $current .= $expression[++$i];

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"') {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            }

            /*
             * "||" is the boolean operator, never a filter separator.
             */
            if (
                $character === '|'
                && $depth === 0
                && ($expression[$i + 1] ?? '') !== '|'
                && ($expression[$i - 1] ?? '') !== '|'
            ) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;
        $parts = array_map('trim', $parts);

        $base = array_shift($parts);
        $filters = [];

        foreach ($parts as $filter) {
            if ($filter === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(?:\((.*)\))?$/s', $filter, $matches) !== 1) {
                throw new RuntimeException("Invalid filter syntax: \"{$filter}\".");
            }

            $filters[] = [
                'name' => $matches[1],
                'args' => trim($matches[2] ?? ''),
            ];
        }

        return [
            'expression' => $base,
            'filters' => $filters,
        ];
    }
}
