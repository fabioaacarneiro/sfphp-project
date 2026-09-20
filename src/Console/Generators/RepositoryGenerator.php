<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates repository skeleton files.
 */
final class RepositoryGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/repositories');
        $filePath = $this->getFilePath('app/repositories', $name, 'Repository');
        $table = strtolower($name . 's');

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Database;

/**
 * {CLASS}Repository for data access and business logic.
 */
final class {CLASS}Repository
{
    /**
     * The table name.
     */
    private const TABLE = '{TABLE}';

    /**
     * Get all records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return Database::table(self::TABLE)->get();
    }

    /**
     * Find a record by ID.
     *
     * @param int $id The record ID
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return Database::table(self::TABLE)->where('id', $id)->first();
    }

    /**
     * Create a new record.
     *
     * @param array<string, mixed> $data The record data
     * @return int The inserted ID
     */
    public function create(array $data): int
    {
        return Database::table(self::TABLE)->insert($data);
    }

    /**
     * Update a record.
     *
     * @param int $id The record ID
     * @param array<string, mixed> $data The data to update
     * @return int The number of affected rows
     */
    public function update(int $id, array $data): int
    {
        return Database::table(self::TABLE)->where('id', $id)->update($data);
    }

    /**
     * Delete a record.
     *
     * @param int $id The record ID
     * @return int The number of affected rows
     */
    public function delete(int $id): int
    {
        return Database::table(self::TABLE)->where('id', $id)->delete();
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
