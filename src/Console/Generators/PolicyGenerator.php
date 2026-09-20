<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates authorization policy skeleton files.
 */
final class PolicyGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/policies');
        $filePath = $this->getFilePath('app/policies', $name, 'Policy');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Policy authorizes actions on a resource.
 */
final class {CLASS}Policy
{
    /**
     * Check if user can view the resource.
     *
     * @param object $user The authenticated user
     * @return bool
     */
    public function view(object $user): bool
    {
        return true;
    }

    /**
     * Check if user can create the resource.
     *
     * @param object $user The authenticated user
     * @return bool
     */
    public function create(object $user): bool
    {
        return true;
    }

    /**
     * Check if user can update the resource.
     *
     * @param object $user The authenticated user
     * @param object $resource The resource instance
     * @return bool
     */
    public function update(object $user, object $resource): bool
    {
        return true;
    }

    /**
     * Check if user can delete the resource.
     *
     * @param object $user The authenticated user
     * @param object $resource The resource instance
     * @return bool
     */
    public function delete(object $user, object $resource): bool
    {
        return true;
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
