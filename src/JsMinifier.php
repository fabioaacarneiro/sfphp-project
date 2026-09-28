<?php

namespace SfphpProject\src;

/**
 * A small JavaScript minifier, for SFJS and for a project's own scripts.
 *
 * It removes comments and collapses whitespace, and it does **not** rewrite
 * tokens: no shortening names, no dropping semicolons, no joining statements
 * onto one line. Those are where a minifier breaks a program, and the saving
 * is not worth owning a JavaScript parser in a framework that has no
 * dependencies.
 *
 * What it cannot do is recognise a regular expression literal. `/` is treated
 * as the start of a comment only when followed by `/` or `*`, so a regex
 * containing either — `/[/]/`, `/a\/*b/` — would be damaged, and a regex
 * containing a quote is read as the start of a string. SFJS's own regex
 * literals hold neither. A project's scripts may, which is why what is built
 * from them is checked with node before it is used, and kept unminified when
 * it cannot be.
 */
final class JsMinifier
{
    /**
     * Strip comments and needless whitespace.
     *
     * @param string $js The source
     * @return string The minified source
     */
    public static function minify(string $js): string
    {
        $out = '';
        $length = strlen($js);
        $i = 0;

        while ($i < $length) {
            $char = $js[$i];
            $next = $i + 1 < $length ? $js[$i + 1] : '';

            // A comment, of either kind.
            if ($char === '/' && $next === '/') {
                while ($i < $length && $js[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                // A block comment between two tokens has to leave something behind,
                // or `a /* x */ b` becomes `ab`.
                $out .= ' ';

                continue;
            }

            // A string or a template literal is copied through untouched.
            if ($char === '"' || $char === "'" || $char === '`') {
                $out .= $char;
                $i++;

                while ($i < $length) {
                    $out .= $js[$i];

                    if ($js[$i] === '\\' && $i + 1 < $length) {
                        // An escaped character, including an escaped quote.
                        $out .= $js[$i + 1];
                        $i += 2;

                        continue;
                    }

                    if ($js[$i] === $char) {
                        $i++;
                        break;
                    }

                    $i++;
                }

                continue;
            }

            $out .= $char;
            $i++;
        }

        /*
         * Line by line rather than all at once, because joining lines is what makes
         * automatic semicolon insertion change the meaning of a program. Each line
         * is trimmed and its internal runs of whitespace collapsed; blank lines go.
         */
        $lines = [];

        foreach (explode("\n", $out) as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? '');

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether a file is valid JavaScript, asked of node.
     *
     * @param string $file The file
     * @return string|null|false Null when it is valid, the error when it is not,
     *                           false when there is no node here to ask
     */
    public static function check(string $file): string|null|false
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            return false;
        }

        $output = [];
        $status = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        return $status === 0 ? null : implode("\n", $output);
    }
}
