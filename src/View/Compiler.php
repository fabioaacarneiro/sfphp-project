<?php

namespace SfphpProject\src\View;

/**
 * Compiles SFHT template syntax into executable PHP code.
 */
final class Compiler
{
    private Parser $parser;
    private array $blocks = [];
    private ?string $extends = null;
    private int $indentLevel = 0;

    /**
     * Create a compiler.
     */
    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Compile template content to PHP code.
     *
     * @param string $content The template content
     * @return string The compiled PHP code
     */
    public function compile(string $content): string
    {
        $tokens = $this->parser->parse($content);
        $code = '';

        foreach ($tokens as $token) {
            $code .= $this->compileToken($token);
        }

        return "<?php\n" . $code . "\n";
    }

    /**
     * Compile a single token to PHP.
     *
     * @param array $token The token to compile
     * @return string The compiled PHP code
     */
    private function compileToken(array $token): string
    {
        return match ($token['type']) {
            'directive' => $this->compileDirective($token),
            'echo' => $this->compileEcho($token),
            'text' => $this->compileText($token),
            default => '',
        };
    }

    /**
     * Compile a directive.
     *
     * @param array $token The directive token
     * @return string
     */
    private function compileDirective(array $token): string
    {
        $name = $token['name'];
        $args = $token['args'];

        return match ($name) {
            'if' => $this->compileIf($args),
            'elseif' => $this->compileElseif($args),
            'else' => $this->compileElse(),
            'endif' => $this->compileEndif(),
            'foreach' => $this->compileForeach($args),
            'endforeach' => $this->compileEndforeach(),
            'for' => $this->compileFor($args),
            'endfor' => $this->compileEndfor(),
            'while' => $this->compileWhile($args),
            'endwhile' => $this->compileEndwhile(),
            'extends' => $this->compileExtends($args),
            'block' => $this->compileBlock($args),
            'endblock' => $this->compileEndblock(),
            'include' => $this->compileInclude($args),
            'includeWhen' => $this->compileIncludeWhen($args),
            'component' => $this->compileComponent($args),
            'use' => $this->compileUse($args),
            default => '',
        };
    }

    private function compileIf(string $condition): string
    {
        $this->indentLevel++;
        return $this->indent() . "if ({$condition}) {\n";
    }

    private function compileElseif(string $condition): string
    {
        $this->indentLevel--;
        $code = $this->indent() . "} elseif ({$condition}) {\n";
        $this->indentLevel++;
        return $code;
    }

    private function compileElse(): string
    {
        $this->indentLevel--;
        $code = $this->indent() . "} else {\n";
        $this->indentLevel++;
        return $code;
    }

    private function compileEndif(): string
    {
        $this->indentLevel--;
        return $this->indent() . "}\n";
    }

    private function compileForeach(string $args): string
    {
        $this->indentLevel++;
        return $this->indent() . "foreach ({$args}) {\n";
    }

    private function compileEndforeach(): string
    {
        $this->indentLevel--;
        return $this->indent() . "}\n";
    }

    private function compileFor(string $args): string
    {
        $this->indentLevel++;
        return $this->indent() . "for ({$args}) {\n";
    }

    private function compileEndfor(): string
    {
        $this->indentLevel--;
        return $this->indent() . "}\n";
    }

    private function compileWhile(string $args): string
    {
        $this->indentLevel++;
        return $this->indent() . "while ({$args}) {\n";
    }

    private function compileEndwhile(): string
    {
        $this->indentLevel--;
        return $this->indent() . "}\n";
    }

    private function compileExtends(string $template): string
    {
        $this->extends = trim($template, '\'"');
        return '';
    }

    private function compileBlock(string $name): string
    {
        return $this->indent() . "// @block('{$name}')\n";
    }

    private function compileEndblock(): string
    {
        return $this->indent() . "// @endblock\n";
    }

    private function compileInclude(string $template): string
    {
        $file = trim($template, '\'"');
        return $this->indent() . "include \$__engine->resolve('{$file}');\n";
    }

    private function compileIncludeWhen(string $args): string
    {
        preg_match('/\s*(.+?)\s*,\s*[\'"](.+?)[\'"]\s*(?:,\s*(.+))?/', $args, $matches);
        $condition = $matches[1] ?? '';
        $template = $matches[2] ?? '';
        $vars = $matches[3] ?? '[]';

        return $this->indent() . "if ({$condition}) {\n"
            . $this->indent() . "  include \$__engine->resolve('{$template}');\n"
            . $this->indent() . "}\n";
    }

    private function compileComponent(string $args): string
    {
        preg_match('/[\'"](.+?)[\'"]\s*,\s*\[(.+)\]/', $args, $matches);
        $component = $matches[1] ?? '';
        $vars = $matches[2] ?? '';

        return $this->indent() . "\$__vars = [{$vars}];\n"
            . $this->indent() . "include \$__engine->resolve('{$component}');\n";
    }

    private function compileUse(string $feature): string
    {
        $feature = trim($feature, '\'"');
        return $this->indent() . "// @use({$feature})\n";
    }

    /**
     * Compile an echo expression (variable output with filters).
     *
     * @param array $token The echo token
     * @return string
     */
    private function compileEcho(array $token): string
    {
        $expr = trim($token['expression']);
        $parsed = $this->parser->extractFilters($expr);

        $php = $this->indent() . 'echo ';

        foreach (array_reverse($parsed['filters']) as $filter) {
            $php .= "\$__engine->filter('{$filter['name']}', ";
        }

        $php .= $this->escapeExpression($parsed['expression']);

        foreach ($parsed['filters'] as $filter) {
            $args = !empty($filter['args']) ? ", [{$filter['args']}]" : ", []";
            $php .= $args . ')';
        }

        $php .= ";\n";

        return $php;
    }

    /**
     * Compile raw text output.
     *
     * @param array $token The text token
     * @return string
     */
    private function compileText(array $token): string
    {
        if (empty(trim($token['content']))) {
            return '';
        }

        $escaped = addslashes($token['content']);
        return $this->indent() . "echo \"{$escaped}\";\n";
    }

    /**
     * Escape a PHP expression for safe evaluation.
     *
     * @param string $expr The expression
     * @return string
     */
    private function escapeExpression(string $expr): string
    {
        $expr = trim($expr);

        if (preg_match('/^\$\w+/', $expr)) {
            return $expr;
        }

        return "'{$expr}'";
    }

    /**
     * Get current indentation.
     *
     * @return string
     */
    private function indent(): string
    {
        return str_repeat('  ', $this->indentLevel);
    }
}
