<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * Compiles SFHT template syntax into executable PHP code.
 *
 * The output starts in HTML mode: text is emitted as it is and only echoes and
 * directives switch into PHP, using the alternative control-structure syntax.
 * Compiled code expects an "$__engine" variable holding the SfhtEngine.
 */
final class Compiler
{
    /**
     * Compiler revision, part of the cache key so that templates compiled by
     * an older revision are never served after an upgrade.
     */
    public const VERSION = 2;

    private Parser $parser;

    /** @var array<int, array{type: string, line: int, id?: int}> */
    private array $open = [];
    private int $loops = 0;

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
     * @throws RuntimeException On a syntax error, with the offending line
     */
    public function compile(string $content): string
    {
        $this->open = [];
        $this->loops = 0;

        $tokens = $this->parser->parse($content);
        $code = '';

        foreach ($tokens as $index => $token) {
            $code .= match ($token['type']) {
                'text' => $this->compileText($token['content']),
                'echo', 'raw' => $this->compileEcho($token)
                    . $this->keepNewline($tokens[$index + 1] ?? null),
                'directive' => '<?php ' . $this->compileDirective($token) . ' ?>',
            };
        }

        if ($this->open !== []) {
            $unclosed = end($this->open);
            throw $this->error("@{$unclosed['type']} is never closed", $unclosed['line']);
        }

        return $code;
    }

    /**
     * PHP swallows one newline after "?>", which would glue the line after an
     * echo to the echo itself. Give that newline back.
     */
    private function keepNewline(?array $next): string
    {
        return $next !== null && $next['type'] === 'text' && str_starts_with($next['content'], "\n")
            ? "\n"
            : '';
    }

    /**
     * Emit literal text, defusing "<?" so template text can never run as PHP.
     */
    private function compileText(string $text): string
    {
        return str_replace('<?', "<?php echo '<?'; ?>", $text);
    }

    /**
     * Compile "{{ expr | filter }}" (escaped) and "{!! expr !!}" (raw).
     *
     * @param array{type: string, expression: string, line: int} $token
     */
    private function compileEcho(array $token): string
    {
        $parsed = $this->parser->extractFilters($token['expression']);

        if ($parsed['expression'] === '') {
            throw $this->error('Empty echo expression', $token['line']);
        }

        $php = $parsed['expression'];

        foreach ($parsed['filters'] as $filter) {
            $args = $filter['args'] !== '' ? ", [{$filter['args']}]" : '';
            $php = "\$__engine->filter('{$filter['name']}', {$php}{$args})";
        }

        $last = $parsed['filters'] === [] ? null : end($parsed['filters'])['name'];

        if ($token['type'] === 'echo' && $last !== 'escape') {
            $php = "\$__engine->e({$php})";
        }

        return "<?= {$php} ?>";
    }

    /**
     * @param array{name: string, args: string, line: int} $token
     */
    private function compileDirective(array $token): string
    {
        ['name' => $name, 'args' => $args, 'line' => $line] = $token;

        if ($args === '' && in_array($name, Parser::ARGUMENT_DIRECTIVES, true) && $name !== 'use') {
            throw $this->error("@{$name} needs arguments", $line);
        }

        return match ($name) {
            'if' => $this->begin('if', $line) . "if ({$args}):",
            'elseif' => $this->expect('if', $line, 'elseif') . "elseif ({$args}):",
            'else' => $this->expect('if', $line, 'else') . 'else:',
            'endif' => $this->end('if', $line) . 'endif;',
            'foreach' => $this->compileForeach($args, $line),
            'endforeach' => $this->compileEndforeach($line),
            'for' => $this->begin('for', $line) . "for ({$args}):",
            'endfor' => $this->end('for', $line) . 'endfor;',
            'while' => $this->begin('while', $line) . "while ({$args}):",
            'endwhile' => $this->end('while', $line) . 'endwhile;',
            'extends' => "\$__engine->extend({$args});",
            'block' => $this->begin('block', $line) . "\$__engine->startBlock({$args});",
            'endblock' => $this->end('block', $line) . 'echo $__engine->endBlock();',
            'include' => $this->compileInclude($args, $line),
            'includeWhen' => $this->compileIncludeWhen($args, $line),
            'component' => $this->compileComponent($args, $line),
            'use' => '/* @use */',
        };
    }

    private function compileForeach(string $args, int $line): string
    {
        if (!preg_match('/^(.+?)\s+as\s+(.+)$/s', $args, $m)) {
            throw $this->error('@foreach expects "$items as $item"', $line);
        }

        $id = ++$this->loops;
        $this->open[] = ['type' => 'foreach', 'line' => $line, 'id' => $id];

        return "\$__loop{$id} = \$__engine->loop({$m[1]}, \$loop ?? null); "
            . "foreach (\$__loop{$id}->items as {$m[2]}): \$loop = \$__loop{$id}->tick();";
    }

    private function compileEndforeach(int $line): string
    {
        $id = $this->closeTop('foreach', $line)['id'];

        return "endforeach; \$loop = \$__loop{$id}->parent;";
    }

    private function compileInclude(string $args, int $line): string
    {
        [$name, $vars] = $this->templateArguments($args, 'include', $line);

        return "echo \$__engine->includeTemplate({$name}, {$vars}, get_defined_vars());";
    }

    private function compileIncludeWhen(string $args, int $line): string
    {
        $parts = $this->parser->splitArguments($args);

        if (count($parts) < 2 || count($parts) > 3) {
            throw $this->error("@includeWhen expects a condition, a template and optional data", $line);
        }

        $vars = $parts[2] ?? '[]';

        return "if ({$parts[0]}): echo \$__engine->includeTemplate({$parts[1]}, {$vars}, get_defined_vars()); endif;";
    }

    private function compileComponent(string $args, int $line): string
    {
        [$name, $vars] = $this->templateArguments($args, 'component', $line);

        return "echo \$__engine->componentTemplate({$name}, {$vars});";
    }

    /**
     * @return array{0: string, 1: string} Template name and data expressions
     */
    private function templateArguments(string $args, string $directive, int $line): array
    {
        $parts = $this->parser->splitArguments($args);

        if (count($parts) < 1 || count($parts) > 2) {
            throw $this->error("@{$directive} expects a template and optional data", $line);
        }

        return [$parts[0], $parts[1] ?? '[]'];
    }

    private function begin(string $type, int $line): string
    {
        $this->open[] = ['type' => $type, 'line' => $line];

        return '';
    }

    private function end(string $type, int $line): string
    {
        $this->closeTop($type, $line);

        return '';
    }

    /**
     * Ensure the innermost open structure is $type, for @elseif and @else.
     */
    private function expect(string $type, int $line, string $directive): string
    {
        $top = end($this->open);

        if ($top === false || $top['type'] !== $type) {
            throw $this->error("@{$directive} without a matching @{$type}", $line);
        }

        return '';
    }

    /**
     * @return array{type: string, line: int, id?: int}
     */
    private function closeTop(string $type, int $line): array
    {
        $top = array_pop($this->open);

        if ($top === null || $top['type'] !== $type) {
            throw $this->error("@end{$type} without a matching @{$type}", $line);
        }

        return $top;
    }

    private function error(string $message, int $line): RuntimeException
    {
        return new RuntimeException("SFHT syntax error on line {$line}: {$message}.");
    }
}
