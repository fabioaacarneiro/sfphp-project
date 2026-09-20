<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates middleware skeleton files.
 */
final class MiddlewareGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/middleware');
        $filePath = $this->getFilePath('app/middleware', $name, 'Middleware');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Middleware handles request processing.
 */
final class {CLASS}Middleware
{
    /**
     * Handle the request.
     *
     * @param callable $next The next middleware handler
     * @return mixed
     */
    public function handle(callable $next): mixed
    {
        // Before request processing

        $response = $next();

        // After request processing

        return $response;
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
