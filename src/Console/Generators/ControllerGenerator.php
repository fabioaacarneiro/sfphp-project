<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates controller skeleton files.
 */
final class ControllerGenerator extends GeneratorBase
{
    /** The view written for the action, when this call wrote one. */
    public ?string $view = null;

    /** Options passed from command line */
    private array $options = [];

    /**
     * Set generation options (e.g., --template=sfht, --no-view)
     *
     * @param array $options Options like ['template' => 'sfht'] or ['no-view' => true]
     * @return self
     */
    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

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

        // Check if view generation is disabled
        if ($this->options['no-view'] ?? false) {
            return $written;
        }

        // Default to SFHT unless specified otherwise
        $template = $this->options['template'] ?? 'sfht';

        if ($template === 'sfht') {
            $this->writeSfhtView($name);
        } elseif ($template === 'phpx') {
            $this->writePhpxComponent($name);
        }

        return $written;
    }

    /**
     * Write an SFHT view template
     */
    private function writeSfhtView(string $name): void
    {
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
    @sfcss
</head>
<body>
    <main class="container py-8">
        <h1>{{ $title }}</h1>
        <p class="text-muted">Rendered by {CLASS}Controller::index(). Edit app/resources/views/{ROUTE}/index.sfht.</p>
    </main>

    @sfjs
</body>
</html>
SFHT;

            if (@file_put_contents($view, str_replace(['{CLASS}', '{ROUTE}'], [$name, strtolower($name)], $template) . "\n") !== false) {
                $this->view = $view;
            }
        }
    }

    /**
     * Write a PHPX component
     */
    private function writePhpxComponent(string $name): void
    {
        $component = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/app/components/' . $name . '.phpx';

        if (!is_file($component)) {
            @mkdir(dirname($component), 0755, true);

            $template = <<<'PHPX'
<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * {CLASS} component
 */
function {CLASS}(string $title = '{CLASS}'): Sfht
{
    return Sfht(
        <div class="card">
            <div class="card-header">
                <h1 class="m-0">{{ $title }}</h1>
            </div>
            <div class="card-body">
                <p>Created by ./sfphp make:controller {CLASS} --template=phpx</p>
                <p>Edit app/components/{CLASS}.phpx to customize this component.</p>
            </div>
        </div>
    );
}
PHPX;

            if (@file_put_contents($component, str_replace(['{CLASS}'], [$name], $template) . "\n") !== false) {
                $this->view = $component;
            }
        }
    }
}
