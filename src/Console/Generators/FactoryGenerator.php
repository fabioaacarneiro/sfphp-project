<?php

namespace SfphpProject\src\Console\Generators;

final class FactoryGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = $this->getFilePath('database/factories', $name . 'Factory');
        $modelName = str_replace('Factory', '', $name);

        $content = <<<'PHP'
<?php

namespace Database\Factories;

use SfPhp\Database\Factory;

class {CLASS}Factory extends Factory
{
    public function definition(): array
    {
        return [
            // Define factory attributes here
            // Example: 'name' => fake()->name(),
        ];
    }

    protected function model(): string
    {
        return \SfphpProject\app\Models\{MODEL}::class;
    }
}
PHP;

        $content = str_replace('{CLASS}', $modelName, $content);
        $content = str_replace('{MODEL}', $modelName, $content);

        file_put_contents($filePath, $content);

        return "Factory created: {$filePath}";
    }
}
