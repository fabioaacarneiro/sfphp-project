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
 * Something that happened, which other parts of the application may care about.
 *
 * Fire it with:
 *
 *     Dispatcher::dispatch(new {CLASS}Event($whatever));
 *
 * An event is a plain object carrying what a listener needs to do its work.
 * Give it properties with names, rather than a generic payload: a listener
 * reading $event->order is clearer than one reading $event->data['order'], and
 * the constructor then says what this event is about.
 */
final class {CLASS}Event
{
    /**
     * Create the event.
     *
     * @param mixed $data What happened
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
