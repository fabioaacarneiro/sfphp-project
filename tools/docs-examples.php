<?php

/**
 * Checks that the documentation's PHP examples are PHP, and that what they
 * call on the framework exists.
 *
 * The documentation is the source of truth, and an example is the part of it
 * people copy. docs-parity.php keeps the three languages in the same shape;
 * this keeps their examples honest against the code:
 *
 * - every ```php block parses. A block may be a fragment on purpose — a
 *   method without its class, a chain that starts at "->", a slice of an
 *   array, a .phpx region with markup in it — so each is tried in the
 *   readings a fragment can have, and "[...]" or "/* ... *\/" placeholders
 *   are filled in first. A block that parses in none of them is reported;
 * - every Class::method( call on a framework class names a method that
 *   exists. A class the framework does not have — Post, OrderController — is
 *   the reader's own and is not checked.
 *
 *     php tools/docs-examples.php
 *
 * Exits 0 when every example passes, 1 otherwise.
 */

require __DIR__ . '/../vendor/autoload.php';

const DOCS = __DIR__ . '/../docs';
const LANGUAGES = ['en', 'pt-BR', 'es'];

/**
 * The framework's classes, by short name, from the source rather than by
 * loading it: including every file would run side effects this has no reason
 * to.
 *
 * @return array<string, list<string>>
 */
function frameworkClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            continue;
        }

        if (preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $found)) {
            foreach ($found[1] as $short) {
                $classes[$short][] = $namespace[1] . '\\' . $short;
            }
        }
    }

    return $classes;
}

/**
 * Every ```php block, with where it starts.
 *
 * @return list<array{file: string, line: int, code: string}>
 */
function phpBlocks(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $blocks = [];

    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/^```(\w*)/', $lines[$i], $fence) !== 1) {
            continue;
        }

        $start = $i + 1;
        $end = $start;

        while ($end < count($lines) && !str_starts_with($lines[$end], '```')) {
            $end++;
        }

        if ($fence[1] === 'php') {
            $blocks[] = ['file' => $path, 'line' => $start + 1, 'code' => implode("\n", array_slice($lines, $start, $end - $start))];
        }

        $i = $end;
    }

    return $blocks;
}

/**
 * Whether PHP parses this.
 */
function parses(string $code): bool
{
    $file = tempnam(sys_get_temp_dir(), 'sfphp-doc');
    file_put_contents($file, $code);
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    unlink($file);

    return $status === 0;
}

/**
 * The readings a documentation fragment can have.
 *
 * @return list<string>
 */
function readings(string $code): array
{
    $body = preg_replace('/^<\?php\s*/', '', $code) ?? $code;

    // Placeholders a reader fills in.
    $body = str_replace(['[...]', ', ...]', ', ...)', '(...)'], ['[]', ']', ')', '()'], $body);
    $body = preg_replace('/=>\s*\/\*[^*]*\*\//', '=> null', $body) ?? $body;

    $readings = ["<?php\n" . $body];

    if (str_contains($body, 'Sfht(')) {
        try {
            $readings[] = (new SfphpProject\src\View\Phpx())->compile("<?php\n" . $body);
        } catch (Throwable) {
            // Not a .phpx region after all; the other readings still apply.
        }
    }

    // A method, or several, with what comes before them — a use, a route — outside.
    if (preg_match('/^(.*?)^((?:public|protected|private)\s.*)$/sm', $body, $parts) === 1) {
        $readings[] = "<?php\n" . $parts[1] . "\nclass DocExample {\n" . $parts[2] . "\n}\n";
        $readings[] = "<?php\n" . $parts[1] . "\ninterface DocExample {\n" . $parts[2] . "\n}\n";
    }

    $readings[] = "<?php\nclass DocExample {\n" . $body . "\n}\n";
    // On a line of its own: the last line may end in a comment the ";" would join.
    $readings[] = "<?php\n\$query" . ltrim($body) . "\n;\n";
    $readings[] = "<?php\n\$list = [\n" . $body . "\n];\n";

    return $readings;
}

$classes = frameworkClasses();
$problems = [];
$checkedBlocks = 0;
$checkedCalls = 0;

foreach (LANGUAGES as $language) {
    foreach (glob(DOCS . '/' . $language . '/*.md') ?: [] as $path) {
        foreach (phpBlocks($path) as $block) {
            $checkedBlocks++;
            $where = $language . '/' . basename($path) . ' line ' . $block['line'];

            $parsed = false;

            foreach (readings($block['code']) as $reading) {
                if (parses($reading)) {
                    $parsed = true;
                    break;
                }
            }

            if (!$parsed) {
                $problems[] = $where . ': the example does not parse as PHP in any reading.';
            }

            preg_match_all('/\b([A-Z][A-Za-z0-9]+)::([a-zA-Z_]\w*)\s*\(/', $block['code'], $calls, PREG_SET_ORDER);

            foreach ($calls as [, $short, $method]) {
                if (!isset($classes[$short])) {
                    continue;
                }

                $checkedCalls++;
                $exists = false;

                foreach ($classes[$short] as $class) {
                    if ((class_exists($class) || interface_exists($class) || enum_exists($class)) && method_exists($class, $method)) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $problems[] = $where . ": {$short}::{$method}() does not exist.";
                }
            }
        }
    }
}

if ($problems !== []) {
    echo "Documentation examples:\n\n  " . implode("\n  ", $problems) . "\n\n" . count($problems) . " problem(s).\n";
    exit(1);
}

echo "Documentation examples: {$checkedBlocks} PHP blocks parse, {$checkedCalls} framework calls exist.\n";
exit(0);
