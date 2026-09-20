<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates event class skeleton files.
 */
final class EventGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/events');
        $filePath = $this->getFilePath('app/events', $name, 'Event');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

/**
 * {CLASS}Event is fired when a specific action occurs.
 */
final class {CLASS}Event
{
    /**
     * Create an event.
     *
     * @param mixed $data The event payload
     */
    public function __construct(public mixed $data = null) {}
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
