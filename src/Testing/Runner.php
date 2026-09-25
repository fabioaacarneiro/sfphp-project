<?php

namespace SfphpProject\src\Testing;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Finds the TestCase classes in a directory and runs them.
 */
final class Runner
{
    /** @var list<string> What failed, one line each */
    private array $failures = [];

    private int $passed = 0;

    /**
     * Run every *Test.php under a directory.
     *
     * @param string $directory Where the tests are
     * @param string|null $filter Only run tests whose class or method name contains this
     * @param callable(string): void $write Where progress goes
     * @return bool Whether every test passed
     */
    public function run(string $directory, ?string $filter, callable $write): bool
    {
        $before = get_declared_classes();

        foreach ($this->files($directory) as $file) {
            require_once $file;
        }

        $classes = array_filter(
            array_diff(get_declared_classes(), $before),
            static fn (string $class): bool => is_subclass_of($class, TestCase::class) && !(new ReflectionClass($class))->isAbstract()
        );

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getName(), 'test') || $method->isStatic()) {
                    continue;
                }

                $label = $class . '::' . $method->getName();

                if ($filter !== null && !str_contains($label, $filter)) {
                    continue;
                }

                try {
                    (new $class())->runTest($method->getName());
                    $this->passed++;
                    $write('PASS ' . $label);
                } catch (Throwable $throwable) {
                    $this->failures[] = $label;
                    $write('FAIL ' . $label . ': ' . $throwable->getMessage());
                }
            }
        }

        $write('');
        $write(sprintf('%d passed, %d failed', $this->passed, count($this->failures)));

        return $this->failures === [];
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
