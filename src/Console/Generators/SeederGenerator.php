<?php

namespace SfphpProject\src\Console\Generators;

final class SeederGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = $this->getFilePath('database/seeders', $name);

        $content = <<<'PHP'
<?php

namespace Database\Seeders;

use SfPhp\Database\Seeder;

class {CLASS} extends Seeder
{
    public function run(): void
    {
        // Add seeding logic here
        // Example: User::factory()->count(10)->create();
    }
}
PHP;

        $content = str_replace('{CLASS}', $name, $content);

        file_put_contents($filePath, $content);

        return "Seeder created: {$filePath}";
    }
}
