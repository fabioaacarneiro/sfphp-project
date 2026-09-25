<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates seeder classes.
 */
final class SeederGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = $this->getFilePath('database/seeders', $name, 'Seeder');

        $content = <<<'PHP'
<?php

namespace Database\Seeders;

use SfphpProject\src\Database\Seeder;

class {CLASS}Seeder extends Seeder
{
    public function run(): void
    {
        // Rows to insert. With a factory for the model:
        //
        //     (new \Database\Factories\{CLASS}Factory())->count(10)->create();
        //
        // Run it with ./sfphp db:seed --class={CLASS}Seeder, or call it
        // from DatabaseSeeder so ./sfphp db:seed runs it too.
    }
}
PHP;

        return $this->writeFile($filePath, str_replace('{CLASS}', $name, $content));
    }
}
