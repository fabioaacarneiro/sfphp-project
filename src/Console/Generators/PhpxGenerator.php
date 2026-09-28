<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates PHPX component skeleton files.
 */
final class PhpxGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/components');
        $filePath = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/app/components/' . $name . '.phpx';

        @mkdir(dirname($filePath), 0755, true);

        $content = <<<'PHPX'
<?php

namespace {NAMESPACE};

use SfphpProject\src\View\Sfht;

/**
 * {CLASS} component — a reusable PHPX component.
 *
 * Usage in a controller or another component:
 *
 *     use function SfphpProject\app\components\{CLASS};
 *
 *     $html = {CLASS}('prop value');
 *     return Response::phpx($html);
 *
 * @param string $title The component title
 * @return Sfht The rendered component
 */
function {CLASS}(string $title = '{CLASS}'): Sfht
{
    return Sfht(
        <div class="card">
            <div class="card-header">
                <h2 class="m-0">{{ $title }}</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Created by <code>./sfphp make:phpx {CLASS}</code>
                </p>
                <p class="text-sm">
                    Edit <code>app/components/{CLASS}.phpx</code> to customize this component.
                </p>
            </div>
        </div>
    );
}
PHPX;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}'],
            [$namespace, $name],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}
