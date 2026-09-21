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

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * {CLASS}Middleware handles request processing.
 */
final class {CLASS}Middleware implements Middleware
{
    /**
     * Handle the request.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        // Runs on the way in. Return a Response here to stop the pipeline
        // without reaching the controller.

        $response = $next($request);

        // Runs on the way out, with the response in hand.

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
