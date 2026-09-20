<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates controller skeleton files.
 */
final class ControllerGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/controllers');
        $filePath = $this->getFilePath('app/controllers', $name, 'Controller');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\app\controllers\BaseController;

final class {CLASS}Controller extends BaseController
{
    // Add your controller methods here
    // Example:
    // public function index(): string
    // {
    //     return $this->view('index', []);
    // }
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
