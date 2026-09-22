<?php

namespace SfphpProject\src\View;

use RuntimeException;

/**
 * Turns a .phpx file into PHP.
 *
 * A spike. The idea is templ's: a component is a function with typed
 * parameters, and its markup sits inside the function rather than in a separate
 * file, so the thing you read is the thing that renders.
 *
 *     function Card(string $title, string $body): string
 *     {
 *         return sfht(
 *             <div class="card">
 *                 <h3>{{ $title }}</h3>
 *                 <p>{{ $body }}</p>
 *             </div>
 *         );
 *     }
 *
 * `sfht(` opens and its matching `)` closes. Everything between is markup,
 * handed to the SFHT compiler that already exists — so `{{ }}`, `{!! !!}`,
 * `@if` and `@foreach` all work inside, and escaping is the same everywhere.
 *
 * What the compiler emits is an immediately-invoked closure that buffers the
 * markup and returns it, called with the enclosing function's variables. Those
 * variables are the component's parameters, which is what makes the props a
 * contract rather than inherited scope.
 *
 * **The cost, stated plainly:** a .phpx file is not valid PHP, so Intelephense
 * cannot parse it and `php -l` cannot check it. What it can check is the file
 * this produces, which is why the compiler pads its output to keep line numbers
 * aligned: an error in the generated PHP points at the right line of the .phpx.
 */
final class Phpx
{
    /** The call that opens a markup region. */
    private const OPEN = 'sfht(';

    private Compiler $compiler;

    /**
     * Create the compiler.
     *
     * @param Compiler|null $compiler The SFHT compiler, or null for a new one
     */
    public function __construct(?Compiler $compiler = null)
    {
        $this->compiler = $compiler ?? new Compiler();
    }

    /**
     * Compile a .phpx source into PHP.
     *
     * @param string $source The .phpx source
     * @return string Valid PHP
     * @throws RuntimeException When a markup region is never closed
     */
    public function compile(string $source): string
    {
        $out = '';
        $offset = 0;

        while (($start = strpos($source, self::OPEN, $offset)) !== false) {
            /*
             * "sfht(" has to be a call rather than the tail of a longer name,
             * or a function called mysfht() would be mistaken for one.
             */
            if ($start > 0 && preg_match('/[A-Za-z0-9_\\\\]/', $source[$start - 1]) === 1) {
                $out .= substr($source, $offset, $start + strlen(self::OPEN) - $offset);
                $offset = $start + strlen(self::OPEN);

                continue;
            }

            $open = $start + strlen(self::OPEN);
            $close = $this->matchingParenthesis($source, $open);

            if ($close === null) {
                throw new RuntimeException(sprintf(
                    'A markup region opened at line %d is never closed.',
                    substr_count(substr($source, 0, $start), "\n") + 1
                ));
            }

            $markup = substr($source, $open, $close - $open);

            $out .= substr($source, $offset, $start - $offset);
            $out .= $this->region($markup);

            $offset = $close + 1;
        }

        return $out . substr($source, $offset);
    }

    /**
     * Compile one markup region into an expression.
     *
     * @param string $markup The markup between the parentheses
     * @return string A PHP expression producing the rendered string
     */
    private function region(string $markup): string
    {
        $compiled = $this->compiler->compile(trim($markup));

        /*
         * The SFHT compiler produces a whole template, so it opens with <?php —
         * correct for a file it is about to write, wrong for statements being
         * spliced into the middle of one. The tag comes off, and a closing one
         * comes off too when a template happened to end in HTML.
         */
        $compiled = preg_replace('/^\s*<\?php\s/', '', $compiled) ?? $compiled;
        $compiled = preg_replace('/\?>\s*$/', '', $compiled) ?? $compiled;

        /*
         * get_defined_vars() reads the component function's own variables,
         * which are its parameters — so what the markup can see is exactly what
         * the signature promised, and nothing from further out.
         */
        /*
         * No ?> around it: the SFHT compiler emits PHP statements, not HTML
         * with tags. Wrapping them in HTML context put `echo ...;` where the
         * parser expected text, which is the first thing this got wrong.
         */
        $expression = '(static function (array $__props): \\SfphpProject\\src\\View\\Sfht { '
            . 'extract($__props); ob_start(); '
            . $compiled
            . ' return new \\SfphpProject\\src\\View\\Sfht((string) ob_get_clean()); })(get_defined_vars())';

        /*
         * The markup spanned several lines and the expression is one, so every
         * line after it would shift. Padding puts them back: an error in the
         * generated PHP then names the line the author would look at.
         */
        $lost = substr_count($markup, "\n") - substr_count($expression, "\n");

        return $expression . str_repeat("\n", max(0, $lost));
    }

    /**
     * Find the parenthesis that closes the one just opened.
     *
     * Quoted strings are stepped over, because a parenthesis inside an
     * attribute — `title="a)b"` — is text rather than structure.
     *
     * @param string $source The source
     * @param int $from The offset just after the opening parenthesis
     * @return int|null The offset of the closing parenthesis
     */
    private function matchingParenthesis(string $source, int $from): ?int
    {
        $depth = 1;
        $length = strlen($source);
        $quote = null;

        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
