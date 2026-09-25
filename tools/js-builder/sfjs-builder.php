<?php

/**
 * Builds SFJS: joins its parts into one bundle and minifies it.
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
 * containing either — `/[/]/`, `/a\/*b/` — would be damaged. A regex literal
 * containing a quote is damaged too, in the other direction: the quote is read
 * as the start of a string, and nothing after it is minified. SFJS's regex
 * literals hold neither, and the build checks its own output with node when
 * node is there, and that the minified file is smaller than two thirds of the
 * bundle, which is what catches it when that changes.
 */

/*
 * One bundle, from three sources. They were three published files, and the
 * two extensions only worked when a page loaded them after the core — a page
 * that got the order wrong, or forgot one, failed with a console message. The
 * parts stay separate to work on and are joined here, in this order, into the
 * one script a page includes.
 */
$sourceDirectory = __DIR__ . '/../../resources/assets/js/src';
$outputDirectory = __DIR__ . '/../../resources/assets/js';
$parts = ['core.js', 'stream.js', 'ui.js'];

$bundle = '';

foreach ($parts as $part) {
    $path = $sourceDirectory . '/' . $part;
    $js = is_file($path) ? file_get_contents($path) : false;

    if ($js === false) {
        fwrite(STDERR, "SFJS part not found at {$path}\n");
        exit(1);
    }

    $bundle .= rtrim($js) . "\n\n";
}

$bundle = rtrim($bundle) . "\n";
$minified = minifyJs($bundle);

/*
 * Comments are about half of the source. A minified file that kept most of
 * its size means the minifier lost its place — a quote inside a regex literal
 * reads as the start of a string — and shipped the rest unminified.
 */
if (strlen($minified) > strlen($bundle) * 2 / 3) {
    fwrite(STDERR, sprintf(
        "The minified SFJS is %d of %d bytes; the minifier lost its place, most likely at a regex literal with a quote in it.\n",
        strlen($minified),
        strlen($bundle)
    ));
    exit(1);
}

foreach (['sfjs.js' => $bundle, 'sfjs.min.js' => $minified] as $name => $contents) {
    if (file_put_contents($outputDirectory . '/' . $name, $contents) === false) {
        fwrite(STDERR, "Could not write {$outputDirectory}/{$name}\n");
        exit(1);
    }
}

/*
 * The minifier cannot parse JavaScript, so its output is checked by something
 * that can. node is not a dependency — the check runs only where it is
 * installed — but where it is, a minifier that broke the syntax fails the
 * build instead of shipping.
 */
$node = trim((string) shell_exec('command -v node 2>/dev/null'));

if ($node !== '') {
    foreach (['sfjs.js', 'sfjs.min.js'] as $name) {
        $output = [];
        $status = 0;
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($outputDirectory . '/' . $name) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            fwrite(STDERR, "{$name} is not valid JavaScript:\n" . implode("\n", $output) . "\n");
            exit(1);
        }
    }
}

printf(
    "✓ Generated: %s (%s bytes, from %s in %d parts)\n",
    realpath($outputDirectory . '/sfjs.min.js'),
    number_format(strlen($minified)),
    number_format(strlen($bundle)),
    count($parts)
);

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
