<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates SFHT view skeleton files.
 */
final class SfhtGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $filePath = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/app/resources/views/' . strtolower($name) . '/index.sfht';

        @mkdir(dirname($filePath), 0755, true);

        $content = <<<'SFHT'
@* A view created by ./sfphp make:sfht {NAME} *@

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? '{NAME}' }}</title>
    <link rel="stylesheet" href="{{ asset('css/sfcss.min.css') }}">
</head>
<body>
    <main class="container py-8">
        <h1>{{ $title ?? '{NAME}' }}</h1>
        <p class="text-muted">Rendered by app/resources/views/{ROUTE}/index.sfht</p>

        @* Add your content here *@
    </main>
</body>
</html>
SFHT;

        $content = str_replace(
            ['{NAME}', '{ROUTE}'],
            [$name, strtolower($name)],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}
