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
         * Extends the framework's Controller, which is in the package. It used
         * to extend a BaseController that belonged to the example application
         * and was not shipped, so every controller generated in somebody else's
         * project referenced a class that did not exist there.
         *
         * Extending is optional — an action returning a Response is the whole
         * contract — but generating it is the friendlier default: $this->view()
         * works in the file the moment it is written.
         */
        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Http\Controller;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class {CLASS}Controller extends Controller
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
        return $this->view('{ROUTE}/index', ['title' => '{CLASS}']);

        // JSON instead — or extend ApiController, which adds payload():
        // return Response::json(['message' => 'It works.']);
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
