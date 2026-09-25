<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates controller skeleton files.
 */
final class ControllerGenerator extends GeneratorBase
{
    /** The view written for the action, when this call wrote one. */
    public ?string $view = null;

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

        $written = $this->writeFile($filePath, $content);

        /*
         * The action renders {route}/index, so the template is written too.
         * It used to be left out, and the controller the README walks through
         * answered its first request with "View product/index not found". An
         * existing template is never touched.
         */
        $view = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/app/resources/views/' . strtolower($name) . '/index.sfht';

        if (!is_file($view)) {
            @mkdir(dirname($view), 0755, true);

            $template = <<<'SFHT'
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('css/sfcss.min.css') }}">
</head>
<body>
    <main class="container py-8">
        <h1>{{ $title }}</h1>
        <p class="text-muted">Rendered by {CLASS}Controller::index(). Edit app/resources/views/{ROUTE}/index.sfht.</p>
    </main>
</body>
</html>
SFHT;

            if (@file_put_contents($view, str_replace(['{CLASS}', '{ROUTE}'], [$name, strtolower($name)], $template) . "\n") !== false) {
                $this->view = $view;
            }
        }

        return $written;
    }
}
