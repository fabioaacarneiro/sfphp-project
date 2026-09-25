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
 * `sfht(` opens a region and the `)` that closes it is found by reading the
 * markup as markup: tags, quoted attributes, `{{ }}` expressions and directive
 * arguments are stepped over, and a `)` ends the region only outside every
 * element. So text is free to hold an apostrophe or a lone parenthesis. What
 * is between is handed to the SFHT compiler that already exists — so `{{ }}`,
 * `{!! !!}`, filters, `@if` and `@foreach` all work inside, and escaping is the
 * same everywhere.
 *
 * What the compiler emits is an immediately-invoked closure that buffers the
 * markup and returns it, called with the enclosing function's variables. Those
 * variables are the component's parameters, which is what makes the props a
 * contract rather than inherited scope.
 *
 * **The cost, stated plainly:** a .phpx file is not valid PHP, so Intelephense
 * cannot parse it and `php -l` cannot check it. What it can check is the file
 * this produces, which is why the compiled file keeps every line where the
 * author wrote it, inside a region and after it: an error in the generated PHP
 * points at the right line of the .phpx.
 */
final class Phpx
{
    /** The call that opens a markup region. */
    private const OPEN = 'sfht(';

    /** Elements that never have a closing tag, so never hold the region open. */
    private const VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Elements whose content is text up to their closing tag, never markup. */
    private const RAW_TEXT = ['script', 'style', 'textarea', 'title'];

    private Compiler $compiler;

    /**
     * Create the compiler.
     *
     * @param Compiler|null $compiler The SFHT compiler, or null for a new one
     */
    public function __construct(?Compiler $compiler = null)
    {
        $this->compiler = $compiler ?? Compiler::forComponents();
    }

    /**
     * Compile a .phpx source into PHP.
     *
     * @param string $source The .phpx source
     * @return string Valid PHP
     * @throws RuntimeException When a markup region is never closed or does not compile
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
            $line = substr_count($source, "\n", 0, $start) + 1;
            $unclosed = null;
            $close = $this->regionEnd($source, $open, $unclosed);

            if ($close === null) {
                /*
                 * An element still open at the end is almost always the
                 * reason: the region's own ) was read as text inside it. Naming
                 * the element points at the fix instead of at the symptom.
                 */
                $reason = $unclosed === null ? '' : sprintf(
                    '; <%s> on line %d is still open, so the ) after it was read as its text',
                    $unclosed[0],
                    substr_count($source, "\n", 0, $unclosed[1]) + 1
                );

                throw new RuntimeException(sprintf(
                    'A markup region opened at line %d is never closed%s.',
                    $line,
                    $reason
                ));
            }

            $markup = substr($source, $open, $close - $open);

            $out .= substr($source, $offset, $start - $offset);
            $out .= $this->region($markup, $line);

            $offset = $close + 1;
        }

        return $out . substr($source, $offset);
    }

    /**
     * Compile one markup region into an expression.
     *
     * @param string $markup The markup between the parentheses
     * @param int $line The line of the .phpx the region opens on
     * @return string A PHP expression producing the rendered string
     * @throws RuntimeException When the markup does not compile, naming the .phpx line
     */
    private function region(string $markup, int $line): string
    {
        $body = trim($markup);
        $leading = substr_count(substr($markup, 0, strlen($markup) - strlen(ltrim($markup))), "\n");

        try {
            $compiled = $this->compiler->compile($body);
        } catch (RuntimeException $exception) {
            /*
             * The SFHT compiler counts lines from the start of the markup it
             * was given; the author counts them from the top of the .phpx.
             * Every "line N" in the message is moved to the author's count.
             */
            $first = $line + $leading - 1;
            $message = preg_replace_callback(
                '/\bline (\d+)/',
                static fn (array $match): string => 'line ' . ((int) $match[1] + $first),
                $exception->getMessage()
            ) ?? $exception->getMessage();

            throw new RuntimeException($message, 0, $exception);
        }

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
         *
         * No ?> around it: the SFHT compiler emits PHP statements, not HTML
         * with tags. Wrapping them in HTML context put `echo ...;` where the
         * parser expected text, which is the first thing this got wrong.
         *
         * The newlines that preceded the markup go first, so its first line
         * compiles onto the line it was written on. The component compiler
         * keeps every line after that where it was, and whatever is still
         * missing at the end — the whitespace before the closing ) — is padded
         * back, so the code after the region keeps its lines too.
         */
        $expression = str_repeat("\n", $leading)
            . '(static function (array $__props): \\SfphpProject\\src\\View\\Sfht { '
            . 'extract($__props); ob_start(); '
            . $compiled
            . ' return new \\SfphpProject\\src\\View\\Sfht((string) ob_get_clean()); })(get_defined_vars())';

        $lost = substr_count($markup, "\n") - substr_count($expression, "\n");

        return $expression . str_repeat("\n", max(0, $lost));
    }

    /**
     * Find the parenthesis that closes a region, by reading the markup.
     *
     * This used to count parentheses and step over quotes, the way PHP code is
     * read. Markup is not PHP code: an apostrophe in "Don't" opened a quote
     * that never closed, and the ")" in "Step 1) open" closed the region in
     * the middle of a paragraph. So the markup's own structure decides.
     * Comments, `{{ }}` and `{!! !!}` expressions, directive arguments and
     * tags — with their quoted attributes — are stepped over whole, and the
     * elements opened are tracked. Text inside an element is text, whatever it
     * holds; only outside every element does a parenthesis count, balanced
     * as before, and the one that balances the opening closes the region.
     *
     * @param string $source The source
     * @param int $from The offset just after the opening parenthesis
     * @param array{0: string, 1: int}|null $unclosed Set to the innermost element
     *                                                still open, when the region never closes
     * @return int|null The offset of the closing parenthesis
     */
    private function regionEnd(string $source, int $from, ?array &$unclosed = null): ?int
    {
        $length = strlen($source);
        $depth = 1;

        /** @var list<array{0: string, 1: int}> $open */
        $open = [];

        $i = $from;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === '{' || $char === '@') {
                $skip = $this->skipSyntax($source, $i);

                if ($skip === null) {
                    return null;
                }

                if ($skip > $i) {
                    $i = $skip;

                    continue;
                }
            }

            if ($char === '<') {
                if (substr_compare($source, '<!--', $i, 4) === 0) {
                    $end = strpos($source, '-->', $i + 4);

                    if ($end === false) {
                        return null;
                    }

                    $i = $end + 3;

                    continue;
                }

                if (preg_match('/\G<(\/?)([A-Za-z][A-Za-z0-9:-]*)|\G<[!?]/', $source, $match, 0, $i) === 1) {
                    $end = $this->tagEnd($source, $i + strlen($match[0]));

                    if ($end === null) {
                        return null;
                    }

                    $name = strtolower($match[2] ?? '');

                    if ($name === '') {
                        // <!DOCTYPE html> and the like: a tag, never an element.
                        $i = $end + 1;
                    } elseif ($match[1] === '/') {
                        /*
                         * A closing tag closes its element and any left open
                         * inside it, the way HTML treats an <li> without </li>.
                         * One that matches nothing open is ignored.
                         */
                        for ($k = count($open) - 1; $k >= 0; $k--) {
                            if ($open[$k][0] === $name) {
                                $open = array_slice($open, 0, $k);

                                break;
                            }
                        }

                        $i = $end + 1;
                    } elseif ($source[$end - 1] === '/' || in_array($name, self::VOID, true)) {
                        $i = $end + 1;
                    } else {
                        $open[] = [$name, $i];
                        $i = $end + 1;

                        if (in_array($name, self::RAW_TEXT, true)) {
                            // A script's "if (a < b)" is its text, not markup.
                            $close = stripos($source, '</' . $name, $i);

                            if ($close === false) {
                                $unclosed = end($open);

                                return null;
                            }

                            $i = $close;
                        }
                    }

                    continue;
                }
            }

            if ($open === [] && $char === '(') {
                $depth++;
            } elseif ($open === [] && $char === ')' && --$depth === 0) {
                return $i;
            }

            $i++;
        }

        $unclosed = $open === [] ? null : end($open);

        return null;
    }

    /**
     * Step over the SFHT syntax starting at an offset, if any starts there.
     *
     * @param string $source The source
     * @param int $i The offset of a "{" or "@"
     * @return int|null The offset just past it, the same offset when nothing
     *                  starts there, or null when it is never closed
     */
    private function skipSyntax(string $source, int $i): ?int
    {
        foreach (['{{--' => '--}}', '{!!' => '!!}', '{{' => '}}'] as $opener => $closer) {
            if (substr_compare($source, $opener, $i, strlen($opener)) === 0) {
                $end = strpos($source, $closer, $i + strlen($opener));

                return $end === false ? null : $end + strlen($closer);
            }
        }

        /*
         * Only a directive the parser knows is syntax, as it is to the
         * parser: an "@click" in an attribute or an address in the text is
         * left to be read as what it is.
         */
        if (
            $source[$i] !== '@'
            || preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/', $source, $match, 0, $i) !== 1
            || !in_array($match[1], Parser::DIRECTIVES, true)
        ) {
            return $i;
        }

        $after = $i + strlen($match[0]);

        if ($match[1] === 'php') {
            $end = strpos($source, '@endphp', $after);

            return $end === false ? null : $end + strlen('@endphp');
        }

        $cursor = $after;

        while ($cursor < strlen($source) && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
            $cursor++;
        }

        if (($source[$cursor] ?? '') !== '(') {
            return $after;
        }

        // A directive's arguments are PHP, so here quotes and nesting do count.
        $depth = 0;
        $quote = null;

        for ($k = $cursor; $k < strlen($source); $k++) {
            $char = $source[$k];

            if ($quote !== null) {
                if ($char === '\\') {
                    $k++;
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
                return $k + 1;
            }
        }

        return null;
    }

    /**
     * Find the ">" that ends a tag.
     *
     * Quoted attribute values are stepped over, so `title="a > b"` and
     * `title="a)b"` are values; so are expressions and directive arguments,
     * which may hold quotes of their own: `class="{{ $on ? "a" : "b" }}"`.
     *
     * @param string $source The source
     * @param int $from The offset just after the tag name
     * @return int|null The offset of the ">", or null when there is none
     */
    private function tagEnd(string $source, int $from): ?int
    {
        $length = strlen($source);
        $quote = null;

        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '{' || ($char === '@' && $quote === null)) {
                $skip = $this->skipSyntax($source, $i);

                if ($skip === null) {
                    return null;
                }

                if ($skip > $i) {
                    $i = $skip - 1;

                    continue;
                }
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i;
            }
        }

        return null;
    }
}
