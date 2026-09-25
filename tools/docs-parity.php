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
// Every document in the language folders. The list used to stop at the first
// three, so the streaming, PWA, async and .phpx guides drifted unchecked — one
// was a third shorter in Spanish, two existed only in English.
const DOCUMENTS = ['DOCUMENTATION', 'SFCSS', 'SFCSS_UTILITIES', 'STREAMING', 'PWA_GUIDE', 'ASYNC', 'PHPX_COMPONENTS'];

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
    /*
     * GitHub's rule: lower-case, drop everything that is not a letter, digit,
     * space, hyphen or underscore, then turn each space into a hyphen — each
     * one, so "Icons & Installation" becomes "icons--installation". Collapsing
     * the spaces reported working links as broken.
     */
    $text = trim($heading);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    $text = str_replace(['`', '*'], '', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);

    return str_replace(' ', '-', $text);
}

/**
 * Collect every anchor a markdown file defines.
 *
 * @param string $path Absolute path to the markdown file
 * @return array<string, true> The anchors, as a set
 */
function anchorsOf(string $path): array
{
    return array_fill_keys(array_keys(headingPositions($path)), true);
}

/**
 * Every anchor a markdown file defines, with the position of its heading.
 *
 * A heading used twice gets "-1", "-2" and so on after the first, as GitHub
 * does; and a line inside a code block is not a heading, whatever it starts
 * with — "# a comment" in a bash block defined an anchor before.
 *
 * @param string $path Absolute path to the markdown file
 * @return array<string, int> Anchor => index of its heading in the file
 */
function headingPositions(string $path): array
{
    $anchors = [];
    $inFence = false;
    $index = 0;

    foreach (file($path) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
            $inFence = !$inFence;

            continue;
        }

        if ($inFence || preg_match('/^#{1,6} (.+)$/u', rtrim($line), $match) !== 1) {
            continue;
        }

        $base = anchor($match[1]);
        $candidate = $base;

        for ($n = 1; isset($anchors[$candidate]); $n++) {
            $candidate = $base . '-' . $n;
        }

        $anchors[$candidate] = $index++;
    }

    return $anchors;
}

/**
 * The in-page links of a file, in order, as the position of the heading each reaches.
 *
 * @param string $path Absolute path to the markdown file
 * @return list<int|null> One entry per "#…" link; null when no heading produces it
 */
function inPageTargets(string $path): array
{
    $positions = headingPositions($path);
    preg_match_all('/\]\((#[^)\s]+)\)/', (string) file_get_contents($path), $matches);

    return array_map(static fn (string $link): ?int => $positions[substr($link, 1)] ?? null, $matches[1]);
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
 * The READMEs are held to the same standard. They were checked for links
 * only, so the Portuguese and Spanish ones stayed a quarter of the English
 * one's length without anything noticing.
 */
$readme = documentShape(__DIR__ . '/../README.md');

foreach (['pt-BR' => 'README.pt-BR.md', 'es' => 'README.es.md'] as $language => $file) {
    $path = __DIR__ . '/../' . $file;

    if (!is_file($path)) {
        $problems[] = sprintf('%s does not exist.', $file);
        continue;
    }

    $shape = documentShape($path);

    foreach (['h2' => 'sections', 'h3' => 'subsections', 'tables' => 'tables'] as $key => $label) {
        if ($shape[$key] !== $readme[$key]) {
            $problems[] = sprintf('%s has %d %s, README.md has %d.', $file, $shape[$key], $label, $readme[$key]);
        }
    }

    if (count($shape['fences']) !== count($readme['fences'])) {
        $problems[] = sprintf('%s has %d code blocks, README.md has %d.', $file, count($shape['fences']), count($readme['fences']));
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

/*
 * Where a link lands. An anchor that exists can still be the wrong one: two
 * Spanish headings both translated as "Registro" sent every link to Logging to
 * the middleware subsection that came first. Each in-page link of a
 * translation has to reach the heading at the same position as the English
 * link it translates.
 */
foreach (DOCUMENTS as $document) {
    $english = DOCS_ROOT . '/' . PRIMARY . '/' . $document . '.md';

    if (!is_file($english)) {
        continue;
    }

    $reference = inPageTargets($english);

    foreach (LANGUAGES as $language) {
        $path = DOCS_ROOT . '/' . $language . '/' . $document . '.md';

        if ($language === PRIMARY || !is_file($path)) {
            continue;
        }

        $targets = inPageTargets($path);

        if (count($targets) !== count($reference)) {
            $problems[] = sprintf('%s/%s.md has %d in-page links, English has %d.', $language, $document, count($targets), count($reference));

            continue;
        }

        foreach ($targets as $i => $position) {
            if ($position !== null && $reference[$i] !== null && $position !== $reference[$i]) {
                $problems[] = sprintf(
                    '%s/%s.md: in-page link %d reaches heading %d, the English one reaches heading %d.',
                    $language,
                    $document,
                    $i + 1,
                    $position + 1,
                    $reference[$i] + 1
                );
            }
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
