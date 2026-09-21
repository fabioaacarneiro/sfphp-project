<?php

/**
 * Checks that the English, Portuguese and Spanish documentation stay in step.
 *
 * The three versions are meant to be complete translations of one another, not
 * one real document and two summaries. Prose cannot be compared mechanically,
 * but structure can: a section added to one language and forgotten in another
 * shows up as a different heading count, and a code example added, dropped or
 * mislabelled shows up as a different fence sequence.
 *
 * This catches the two failures that actually happen when three files are
 * edited by hand: a section that exists in one language only, and a code block
 * whose fence says "php" in one version and "bash" in another.
 *
 * It also verifies every relative link and in-page anchor, because moving a
 * file into a language folder breaks links silently — markdown has no compiler
 * to complain.
 *
 *     php tools/docs-parity.php
 *
 * Exits 0 when the three agree, 1 otherwise.
 */

const DOCS_ROOT = __DIR__ . '/../docs';
const LANGUAGES = ['en', 'pt-BR', 'es'];
const DOCUMENTS = ['DOCUMENTATION', 'SFCSS', 'SFCSS_UTILITIES'];

/** The reference language: the other two are compared against it. */
const PRIMARY = 'en';

/**
 * Reduce a document to the shape a translation must preserve.
 *
 * Headings and table separators are counted rather than compared, since their
 * text is translated. Fence languages are compared in order, because those are
 * never translated: a `php` block is a `php` block in every version.
 *
 * @param string $path Absolute path to the markdown file
 * @return array{h2: int, h3: int, tables: int, fences: list<array{0: string, 1: int}>}
 */
function documentShape(string $path): array
{
    $h2 = 0;
    $h3 = 0;
    $tables = 0;
    $fences = [];
    $insideFence = false;

    foreach (file($path) as $number => $line) {
        if (preg_match('/^```(\w*)/', $line, $match) === 1) {
            if ($insideFence) {
                $insideFence = false;
                continue;
            }

            $fences[] = [$match[1] !== '' ? $match[1] : 'plain', $number + 1];
            $insideFence = true;
            continue;
        }

        if ($insideFence) {
            continue;
        }

        if (str_starts_with($line, '## ')) {
            $h2++;
        } elseif (str_starts_with($line, '### ')) {
            $h3++;
        } elseif (preg_match('/^\|[-: |]+\|$/', trim($line)) === 1) {
            $tables++;
        }
    }

    return ['h2' => $h2, 'h3' => $h3, 'tables' => $tables, 'fences' => $fences];
}

/**
 * Turn a heading into the anchor GitHub generates for it.
 *
 * @param string $heading The heading text, without the leading hashes
 * @return string The anchor, without the leading '#'
 */
function anchor(string $heading): string
{
    $text = strtolower(trim($heading));
    $text = str_replace(['`', '*', '_'], '', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);

    return preg_replace('/\s+/u', '-', trim($text));
}

/**
 * Collect every anchor a markdown file defines.
 *
 * @param string $path Absolute path to the markdown file
 * @return array<string, true> The anchors, as a set
 */
function anchorsOf(string $path): array
{
    $anchors = [];

    foreach (file($path) as $line) {
        if (preg_match('/^#{1,6} (.+)$/u', rtrim($line), $match) === 1) {
            $anchors[anchor($match[1])] = true;
        }
    }

    return $anchors;
}

$problems = [];

/*
 * Structure: every translation must have the same headings, tables and code
 * blocks as the primary language.
 */
foreach (DOCUMENTS as $document) {
    $shapes = [];

    foreach (LANGUAGES as $language) {
        $path = DOCS_ROOT . '/' . $language . '/' . $document . '.md';

        if (!is_file($path)) {
            $problems[] = sprintf('%s/%s.md does not exist.', $language, $document);
            continue;
        }

        $shapes[$language] = documentShape($path);
    }

    if (!isset($shapes[PRIMARY])) {
        continue;
    }

    $reference = $shapes[PRIMARY];

    foreach ($shapes as $language => $shape) {
        if ($language === PRIMARY) {
            continue;
        }

        foreach (['h2' => 'sections', 'h3' => 'subsections', 'tables' => 'tables'] as $key => $label) {
            if ($shape[$key] !== $reference[$key]) {
                $problems[] = sprintf(
                    '%s/%s.md has %d %s, English has %d.',
                    $language,
                    $document,
                    $shape[$key],
                    $label,
                    $reference[$key]
                );
            }
        }

        if (count($shape['fences']) !== count($reference['fences'])) {
            $problems[] = sprintf(
                '%s/%s.md has %d code blocks, English has %d.',
                $language,
                $document,
                count($shape['fences']),
                count($reference['fences'])
            );

            continue;
        }

        foreach ($shape['fences'] as $index => [$fence, $line]) {
            [$expected, $expectedLine] = $reference['fences'][$index];

            if ($fence !== $expected) {
                $problems[] = sprintf(
                    '%s/%s.md line %d: code block is "%s", English line %d has "%s".',
                    $language,
                    $document,
                    $line,
                    $fence,
                    $expectedLine,
                    $expected
                );
            }
        }
    }
}

/*
 * Links: a relative path that does not resolve, or an anchor no heading
 * produces, is a dead link the moment a file moves.
 */
$files = [
    __DIR__ . '/../README.md',
    __DIR__ . '/../README.pt-BR.md',
    __DIR__ . '/../README.es.md',
    DOCS_ROOT . '/README.md',
];

foreach (LANGUAGES as $language) {
    foreach (DOCUMENTS as $document) {
        $files[] = DOCS_ROOT . '/' . $language . '/' . $document . '.md';
    }
}

$anchors = [];

foreach ($files as $path) {
    if (is_file($path)) {
        $anchors[realpath($path)] = anchorsOf($path);
    }
}

foreach ($files as $path) {
    if (!is_file($path)) {
        $problems[] = sprintf('%s does not exist.', $path);
        continue;
    }

    $name = basename(dirname($path)) . '/' . basename($path);

    preg_match_all('/\]\(([^)\s]+)\)/', file_get_contents($path), $matches);

    foreach ($matches[1] as $link) {
        if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
            continue;
        }

        [$relative, $fragment] = array_pad(explode('#', $link, 2), 2, null);

        $target = $relative === ''
            ? realpath($path)
            : realpath(dirname($path) . '/' . $relative);

        if ($target === false) {
            $problems[] = sprintf('%s links to %s, which does not exist.', $name, $link);
            continue;
        }

        if ($fragment === null || $fragment === '' || !isset($anchors[$target])) {
            continue;
        }

        if (!isset($anchors[$target][$fragment])) {
            $problems[] = sprintf('%s links to %s, but no heading produces that anchor.', $name, $link);
        }
    }
}

if ($problems === []) {
    printf(
        "Documentation parity: %d documents × %d languages agree, every link resolves.\n",
        count(DOCUMENTS),
        count(LANGUAGES)
    );

    exit(0);
}

foreach ($problems as $problem) {
    echo '  ', $problem, PHP_EOL;
}

printf("\n%d problem(s).\n", count($problems));

exit(1);
