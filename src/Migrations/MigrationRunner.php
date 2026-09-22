<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Coordinates migration execution and rollback.
 */
final class MigrationRunner
{
    /**
     * Create a migration runner.
     *
     * @param PDO $pdo The database connection
     * @param string $directory The migrations directory
     */
    public function __construct(
        private PDO $pdo,
        private string $directory
    ) {}

    /**
     * Apply pending migrations.
     *
     * @param int|null $step The maximum number of migrations to apply
     * @return array<int, string> The applied migration filenames
     */
    public function migrate(?int $step = null): array
    {
        /*
         * Taken before the pending list is read, because the race is in the
         * gap between reading it and acting on it: two instances migrating on
         * boot both see the same file as pending and both run it.
         */
        $lock = new MigrationLock($this->pdo);
        $lock->acquire();

        try {
            $repository = $this->repository();
            $schema = new Schema($this->pdo);
            $pending = $this->pendingFiles($repository);
            $selected = $step === null ? $pending : array_slice($pending, 0, $step);
            $batch = $repository->nextBatch();
            $applied = [];

            foreach ($selected as $file) {
                $migration = $this->load($file);
                $this->transactional(function () use ($migration, $schema, $repository, $file, $batch): void {
                    $migration->up($schema);
                    $repository->record(basename($file), $batch);
                });
                $applied[] = basename($file);
            }

            return $applied;
        } finally {
            $lock->release();
        }
    }

    /**
     * Roll back applied migrations.
     *
     * @param int $steps The number of migrations to revert
     * @return array<int, string> The reverted migration filenames
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new InvalidArgumentException('Rollback steps must be at least 1.');
        }

        $repository = $this->repository();
        $schema = new Schema($this->pdo);
        $latest = $repository->latest($steps);
        $reverted = [];

        foreach ($latest as $migration) {
            $file = $this->filePath($migration['migration']);
            if (!is_file($file)) {
                throw new RuntimeException("Migration file not found: {$migration['migration']}");
            }

            $loaded = $this->load($file);
            $this->transactional(function () use ($loaded, $schema, $repository, $migration): void {
                $loaded->down($schema);
                $repository->forget($migration['migration']);
            });
            $reverted[] = $migration['migration'];
        }

        return $reverted;
    }

    /**
     * Reset the database by rolling back all migrations and clearing the history.
     *
     * @return void
     */
    public function fresh(): void
    {
        $repository = $this->repository();
        $schema = new Schema($this->pdo);
        $applied = $repository->all();

        foreach (array_reverse($applied) as $migration) {
            $file = $this->filePath($migration['migration']);
            if (!is_file($file)) {
                continue;
            }

            $loaded = $this->load($file);
            $this->transactional(function () use ($loaded, $schema): void {
                $loaded->down($schema);
            });
        }

        $this->transactional(function () use ($repository): void {
            $repository->clear();
        });
    }

    /**
     * List migration files and their current status.
     *
     * @return array<int, array{migration: string, status: string}>
     */
    public function status(): array
    {
        $repository = $this->repository();
        $applied = array_fill_keys(array_column($repository->all(), 'migration'), true);
        $rows = [];

        foreach ($this->files() as $file) {
            $migration = basename($file);
            $rows[] = [
                'migration' => $migration,
                'status' => isset($applied[$migration]) ? 'applied' : 'pending',
            ];
        }

        return $rows;
    }

    /**
     * Get all migration files on disk.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Run a callback atomically when the driver supports transactional DDL.
     *
     * PostgreSQL rolls schema changes back with the transaction, so a failing
     * migration leaves nothing half-applied. MySQL commits DDL implicitly, so it
     * runs without a transaction.
     *
     * @param callable():void $callback The work to run
     * @return void
     */
    private function transactional(callable $callback): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->pdo->inTransaction()) {
            $callback();

            return;
        }

        $this->pdo->beginTransaction();

        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * Load a migration file and return the migration object.
     *
     * @param string $file The migration file path
     * @return Migration
     */
    private function load(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration file must return an instance of Migration: $file");
        }

        return $migration;
    }

    /**
     * Get pending migration files.
     *
     * @param MigrationRepository $repository The migration repository
     * @return array<int, string>
     */
    private function pendingFiles(MigrationRepository $repository): array
    {
        return array_values(array_filter(
            $this->files(),
            static fn (string $file): bool => !$repository->exists(basename($file))
        ));
    }

    /**
     * Create the repository and migrations table.
     *
     * @return MigrationRepository
     */
    private function repository(): MigrationRepository
    {
        $repository = new MigrationRepository($this->pdo);
        $repository->ensureTable();

        return $repository;
    }

    /**
     * Build the full path for a migration filename.
     *
     * @param string $migration The migration filename
     * @return string
     */
    private function filePath(string $migration): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $migration;
    }
}
