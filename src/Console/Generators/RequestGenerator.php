<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates form request skeleton files.
 */
final class RequestGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/requests');
        $filePath = $this->getFilePath('app/requests', $name, 'Request');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Validator;
use SfphpProject\src\ValidationResult;

/**
 * {CLASS}Request validates incoming data for {CLASS} operations.
 */
final class {CLASS}Request
{
    /**
     * Get the validation rules.
     *
     * Rules are a pipe-separated string, and an argument follows a colon.
     * The available rules are: required, email, min:N, max:N, alpha,
     * alphanum and number.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            // 'name' => 'required|min:3|max:255',
            // 'email' => 'required|email',
        ];
    }

    /**
     * Validate the given data.
     *
     * @param array<string, mixed> $data The data to validate
     * @return ValidationResult
     */
    public static function validate(array $data): ValidationResult
    {
        return Validator::validate($data, self::rules());
    }

    /**
     * Check if the data is valid.
     *
     * @param array<string, mixed> $data The data to validate
     * @return bool
     */
    public static function passes(array $data): bool
    {
        return self::validate($data)->passes();
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
