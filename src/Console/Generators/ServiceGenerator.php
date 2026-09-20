<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates service skeleton files.
 */
final class ServiceGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/services');
        $filePath = $this->getFilePath('app/services', $name, 'Service');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Service for business logic and application operations.
 */
final class {CLASS}Service
{
    /**
     * Create a new {CLASS}Service instance.
     */
    public function __construct()
    {
        // Inject dependencies here
        // Example: private UserRepository $users
    }

    /**
     * Add your service methods here.
     *
     * Example:
     * public function process(array $data): void
     * {
     *     // Business logic
     * }
     */
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
