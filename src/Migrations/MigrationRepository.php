<?php

namespace SfphpProject\src\Migrations;

use PDO;
use PDOStatement;

/**
 * Stores migration execution history.
 */
final class MigrationRepository
{
    /**
     * Create a repository for the given connection.
     *
     * @param PDO $pdo The database connection
     */
    public function __construct(private PDO $pdo) {}

    /**
     * Ensure the migrations table exists.
     *
     * @return void
     */
    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . 'migration VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'batch INTEGER NOT NULL, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
    }

    /**
     * Determine the next migration batch number.
     *
     * @return int
     */
    public function nextBatch(): int
    {
        $batch = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 AS batch FROM migrations');
        if ($batch === false) {
            return 1;
        }

        return (int) ($batch->fetchColumn() ?: 1);
    }

    /**
     * Record a migration as applied.
     *
     * @param string $migration The migration filename
     * @param int $batch The migration batch number
     * @return void
     */
    public function record(string $migration, int $batch): void
    {
        $statement = $this->prepare(
            'INSERT INTO migrations (migration, batch, applied_at) VALUES (:migration, :batch, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'migration' => $migration,
            'batch' => $batch,
        ]);
    }

    /**
     * Forget a migration after rollback.
     *
     * @param string $migration The migration filename
     * @return void
     */
    public function forget(string $migration): void
    {
        $statement = $this->prepare('DELETE FROM migrations WHERE migration = :migration');
        $statement->execute(['migration' => $migration]);
    }

    /**
     * Get all applied migrations.
     *
     * @return array<int, array{migration: string, batch: int, applied_at: string}>
     */
    public function all(): array
    {
        $statement = $this->prepare(
            'SELECT migration, batch, applied_at FROM migrations ORDER BY applied_at ASC, migration ASC'
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the most recently applied migrations.
     *
     * @param int $limit The maximum number of migrations to return
     * @return array<int, array{migration: string, batch: int, applied_at: string}>
     */
    public function latest(int $limit): array
    {
        $statement = $this->prepare(
            'SELECT migration, batch, applied_at FROM migrations '
            . 'ORDER BY applied_at DESC, migration DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether a migration has been applied.
     *
     * @param string $migration The migration filename
     * @return bool
     */
    public function exists(string $migration): bool
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM migrations WHERE migration = :migration');
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Prepare a PDO statement.
     *
     * @param string $sql The SQL statement
     * @return PDOStatement
     */
    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare migration repository statement.');
        }

        return $statement;
    }
}
