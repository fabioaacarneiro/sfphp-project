<?php

namespace SfphpProject\src\Testing;

use Throwable;

/**
 * The base of a project's tests, for `./sfphp test`.
 *
 *     final class PostTest extends TestCase
 *     {
 *         public function testTitleIsTrimmed(): void
 *         {
 *             $this->assertSame('Hello', trim(' Hello '));
 *         }
 *     }
 *
 * Every public method whose name starts with "test" is run, each on a fresh
 * instance, with setUp() before it and tearDown() after. It is deliberately
 * small — a handful of assertions and a runner — so a project can test
 * without a dependency. A project that wants PHPUnit adds it to require-dev.
 */
abstract class TestCase
{
    /** Assertions made by the running test. */
    private int $assertions = 0;

    /**
     * Run before each test.
     */
    protected function setUp(): void
    {
    }

    /**
     * Run after each test, whether it passed or not.
     */
    protected function tearDown(): void
    {
    }

    /**
     * @internal Called by the runner.
     */
    public function runTest(string $method): int
    {
        $this->assertions = 0;
        $this->setUp();

        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }

        return $this->assertions;
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertSame(true, $value, $message);
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertSame(false, $value, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected != $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true) . ' to equal ' . var_export($actual, true) . '.');
        }
    }

    protected function assertCount(int $expected, countable|array $value, string $message = ''): void
    {
        $this->assertSame($expected, count($value), $message);
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . var_export($haystack, true) . ' does not contain ' . var_export($needle, true) . '.');
        }
    }

    /**
     * @param callable(): mixed $callback What should throw
     * @param class-string<Throwable> $class What it should throw
     */
    protected function assertThrows(callable $callback, string $class, string $message = ''): void
    {
        $this->assertions++;

        try {
            $callback();
        } catch (Throwable $throwable) {
            if ($throwable instanceof $class) {
                return;
            }

            throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ', got ' . $throwable::class . ': ' . $throwable->getMessage());
        }

        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ' to be thrown, and nothing was.');
    }
}
