<?php

/**
 * Minifies SFJS.
 *
 * SFCSS shipped a .min.css and SFJS shipped nothing, which is the kind of gap
 * nobody notices until they look at the directory.
 *
 * This is deliberately a small minifier rather than a clever one. It removes
 * comments and collapses whitespace, and it does **not** rewrite tokens: no
 * shortening names, no dropping semicolons, no joining statements onto one
 * line. Those are where a minifier breaks a program, and the saving is not
 * worth owning a JavaScript parser in a framework that has no dependencies.
 *
 * What it cannot do is recognise a regular expression literal. `/` is treated
 * as the start of a comment only when followed by `/` or `*`, so a regex
 * containing either — `/[/]/`, `/a\/*b/` — would be damaged. SFJS contains no
 * regex literals today, and the build checks its own output with node when node
 * is there, which is what would catch it if that changed.
 */

$source = __DIR__ . '/../../resources/assets/js/sfjs.js';
$target = __DIR__ . '/../../resources/assets/js/sfjs.min.js';

if (!is_file($source)) {
    fwrite(STDERR, "SFJS not found at {$source}\n");
    exit(1);
}

$js = file_get_contents($source);

if ($js === false) {
    fwrite(STDERR, "Could not read {$source}\n");
    exit(1);
}

$minified = minifyJs($js);

if (file_put_contents($target, $minified) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}

printf("✓ Generated: %s (%s bytes, from %s)\n", $target, number_format(strlen($minified)), number_format(strlen($js)));

/**
 * Strip comments and needless whitespace from JavaScript.
 *
 * @param string $js The source
 * @return string The minified source
 */
function minifyJs(string $js): string
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
