<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates event listener skeleton files.
 */
final class ListenerGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/listeners');
        $filePath = $this->getFilePath('app/listeners', $name, 'Listener');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Listener handles an event.
 */
final class {CLASS}Listener
{
    /**
     * Handle the event.
     *
     * @param object $event The event instance
     * @return void
     */
    public function handle(object $event): void
    {
        // Handle the event
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
