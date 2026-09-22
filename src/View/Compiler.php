<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * Compiles an SFHT token stream into executable PHP.
 *
 * Two rules drive the output.
 *
 * Literal text is emitted as a single-quoted PHP string. The previous compiler
 * used addslashes() and a double-quoted string, and addslashes() does not
 * escape "$": any markup containing "$total" was interpolated as a variable at
 * render time, which let page content reach into the template scope. Single
 * quotes do not interpolate, so escaping the backslash and the quote is enough
 * to reproduce the source byte for byte.
 *
 * Output is HTML-escaped by default. "{{ }}" escapes, "{!! !!}" does not. A
 * template engine whose default is unescaped output turns every variable a
 * page renders into a stored-XSS candidate, so the safe form is the short one
 * and bypassing it has to be written out explicitly.
 */
final class Compiler
{
    /**
     * Blocks that close with an "end" directive, mapped to the opener they need.
     */
    private const CLOSERS = [
        'endif' => 'if',
        'endunless' => 'unless',
        'endforeach' => 'foreach',
        'endforelse' => 'forelse',
        'endfor' => 'for',
        'endwhile' => 'while',
        'endblock' => 'block',
    ];

    private Parser $parser;

    /**
     * Open control structures, innermost last.
     *
     * @var array<int, array{name: string, line: int}>
     */
    private array $stack = [];

    /**
     * Create a compiler.
     */
    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Compile template source to PHP.
     *
     * @param string $content The template source
     * @return string The compiled PHP code
     * @throws RuntimeException If the template has unbalanced directives
     */
    public function compile(string $content): string
    {
        /*
         * Reset per call. These used to be instance state that survived
         * between templates, so a template leaked its open blocks into
         * whatever was compiled next through the same engine.
         */
        $this->stack = [];

        $code = "<?php\n";

        foreach ($this->parser->parse($content) as $token) {
            $code .= match ($token['type']) {
                'text' => $this->compileText($token['value']),
                'php' => rtrim($token['code']) . "\n",
                'echo' => $this->compileEcho($token['expression'], true),
                'raw' => $this->compileEcho($token['expression'], false),
                'directive' => $this->compileDirective($token),
                default => '',
            };
        }

        if ($this->stack !== []) {
            $open = end($this->stack);

            throw new RuntimeException(
                "Unclosed @{$open['name']} opened on line {$open['line']}."
            );
        }

        return $code;
    }

    /**
     * Compile literal template text.
     *
     * @param string $text The literal text
     * @return string The compiled PHP code
     */
    private function compileText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $escaped = str_replace(['\\', '\''], ['\\\\', '\\\''], $text);

        return "echo '{$escaped}';\n";
    }

    /**
     * Compile an output expression and its filter chain.
     *
     * @param string $expression The expression source
     * @param bool $escape Whether to HTML-escape the result
     * @return string The compiled PHP code
     */
    private function compileEcho(string $expression, bool $escape): string
    {
        $parsed = $this->parser->extractFilters($expression);
        $code = '(' . $parsed['expression'] . ')';

        foreach ($parsed['filters'] as $filter) {
            $arguments = $filter['args'] === '' ? '[]' : '[' . $filter['args'] . ']';
            $code = "\$__engine->filter('{$filter['name']}', {$code}, {$arguments})";
        }

        if ($escape) {
            /*
             * Markup the framework produced prints as it is; everything else is
             * escaped. Without this, composing a component would need the raw
             * form, {!! Card(...) !!}, and asking an author to remember which
             * values are trusted is how {!! $comment !!} eventually ships.
             */
            $code = "\SfphpProject\src\View\Compiler::text({$code})";
        }

        return "echo {$code};\n";
    }

    /**
     * Render a value for output, escaping anything that is not known markup.
     *
     * @param mixed $value The value
     * @return string The text to print
     */
    public static function text(mixed $value): string
    {
        if ($value instanceof Sfht) {
            return (string) $value;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Compile a directive.
     *
     * @param array<string, mixed> $token The directive token
     * @return string The compiled PHP code
     * @throws RuntimeException If the directive is misplaced
     */
    private function compileDirective(array $token): string
    {
        $name = $token['name'];
        $args = $token['args'];
        $line = $token['line'];

        if (isset(self::CLOSERS[$name])) {
            $this->closeBlock($name, $line);
        }

        return match ($name) {
            'if' => $this->openBlock('if', $line, "if ({$args}) {\n"),
            'elseif' => $this->requireOpen(['if'], $name, $line, "} elseif ({$args}) {\n"),
            'else' => $this->requireOpen(['if', 'unless'], $name, $line, "} else {\n"),
            'endif' => "}\n",

            'unless' => $this->openBlock('unless', $line, "if (!({$args})) {\n"),
            'endunless' => "}\n",

            'foreach' => $this->openBlock('foreach', $line, "foreach ({$args}) {\n"),
            'endforeach' => "}\n",

            'forelse' => $this->compileForelse($args, $line),
            'empty' => $this->requireOpen(['forelse'], $name, $line, "}\nif (!\$__forelse) {\n"),
            'endforelse' => "}\n",

            'for' => $this->openBlock('for', $line, "for ({$args}) {\n"),
            'endfor' => "}\n",

            'while' => $this->openBlock('while', $line, "while ({$args}) {\n"),
            'endwhile' => "}\n",

            'extends' => "\$__engine->extend({$args});\n",
            'block' => $this->openBlock('block', $line, "\$__engine->startBlock({$args});\n"),
            'endblock' => "\$__engine->endBlock();\n",

            'include' => $this->compileInclude($args),
            'includeWhen' => $this->compileIncludeWhen($args, $line),
            'component' => $this->compileInclude($args),

            default => '',
        };
    }

    /**
     * Compile a "@forelse" loop header.
     *
     * @param string $args The loop expression
     * @param int $line The directive line
     * @return string The compiled PHP code
     */
    private function compileForelse(string $args, int $line): string
    {
        return $this->openBlock(
            'forelse',
            $line,
            "\$__forelse = false;\nforeach ({$args}) {\n    \$__forelse = true;\n"
        );
    }

    /**
     * Compile "@include('template')" or "@include('template', [...])".
     *
     * @param string $args The directive arguments
     * @return string The compiled PHP code
     */
    private function compileInclude(string $args): string
    {
        $parts = $this->splitArguments($args);
        $template = $parts[0] ?? "''";
        $data = $parts[1] ?? '[]';

        /*
         * get_defined_vars() gives the partial the variables in scope at the
         * point of inclusion, which is what a reader expects a partial to see,
         * and the explicit array wins over it.
         */
        return "echo \$__engine->renderPartial({$template}, "
            . "array_merge(get_defined_vars(), {$data}));\n";
    }

    /**
     * Compile "@includeWhen(condition, 'template', [...])".
     *
     * @param string $args The directive arguments
     * @param int $line The directive line
     * @return string The compiled PHP code
     * @throws RuntimeException If the directive has too few arguments
     */
    private function compileIncludeWhen(string $args, int $line): string
    {
        $parts = $this->splitArguments($args);

        if (count($parts) < 2) {
            throw new RuntimeException(
                "@includeWhen needs a condition and a template on line {$line}."
            );
        }

        $condition = array_shift($parts);
        $template = $parts[0];
        $data = $parts[1] ?? '[]';

        return "if ({$condition}) {\n"
            . "echo \$__engine->renderPartial({$template}, "
            . "array_merge(get_defined_vars(), {$data}));\n"
            . "}\n";
    }

    /**
     * Split a directive argument list on top-level commas.
     *
     * @param string $args The raw argument source
     * @return array<int, string> The individual arguments
     */
    private function splitArguments(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $character = $args[$i];

            if ($quote !== null) {
                $current .= $character;

                if ($character === '\\' && $i + 1 < $length) {
                    $current .= $args[++$i];

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

            if ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Record an opened control structure and return its code.
     *
     * @param string $name The directive opening the structure
     * @param int $line The directive line
     * @param string $code The compiled PHP code
     * @return string The compiled PHP code
     */
    private function openBlock(string $name, int $line, string $code): string
    {
        $this->stack[] = ['name' => $name, 'line' => $line];

        return $code;
    }

    /**
     * Pop the structure a closing directive terminates.
     *
     * @param string $closer The closing directive
     * @param int $line The directive line
     * @return void
     * @throws RuntimeException If nothing matching is open
     */
    private function closeBlock(string $closer, int $line): void
    {
        $expected = self::CLOSERS[$closer];
        $open = array_pop($this->stack);

        if ($open === null) {
            throw new RuntimeException("@{$closer} on line {$line} closes nothing.");
        }

        if ($open['name'] !== $expected) {
            throw new RuntimeException(
                "@{$closer} on line {$line} closes @{$open['name']} opened on line {$open['line']}."
            );
        }
    }

    /**
     * Assert that a directive appears inside one of the given structures.
     *
     * @param array<int, string> $allowed The structures the directive may appear in
     * @param string $name The directive name
     * @param int $line The directive line
     * @param string $code The compiled PHP code
     * @return string The compiled PHP code
     * @throws RuntimeException If the directive is misplaced
     */
    private function requireOpen(array $allowed, string $name, int $line, string $code): string
    {
        $open = end($this->stack);

        if ($open === false || !in_array($open['name'], $allowed, true)) {
            throw new RuntimeException(
                "@{$name} on line {$line} must appear inside @" . implode(' or @', $allowed) . '.'
            );
        }

        return $code;
    }
}
