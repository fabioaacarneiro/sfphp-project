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

        /*
         * No base class. This extended SfphpProject\app\controllers\
         * BaseController, which belongs to this repository's example
         * application and is not in the package — so every controller this
         * generated in somebody else's project referenced a class that does not
         * exist there.
         *
         * Nothing is lost: that base class was two methods that forwarded to
         * Response::view() and Response::redirect(), which any class can call.
         */
        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class {CLASS}Controller
{
    /**
     * Handle the request.
     *
     * An action returns a Response rather than echoing. That is what lets a
     * middleware wrap it, and what makes this testable without a browser.
     *
     * Register it in your route file:
     *
     *     Router::get('/{ROUTE}', '{CLASS}Controller', 'index');
     *
     * @param Request $request The incoming request
     * @return Response The response
     */
    public function index(Request $request): Response
    {
        return Response::json(['message' => 'It works.']);

        // A page instead:
        // return Response::view('{ROUTE}/index', ['title' => '{CLASS}']);
    }
}
PHP;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}', '{ROUTE}'],
            [$namespace, $name, strtolower($name)],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}
