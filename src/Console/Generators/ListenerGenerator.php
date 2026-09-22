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
 * Does something when an event is fired.
 *
 * Register it where the application boots:
 *
 *     Dispatcher::listen(SomethingHappenedEvent::class, {CLASS}Listener::class);
 *
 * The class name is resolved through the container when the event fires, so
 * this may declare what it needs in a constructor and have it injected — and
 * nothing is built for an event that never happens.
 *
 * A listener that throws is logged and does not stop the others, because
 * dispatching is telling rather than asking. Work that the caller depends on
 * belongs in the caller, not here.
 */
final class {CLASS}Listener
{
    /**
     * Handle the event.
     *
     * @param object $event The event that was fired
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
