<?php

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $messages = [];

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            $this->messages[] = "PASS $name";
        } catch (Throwable $throwable) {
            $this->failed++;
            $this->messages[] = "FAIL $name: {$throwable->getMessage()}";
        }
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
        foreach ($this->messages as $message) {
            echo $message . "\n";
        }

        echo "{$this->passed} passed, {$this->failed} failed\n";
        exit($this->failed === 0 ? 0 : 1);
    }
}
