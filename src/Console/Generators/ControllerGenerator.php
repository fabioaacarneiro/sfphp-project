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
         * No base class, because there is nothing to inherit: Response is a
         * factory, so view(), redirect(), route() and back() are all reachable
         * from any class. This used to extend a BaseController that lived in
         * the example application and was not shipped, so every controller
         * generated in somebody else's project named a class that did not exist
         * there.
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
     *     use {NAMESPACE}\{CLASS}Controller;
     *
     *     Router::get('/{ROUTE}', [{CLASS}Controller::class, 'index']);
     *
     * @param Request $request The incoming request
     * @return Response The response
     */
    public function index(Request $request): Response
    {
        return Response::sfht('{ROUTE}/index', ['title' => '{CLASS}']);

        // JSON instead:
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
