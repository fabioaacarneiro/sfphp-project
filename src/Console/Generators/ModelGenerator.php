<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates model skeleton files.
 */
final class ModelGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/models');
        $filePath = $this->getFilePath('app/models', $name);
        $table = strtolower($name . 's');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Database;

/**
 * {CLASS} model for interacting with the database.
 */
final class {CLASS}
{
    /**
     * Get all records.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::table('{TABLE}')->get();
    }

    /**
     * Find a record by ID.
     *
     * @param int $id The record ID
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::table('{TABLE}')->where('id', $id)->first();
    }

    /**
     * Create a new record.
     *
     * @param array<string, mixed> $data The record data
     * @return int The inserted ID
     */
    public static function create(array $data): int
    {
        return Database::table('{TABLE}')->insert($data);
    }

    /**
     * Update a record.
     *
     * @param int $id The record ID
     * @param array<string, mixed> $data The data to update
     * @return int The number of affected rows
     */
    public static function update(int $id, array $data): int
    {
        return Database::table('{TABLE}')->where('id', $id)->update($data);
    }

    /**
     * Delete a record.
     *
     * @param int $id The record ID
     * @return int The number of affected rows
     */
    public static function delete(int $id): int
    {
        return Database::table('{TABLE}')->where('id', $id)->delete();
    }
}
PHP;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}', '{TABLE}'],
            [$namespace, $name, $table],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}
