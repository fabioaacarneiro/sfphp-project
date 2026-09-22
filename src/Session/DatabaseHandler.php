<?php

namespace SfphpProject\src\Session;

use PDO;
use PDOException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use SfphpProject\src\Database;
use SfphpProject\src\Time;

/**
 * Keeps sessions in a database table, so every instance sees the same ones.
 *
 * This is the option for a deployment that already has a database and does not
 * want a second piece of infrastructure to run sessions. It is slower than the
 * cache handler — a session read and a session write per request, on the same
 * connection the application is using for its own work — and it is durable,
 * which the cache is not.
 *
 * The table is created by a migration rather than on demand. A session store
 * that quietly issues DDL is a session store that can fail halfway through a
 * request on a connection without rights to create tables.
 *
 *     ./sfphp migrate
 *     Session::start($secure, new DatabaseHandler(), 7200, 43200);
 */
final class DatabaseHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?PDO $connection = null;

    /**
     * Create the handler.
     *
     * @param string $table The table holding sessions
     * @param PDO|null $connection The connection, or null for the application's
     */
    public function __construct(
        private string $table = 'sessions',
        ?PDO $connection = null
    ) {
        $this->connection = $connection;
    }

    /**
     * @param string $path Ignored; the table decides where data lives
     * @param string $name The session name
     * @return bool Always true
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @return bool Always true
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * @param string $id The session id
     * @return string The serialised session data, or an empty string
     */
    public function read(string $id): string
    {
        $statement = $this->connection()->prepare(
            'SELECT payload FROM ' . $this->table . ' WHERE id = ? AND expires_at > ?'
        );
        $statement->execute([$id, $this->now()]);

        $payload = $statement->fetchColumn();

        return is_string($payload) ? $payload : '';
    }

    /**
     * Store the session, replacing any row already under that id.
     *
     * The upsert is written per dialect rather than as "delete then insert",
     * which would leave a window where a concurrent request reads no session
     * at all — the browser sending two requests at once is ordinary, not rare.
     *
     * @param string $id The session id
     * @param string $data The serialised session data
     * @return bool True when stored
     */
    public function write(string $id, string $data): bool
    {
        $connection = $this->connection();
        $driver = (string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $expiresAt = $this->now() + $this->lifetime();

        $sql = match ($driver) {
            'mysql' => 'INSERT INTO ' . $this->table . ' (id, payload, expires_at) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
            default => 'INSERT INTO ' . $this->table . ' (id, payload, expires_at) VALUES (?, ?, ?)'
                . ' ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at',
        };

        try {
            $connection->prepare($sql)->execute([$id, $data, $expiresAt]);
        } catch (PDOException $e) {
            /*
             * A session that cannot be written is a visitor who is about to be
             * logged out, which is worth a record. It is not worth failing the
             * request over: PHP writes the session during shutdown, where an
             * exception has nowhere useful to go.
             */
            logger()->error('could not write the session', [
                'driver' => $driver,
                'detail' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param string $id The session id
     * @return bool True when removed
     */
    public function destroy(string $id): bool
    {
        $this->connection()->prepare('DELETE FROM ' . $this->table . ' WHERE id = ?')->execute([$id]);

        return true;
    }

    /**
     * Remove sessions whose deadline has passed.
     *
     * @param int $maxLifetime Seconds PHP considers a session expired after
     * @return int|false The number of sessions removed
     */
    public function gc(int $maxLifetime): int|false
    {
        $statement = $this->connection()->prepare(
            'DELETE FROM ' . $this->table . ' WHERE expires_at <= ?'
        );
        $statement->execute([$this->now()]);

        return $statement->rowCount();
    }

    /**
     * Whether this id names a session that exists and has not expired.
     *
     * Implementing this is what makes session.use_strict_mode work: PHP asks
     * before adopting the id a cookie carries, so an id nobody was issued is
     * refused rather than brought to life.
     *
     * @param string $id The session id
     * @return bool True when the session exists
     */
    public function validateId(string $id): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT 1 FROM ' . $this->table . ' WHERE id = ? AND expires_at > ?'
        );
        $statement->execute([$id, $this->now()]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Push the deadline out without rewriting the payload.
     *
     * @param string $id The session id
     * @param string $data The serialised session data
     * @return bool True when refreshed
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        $statement = $this->connection()->prepare(
            'UPDATE ' . $this->table . ' SET expires_at = ? WHERE id = ?'
        );

        return $statement->execute([$this->now() + $this->lifetime(), $id]);
    }

    /**
     * The connection to use.
     *
     * @return PDO The connection
     */
    private function connection(): PDO
    {
        return $this->connection ??= Database::connect();
    }

    /**
     * The current instant, in UTC.
     *
     * Stored as an integer rather than a datetime so the comparison never
     * depends on how the column's zone was interpreted. A session store is the
     * last place worth having that argument.
     *
     * @return int The Unix timestamp
     */
    private function now(): int
    {
        return Time::now()->getTimestamp();
    }

    /**
     * How long a stored session should survive without being touched.
     *
     * @return int The lifetime in seconds
     */
    private function lifetime(): int
    {
        $configured = defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 0;

        return $configured > 0 ? $configured : max(60, (int) ini_get('session.gc_maxlifetime'));
    }
}
