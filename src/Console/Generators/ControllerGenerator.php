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
        return Response::html('<h1>{CLASS}</h1>');

        /*
         * The page, once there is one to render:
         *
         *   A template — ./sfphp make:sfht {CLASS}, which writes
         *   app/resources/views/{ROUTE}/index.sfht:
         *
         *     return Response::sfht('{ROUTE}/index', ['title' => '{CLASS}']);
         *
         *   A component — ./sfphp make:phpx {CLASS}Page, which writes
         *   app/components/{CLASS}Page.phpx (import it with use function):
         *
         *     return Response::phpx({CLASS}Page());
         *
         *   JSON:
         *
         *     return Response::json(['message' => 'It works.']);
         */
    }
}
PHP;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}', '{ROUTE}'],
            [$namespace, $name, strtolower($name)],
            $content
        );

        /*
         * Only the controller. Which kind of page an action answers with — a
         * template, a component, JSON — is the author's call, and make:sfht and
         * make:phpx write each; a controller that chose one for them wrote
         * files they then had to find and delete. The action answers on its
         * own, so the first request works before any page exists.
         */
        return $this->writeFile($filePath, $content);
    }
}
