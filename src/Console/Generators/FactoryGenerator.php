<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates factory classes.
 */
final class FactoryGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = $this->getFilePath('database/factories', $name, 'Factory');

        // The model's namespace is the project's, spelled the way make:model writes it.
        $model = $this->getNamespace('app/models') . '\\' . $name;

        $content = <<<'PHP'
<?php

namespace Database\Factories;

use SfphpProject\src\Database\Factory;

class {CLASS}Factory extends Factory
{
    /**
     * One row. This runs once per row, so a random value differs each time;
     * a closure is called per row with the factory and the row's index.
     */
    public function definition(): array
    {
        return [
            // 'title' => 'Example ' . bin2hex(random_bytes(3)),
            // 'position' => fn (Factory $factory, int $index): int => $index + 1,
        ];
    }

    protected function model(): string
    {
        return \{MODEL}::class;
    }
}
PHP;

        return $this->writeFile($filePath, str_replace(['{CLASS}', '{MODEL}'], [$name, $model], $content));
    }
}
