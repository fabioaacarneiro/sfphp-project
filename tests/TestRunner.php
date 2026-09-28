<?php

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;

    /** @var list<string> */
    private array $failures = [];

    private ?string $filter = null;
    private string $file = '';

    /**
     * Run only the tests whose name, or the name of whose file, contains this.
     *
     * @param string $filter Compared without case
     * @return void
     */
    public function filter(string $filter): void
    {
        $this->filter = $filter === '' ? null : strtolower($filter);
    }

    /**
     * Say which file the next tests come from.
     *
     * @param string $file Its name, without .php
     * @return void
     */
    public function file(string $file): void
    {
        $this->file = $file;
    }

    public function run(string $name, callable $test): void
    {
        if ($this->filter !== null
            && !str_contains(strtolower($name), $this->filter)
            && !str_contains(strtolower($this->file), $this->filter)) {
            $this->skipped++;

            return;
        }

        /*
         * Each result is said as it happens, on STDERR: a suite that is silent
         * until it is done looks the same as one that is stuck. Not on STDOUT,
         * because some tests emit a response, and PHP refuses to send headers
         * once anything has been printed.
         */
        /*
         * What a test prints is held and passed on to STDERR afterwards. A test
         * that printed — a command's usage line, a warning — used to make the
         * next one that emits a response fail with "output already started",
         * or not, depending on the php.ini: CI passed, a Docker image without
         * one did not. Buffered, every test starts with nothing sent.
         */
        $level = ob_get_level();
        ob_start();

        try {
            $test();
            $this->passed++;
            $line = "PASS $name";
        } catch (Throwable $throwable) {
            $this->failed++;
            $line = "FAIL $name: {$throwable->getMessage()}";
            $this->failures[] = ($this->file !== '' ? "[{$this->file}] " : '') . $line;
        } finally {
            $printed = '';

            while (ob_get_level() > $level) {
                $printed = ob_get_clean() . $printed;
            }

            if ($printed !== '') {
                fwrite(STDERR, $printed . (str_ends_with($printed, "\n") ? '' : "\n"));
            }
        }

        fwrite(STDERR, $line . "\n");
    }

    public function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Expected ' . var_export($expected, true)
                . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }

    public function assertTrue(bool $value): void
    {
        if (!$value) {
            throw new RuntimeException('Expected true.');
        }
    }

    public function assertThrows(callable $callback, string $class): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            if ($throwable instanceof $class) {
                return;
            }

            throw new RuntimeException(
                "Expected $class but received " . $throwable::class . '.'
            );
        }

        throw new RuntimeException("Expected $class to be thrown.");
    }

    public function finish(): never
    {
        // The failures again, together, with the file each came from, so a long run does not bury them.
        if ($this->failures !== []) {
            echo "\n" . implode("\n", $this->failures) . "\n";
        }

        $summary = "{$this->passed} passed, {$this->failed} failed";

        if ($this->skipped > 0) {
            $summary .= ", {$this->skipped} not matching the filter";
        }

        echo $summary . "\n";
        exit($this->failed === 0 && $this->passed > 0 ? 0 : 1);
    }
}
