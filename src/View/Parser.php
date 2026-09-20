<?php

namespace SfphpProject\src\View;

/**
 * Parses SFHT template syntax and tokenizes directives.
 */
final class Parser
{
    private const DIRECTIVE_PATTERN = '/@(\w+)(?:\(([^)]*)\))?/';
    private const VARIABLE_PATTERN = '/\{\{\s*([^}]+)\s*\}\}/';
    private const ECHO_PATTERN = '/\{\{\s*(.+?)\s*\}\}/';

    /**
     * Parse template content into tokens.
     *
     * @param string $content The template content
     * @return array<int, array{type: string, value: string, args: string|null}>
     */
    public function parse(string $content): array
    {
        $tokens = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            $tokens = array_merge($tokens, $this->parseLine($line, $lineNum));
        }

        return $tokens;
    }

    /**
     * Parse a single line and extract tokens.
     *
     * @param string $line The line to parse
     * @param int $lineNum The line number
     * @return array<int, array>
     */
    private function parseLine(string $line, int $lineNum): array
    {
        $tokens = [];
        $pattern = '/@(\w+)(?:\s*\(([^)]*)\))?/';

        if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $directive) {
                $name = $directive[0];
                $args = $matches[2][$index][0] ?? '';

                $tokens[] = [
                    'type' => 'directive',
                    'name' => $name,
                    'args' => trim($args),
                    'line' => $lineNum + 1,
                ];
            }
        }

        if (preg_match_all(self::ECHO_PATTERN, $line, $matches)) {
            foreach ($matches[1] as $expr) {
                $tokens[] = [
                    'type' => 'echo',
                    'expression' => $expr,
                    'line' => $lineNum + 1,
                ];
            }
        }

        if (!empty($line) && !preg_match('/@\w+/', $line) && !preg_match(self::ECHO_PATTERN, $line)) {
            $tokens[] = [
                'type' => 'text',
                'content' => $line,
                'line' => $lineNum + 1,
            ];
        }

        return $tokens;
    }

    /**
     * Extract filters from expression (e.g., "name | upper | truncate(50)").
     *
     * @param string $expression The expression with filters
     * @return array{expression: string, filters: array}
     */
    public function extractFilters(string $expression): array
    {
        $parts = array_map('trim', explode('|', $expression));
        $expr = array_shift($parts);
        $filters = [];

        foreach ($parts as $filter) {
            if (preg_match('/(\w+)(?:\(([^)]*)\))?/', $filter, $m)) {
                $filters[] = [
                    'name' => $m[1],
                    'args' => $m[2] ?? '',
                ];
            }
        }

        return [
            'expression' => $expr,
            'filters' => $filters,
        ];
    }

    /**
     * Validate directive syntax.
     *
     * @param string $directive The directive name
     * @param string $args The directive arguments
     * @return bool
     */
    public function validateDirective(string $directive, string $args): bool
    {
        $validDirectives = [
            'if', 'elseif', 'else', 'endif',
            'foreach', 'endforeach',
            'for', 'endfor',
            'while', 'endwhile',
            'extends', 'block', 'endblock',
            'include', 'includeWhen',
            'component',
            'use',
        ];

        return in_array($directive, $validDirectives);
    }
}
