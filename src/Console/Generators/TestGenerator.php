<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates test class skeleton files.
 */
final class TestGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('tests');
        $filePath = $this->getFilePath('tests', $name, 'Test');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Test covers the {CLASS} functionality.
 */
final class {CLASS}Test
{
    public function testExample(): void
    {
        $this->assertTrue(true);
    }
}
PHP;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}'],
            [$namespace, $name],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}
