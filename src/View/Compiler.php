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

    /**
     * Directives a .phpx markup region cannot use, with what to write instead.
     *
     * They all need the template engine — a layout to extend, a block table,
     * a directory of partials — and a component runs as a plain function call,
     * with no engine around it. Refusing them at build time, with the line,
     * is kinder than the "Call to a member function on null" they used to
     * produce the first time the component ran.
     */
    private const COMPONENT_REJECTS = [
        'extends' => 'a component is called, it does not extend a layout',
        'block' => 'a component is called, it does not fill a layout',
        'endblock' => 'a component is called, it does not fill a layout',
        'include' => 'call the other component instead, as {{ Card(...) }}',
        'includeWhen' => 'call the other component inside @if instead',
        'component' => 'call the other component instead, as {{ Card(...) }}',
        'use' => 'import it with `use function` at the top of the .phpx file',
    ];

    private Parser $parser;

    /**
     * Whether this compiles a .phpx markup region rather than a template.
     */
    private bool $component = false;

    /**
     * What ends each statement the compiler writes.
     *
     * A newline for a template, where the compiled file is only ever read by
     * PHP. A space for a component region, whose compiled lines have to stay
     * the lines the author wrote: the only newlines left are the ones in the
     * markup itself, so an error in the generated PHP names the .phpx line.
     */
    private string $eol = "\n";

    /**
     * The imports a template declared with @use, keyed to drop duplicates.
     *
     * @var array<string, string>
     */
    private array $uses = [];

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
     * A compiler for the markup regions of a .phpx component.
     *
     * The output is the same PHP, with three differences a function call
     * needs: filters run without an engine, the directives that need one are
     * refused at build time, and the statements keep the lines of the markup
     * they came from.
     *
     * @return self The compiler
     */
    public static function forComponents(): self
    {
        $compiler = new self();
        $compiler->component = true;
        $compiler->eol = ' ';

        return $compiler;
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
        $this->uses = [];

        $code = '';

        foreach ($this->parser->parse($content) as $token) {
            /*
             * A component's statements end in spaces, so the newlines the
             * parser trimmed away — around an expression, inside a comment —
             * are put back before the next token that knows its line. The
             * compiled region then has the markup's line structure exactly.
             */
            if ($this->component && isset($token['line'])) {
                $code .= str_repeat("\n", max(0, $token['line'] - 1 - substr_count($code, "\n")));
            }

            $code .= match ($token['type']) {
                'text' => $this->compileText($token['value']),
                'php' => $this->compilePhp($token['code']),
                'echo' => $this->compileEcho($token['expression'], true, $token['line']),
                'raw' => $this->compileEcho($token['expression'], false, $token['line']),
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

        /*
         * An import has to sit at the top level of the file, and a template
         * can ask for one inside @if or @block. Hoisting every @use onto the
         * opening line makes it valid wherever it was written.
         */
        $imports = $this->uses === [] ? '' : ' ' . implode(' ', $this->uses);

        return "<?php{$imports}\n" . $code;
    }

    /**
     * Compile a raw "@php" region.
     *
     * @param string $code The PHP between @php and @endphp
     * @return string The compiled PHP code
     */
    private function compilePhp(string $code): string
    {
        if (!$this->component) {
            return rtrim($code) . "\n";
        }

        /*
         * Kept byte for byte, newlines included, so the lines stay the
         * author's. The one newline added is after a trailing // comment,
         * which would otherwise swallow the statement written after it.
         */
        $tokens = token_get_all('<?php ' . $code);
        $last = null;

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $last = $token;
            }
        }

        $lineComment = is_array($last) && $last[0] === T_COMMENT && !str_starts_with($last[1], '/*');

        $endsOnItsLine = $lineComment && preg_match('/\n\s*$/', $code) !== 1;

        return $code . ($endsOnItsLine ? "\n" : ' ');
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

        return "echo '{$escaped}';{$this->eol}";
    }

    /**
     * Compile an output expression and its filter chain.
     *
     * @param string $expression The expression source
     * @param bool $escape Whether to HTML-escape the result
     * @param int $line The line the expression starts on
     * @return string The compiled PHP code
     * @throws RuntimeException If a component uses a filter that does not exist
     */
    private function compileEcho(string $expression, bool $escape, int $line): string
    {
        $parsed = $this->parser->extractFilters($expression);
        $code = '(' . $parsed['expression'] . ')';

        /*
         * default() is for a value that may not be there, and "not there"
         * includes a variable the view was never given. Reading it raised an
         * undefined-variable warning — an exception here — before default()
         * had a chance to run. A plain variable or path read through default()
         * is read with ??, which is silent when it is missing.
         */
        $usesDefault = in_array('default', array_column($parsed['filters'], 'name'), true);

        if ($usesDefault && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(\[[^\[\]]+\]|->[A-Za-z_][A-Za-z0-9_]*)*$/', trim($parsed['expression'])) === 1) {
            $code = '(' . trim($parsed['expression']) . ' ?? null)';
        }

        foreach ($parsed['filters'] as $filter) {
            $arguments = $filter['args'] === '' ? '[]' : '[' . $filter['args'] . ']';

            if (!$this->component) {
                $code = "\$__engine->filter('{$filter['name']}', {$code}, {$arguments})";

                continue;
            }

            /*
             * A component is a function call, with no engine in scope to ask,
             * so its filters are the standard ones, applied directly. Which
             * ones exist is known now, and a misspelt name is a build error
             * rather than an exception on the first request that renders it.
             */
            if (!SfhtEngine::hasStandardFilter($filter['name'])) {
                throw new RuntimeException(
                    "Unknown filter \"{$filter['name']}\" on line {$line}; a component can use "
                    . implode(', ', SfhtEngine::standardFilterNames()) . '.'
                );
            }

            $code = "\\SfphpProject\\src\\View\\SfhtEngine::standardFilter('{$filter['name']}', {$code}, {$arguments})";
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

        return "echo {$code};{$this->eol}";
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

        /*
         * "@use" with no argument list is text, as it is in a template, so it
         * is not refused here either.
         */
        $refused = isset(self::COMPONENT_REJECTS[$name]) && !($name === 'use' && $args === '');

        if ($this->component && $refused) {
            throw new RuntimeException(
                "@{$name} on line {$line} cannot be used in a .phpx component: "
                . self::COMPONENT_REJECTS[$name] . '.'
            );
        }

        if (isset(self::CLOSERS[$name])) {
            $this->closeBlock($name, $line);
        }

        return match ($name) {
            'if' => $this->openBlock('if', $line, "if ({$args}) {{$this->eol}"),
            'elseif' => $this->requireOpen(['if'], $name, $line, "} elseif ({$args}) {{$this->eol}"),
            'else' => $this->requireOpen(['if', 'unless'], $name, $line, "} else {{$this->eol}"),
            'endif' => "}{$this->eol}",

            'unless' => $this->openBlock('unless', $line, "if (!({$args})) {{$this->eol}"),
            'endunless' => "}{$this->eol}",

            'foreach' => $this->openBlock('foreach', $line, "foreach ({$args}) {{$this->eol}"),
            'endforeach' => "}{$this->eol}",

            'forelse' => $this->compileForelse($args, $line),
            'empty' => $this->requireOpen(['forelse'], $name, $line, "}{$this->eol}if (!\$__forelse" . (count($this->stack) - 1) . ") {{$this->eol}"),
            'endforelse' => "}{$this->eol}",

            'for' => $this->openBlock('for', $line, "for ({$args}) {{$this->eol}"),
            'endfor' => "}{$this->eol}",

            'while' => $this->openBlock('while', $line, "while ({$args}) {{$this->eol}"),
            'endwhile' => "}{$this->eol}",

            'extends' => "\$__engine->extend({$args});{$this->eol}",
            'block' => $this->openBlock('block', $line, "\$__engine->startBlock({$args});{$this->eol}"),
            'endblock' => "\$__engine->endBlock();{$this->eol}",

            'include' => $this->compileInclude($args),
            'includeWhen' => $this->compileIncludeWhen($args, $line),
            'component' => $this->compileInclude($args),

            'use' => $this->compileUse($args, $line),

            default => '',
        };
    }

    /**
     * Compile "@use(function App\\Card)", an import for the template.
     *
     * A compiled template runs in the global namespace, so a component —
     * a namespaced function — is not found by its bare name. This is the
     * `use` statement PHP already has, spelled as a directive so that it can
     * be written anywhere in the template: it is hoisted to the top of the
     * compiled file, the only place PHP accepts it.
     *
     * Written without an argument list, "@use" is text, so an address such as
     * "someone@use.example" still reaches the page.
     *
     * @param string $args The directive arguments
     * @param int $line The directive line
     * @return string The compiled PHP code
     * @throws RuntimeException If the argument is not a name to import
     */
    private function compileUse(string $args, int $line): string
    {
        if ($args === '') {
            return $this->compileText('@use');
        }

        $import = trim($args, " \t\n\r'\"");
        $name = '\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';

        if (preg_match('/^(?:(?:function|const)\s+)?' . $name . '(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?$/', $import) !== 1) {
            throw new RuntimeException(
                "@use on line {$line} needs a name to import, as @use(function App\\Components\\Card)."
            );
        }

        $this->uses[strtolower($import)] = 'use ' . preg_replace('/\s+/', ' ', $import) . ';';

        return '';
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
        /*
         * One flag per nesting depth. They all used to be $__forelse, so an
         * inner loop's flag was the one the outer @empty read: an outer list
         * with an item whose inner list was empty rendered the outer
         * "nothing here" as well.
         */
        $flag = '$__forelse' . count($this->stack);

        return $this->openBlock(
            'forelse',
            $line,
            "{$flag} = false;{$this->eol}foreach ({$args}) {{$this->eol}    {$flag} = true;{$this->eol}"
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
            . "array_merge(get_defined_vars(), {$data}));{$this->eol}";
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

        return "if ({$condition}) {{$this->eol}"
            . "echo \$__engine->renderPartial({$template}, "
            . "array_merge(get_defined_vars(), {$data}));{$this->eol}"
            . "}{$this->eol}";
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
