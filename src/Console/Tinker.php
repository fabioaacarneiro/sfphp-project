<?php

namespace SfphpProject\src\Console;

/**
 * Interactive PHP shell (REPL) for framework exploration.
 */
final class Tinker
{
    /**
     * Create a tinker session.
     *
     * @param string $rootPath The project root path
     */
    public function __construct(private string $rootPath) {}

    /**
     * Start the interactive shell.
     *
     * @return void
     */
    public function start(): void
    {
        $this->printWelcome();

        while (true) {
            $input = readline('>>> ');

            if ($input === null || $input === 'exit' || $input === 'quit') {
                $this->printGoodbye();
                break;
            }

            if (trim($input) === '') {
                continue;
            }

            readline_add_history($input);

            try {
                $this->execute($input);
            } catch (Throwable $e) {
                fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
            }
        }
    }

    /**
     * Execute code in the shell.
     *
     * @param string $code The PHP code to execute
     * @return void
     */
    private function execute(string $code): void
    {
        $closure = static function () use ($code) {
            return eval('return ' . $code . ';');
        };

        try {
            $result = $closure();

            if ($result !== null) {
                var_dump($result);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Print welcome message.
     *
     * @return void
     */
    private function printWelcome(): void
    {
        fwrite(STDOUT, "SFPHP Tinker (Interactive Shell)\n");
        fwrite(STDOUT, "Type 'exit' or 'quit' to exit\n\n");
    }

    /**
     * Print goodbye message.
     *
     * @return void
     */
    private function printGoodbye(): void
    {
        fwrite(STDOUT, "\nGoodbye!\n");
    }
}
