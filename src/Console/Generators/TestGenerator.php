<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates test classes for `./sfphp test`.
 */
final class TestGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = $this->getFilePath('tests', $name, 'Test');

        /*
         * It used to be a class in a namespace no autoloader mapped, calling
         * $this->assertTrue() on a class with no parent, with nothing in the
         * project to run it. It extends the framework's TestCase now, and
         * `./sfphp test` loads the file itself, so no autoload entry is needed.
         */
        $content = <<<'PHP'
<?php

namespace Tests;

use SfphpProject\src\Testing\TestCase;

/**
 * {CLASS}Test. Run it with ./sfphp test, or ./sfphp test {CLASS}Test.
 */
final class {CLASS}Test extends TestCase
{
    public function testExample(): void
    {
        $this->assertSame(4, 2 + 2);
    }
}
PHP;

        return $this->writeFile($filePath, str_replace('{CLASS}', $name, $content));
    }
}
