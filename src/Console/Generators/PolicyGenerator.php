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

        /*
         * Every ability denies until it is written. The stub used to answer
         * true everywhere, so registering it allowed everything; and it typed
         * the user as object, so a guest — who arrives as null — was a
         * TypeError instead of a refusal.
         */
        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Auth\Authenticatable;

/**
 * {CLASS}Policy decides who may do what with a {CLASS}.
 *
 * Register it once, at boot:
 *
 *     Gate::policy({CLASS}::class, {CLASS}Policy::class);
 *
 * A guest arrives as null. Each ability denies until it says otherwise.
 */
final class {CLASS}Policy
{
    public function view(?Authenticatable $user, object $resource): bool
    {
        return false;
    }

    public function create(?Authenticatable $user): bool
    {
        return false;
    }

    public function update(?Authenticatable $user, object $resource): bool
    {
        return false;
    }

    public function delete(?Authenticatable $user, object $resource): bool
    {
        return false;
    }
}
PHP;

        return $this->writeFile($filePath, str_replace(['{NAMESPACE}', '{CLASS}'], [$namespace, $name], $content));
    }
}
