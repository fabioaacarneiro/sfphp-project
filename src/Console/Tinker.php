<?php

namespace SfphpProject\src\Console;

use ParseError;
use Throwable;

/**
 * Interactive PHP shell (REPL) for framework exploration.
 *
 *     >>> $user = User::find(1)
 *     >>> $user->name
 *     >>> echo strtoupper($user->name);
 *
 * Variables live for the whole session. A line is tried as an expression first
 * and its value printed; a line that is not one — echo, foreach, a function
 * declaration — runs as a statement. An error is printed and the session goes
 * on.
 */
final class Tinker
{
    /** @var array<string, mixed> The variables the session has defined so far */
    private array $variables = [];

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
            $input = $this->read('>>> ');

            if ($input === null || trim($input) === 'exit' || trim($input) === 'quit') {
                $this->printGoodbye();
                break;
            }

            if (trim($input) === '') {
                continue;
            }

            if (function_exists('readline_add_history')) {
                readline_add_history($input);
            }

            $this->execute($input);
        }
    }

    /**
     * Run one line and print what it produced.
     *
     * @internal Public for the tests.
     * @param string $code The PHP code
     * @return void
     */
    public function execute(string $code): void
    {
        $code = trim($code);

        /*
         * Throwable was caught here without being imported, inside this
         * namespace — so the catch named a class that does not exist, caught
         * nothing, and the first typo or failed query ended the session.
         */
        try {
            [$result, $isValue] = $this->evaluate($code);

            if ($isValue && $result !== null) {
                var_dump($result);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Evaluate a line with the session's variables in scope.
     *
     * @param string $code The line
     * @return array{0: mixed, 1: bool} The value, and whether the line was an expression
     */
    private function evaluate(string $code): array
    {
        $expression = rtrim($code, "; \t");

        try {
            return [$this->run('return ' . $expression . ';'), true];
        } catch (ParseError) {
            // Not an expression: echo, a loop, a declaration. Run it as written.
            return [$this->run(str_ends_with($code, ';') || str_ends_with($code, '}') ? $code : $code . ';'), false];
        }
    }

    /**
     * Run code in a scope that keeps its variables between lines.
     *
     * Every line used to run in a closure of its own, so "$x = 1" was gone
     * by the next prompt.
     *
     * @param string $__code The code
     * @return mixed What it returned
     */
    private function run(string $__code): mixed
    {
        extract($this->variables, EXTR_SKIP);

        try {
            return eval($__code);
        } finally {
            $this->variables = array_diff_key(get_defined_vars(), ['__code' => true, 'this' => true]);
        }
    }

    /**
     * Read one line, with readline when the extension is there.
     *
     * @param string $prompt The prompt
     * @return string|null The line, or null at the end of the input
     */
    private function read(string $prompt): ?string
    {
        if (function_exists('readline') && stream_isatty(STDIN)) {
            $line = readline($prompt);

            return $line === false ? null : $line;
        }

        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);

        return $line === false ? null : rtrim($line, "\r\n");
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
