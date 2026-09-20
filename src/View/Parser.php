<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * Splits SFHT template source into text, echo and directive tokens.
 *
 * The source is scanned as a whole, not line by line, so HTML, echoes and
 * directives can share a line and directive arguments can span several.
 */
final class Parser
{
    /** Directives that take a parenthesised argument list. */
    public const ARGUMENT_DIRECTIVES = [
        'if', 'elseif', 'foreach', 'for', 'while',
        'extends', 'block', 'include', 'includeWhen', 'component', 'use',
    ];

    /** Directives written on their own, such as @else or @endif. */
    public const PLAIN_DIRECTIVES = [
        'else', 'endif', 'endforeach', 'endfor', 'endwhile', 'endblock',
    ];

    private const TOKEN_PATTERN = '/@\{\{|\{\{--|\{!!|\{\{|@([A-Za-z]+)/';

    /**
     * Parse template content into tokens.
     *
     * Token types: "text" (content), "echo" and "raw" (expression, "raw" is
     * not escaped) and "directive" (name, args). Every token carries its line.
     *
     * @param string $content The template content
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException If an echo, comment or directive is left open
     */
    public function parse(string $content): array
    {
        $tokens = [];
        $position = 0;
        $length = strlen($content);

        while ($position < $length) {
            if (!preg_match(self::TOKEN_PATTERN, $content, $match, PREG_OFFSET_CAPTURE, $position)) {
                break;
            }

            [$found, $offset] = $match[0];
            $after = $offset + strlen($found);

            if ($found[0] === '@' && $found !== '@{{') {
                $name = $match[1][0];
                $token = $this->directiveAt($content, $name, $after, $offset);

                if ($token === null) {
                    $this->text($tokens, substr($content, $position, $after - $position), $position, $content);
                    $position = $after;
                    continue;
                }

                $this->text($tokens, substr($content, $position, $offset - $position), $position, $content);
                $position = $token['end'];
                unset($token['end']);
                $token['line'] = $this->lineOf($content, $offset);
                $tokens[] = $token;
                continue;
            }

            $this->text($tokens, substr($content, $position, $offset - $position), $position, $content);

            if ($found === '@{{') {
                $this->text($tokens, '{{', $offset, $content);
                $position = $after;
                continue;
            }

            $closing = match ($found) {
                '{{--' => '--}}',
                '{!!' => '!!}',
                default => '}}',
            };
            $end = strpos($content, $closing, $after);

            if ($end === false) {
                throw new RuntimeException(sprintf(
                    'Unclosed "%s" starting on line %d.',
                    $found,
                    $this->lineOf($content, $offset)
                ));
            }

            if ($found !== '{{--') {
                $tokens[] = [
                    'type' => $found === '{!!' ? 'raw' : 'echo',
                    'expression' => trim(substr($content, $after, $end - $after)),
                    'line' => $this->lineOf($content, $offset),
                ];
            }

            $position = $end + strlen($closing);
        }

        $this->text($tokens, substr($content, $position), $position, $content);

        return $tokens;
    }

    /**
     * Split "name | upper | truncate(50)" into the expression and its filters.
     *
     * A single "|" starts a filter. "||" and pipes inside strings, brackets
     * or parentheses belong to the expression.
     *
     * @param string $expression The expression with filters
     * @return array{expression: string, filters: array<int, array{name: string, args: string}>}
     * @throws RuntimeException If a filter is malformed
     */
    public function extractFilters(string $expression): array
    {
        $parts = $this->splitTopLevel($expression, '|', true);
        $expr = trim((string) array_shift($parts));
        $filters = [];

        foreach ($parts as $filter) {
            if (!preg_match('/^\s*(\w+)\s*(?:\((.*)\))?\s*$/s', $filter, $m)) {
                throw new RuntimeException('Invalid filter "' . trim($filter) . '".');
            }

            $filters[] = ['name' => $m[1], 'args' => trim($m[2] ?? '')];
        }

        return ['expression' => $expr, 'filters' => $filters];
    }

    /**
     * Split directive arguments on top-level commas.
     *
     * @param string $args The raw argument list
     * @return array<int, string>
     */
    public function splitArguments(string $args): array
    {
        if (trim($args) === '') {
            return [];
        }

        return array_map('trim', $this->splitTopLevel($args, ',', false));
    }

    /**
     * Check whether a directive name is part of SFHT.
     *
     * @param string $directive The directive name
     * @param string $args The directive arguments
     * @return bool
     */
    public function validateDirective(string $directive, string $args): bool
    {
        return in_array($directive, self::ARGUMENT_DIRECTIVES, true)
            || in_array($directive, self::PLAIN_DIRECTIVES, true);
    }

    /**
     * Read the directive whose name ends at $after, or null when the "@word"
     * is plain text (an e-mail address, "@media", an SFJS "@if=..." attribute).
     *
     * @return array<string, mixed>|null
     */
    private function directiveAt(string $content, string $name, int $after, int $offset): ?array
    {
        if (in_array($name, self::PLAIN_DIRECTIVES, true)) {
            return ['type' => 'directive', 'name' => $name, 'args' => '', 'end' => $after];
        }

        if (!in_array($name, self::ARGUMENT_DIRECTIVES, true)) {
            return null;
        }

        $open = $after + strspn($content, " \t", $after);

        if (($content[$open] ?? '') !== '(') {
            return null;
        }

        $close = $this->closingParenthesis($content, $open);

        if ($close === null) {
            throw new RuntimeException(sprintf(
                'Unclosed "(" for @%s on line %d.',
                $name,
                $this->lineOf($content, $offset)
            ));
        }

        return [
            'type' => 'directive',
            'name' => $name,
            'args' => trim(substr($content, $open + 1, $close - $open - 1)),
            'end' => $close + 1,
        ];
    }

    /**
     * Find the parenthesis that closes the one at $open, skipping strings.
     */
    private function closingParenthesis(string $content, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($content);

        for ($i = $open; $i < $length; $i++) {
            $char = $content[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Split on a separator that sits outside strings, brackets and parentheses.
     *
     * @return array<int, string>
     */
    private function splitTopLevel(string $source, string $separator, bool $skipDoubled): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $source[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                if ($skipDoubled && ($source[$i + 1] ?? '') === $separator) {
                    $current .= $char . $source[++$i];
                    continue;
                }

                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Append a text token, merging with a preceding text token.
     *
     * @param array<int, array<string, mixed>> $tokens
     */
    private function text(array &$tokens, string $text, int $offset, string $content): void
    {
        if ($text === '') {
            return;
        }

        $last = array_key_last($tokens);

        if ($last !== null && $tokens[$last]['type'] === 'text') {
            $tokens[$last]['content'] .= $text;
            return;
        }

        $tokens[] = ['type' => 'text', 'content' => $text, 'line' => $this->lineOf($content, $offset)];
    }

    private function lineOf(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }
}
