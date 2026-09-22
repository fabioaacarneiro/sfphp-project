<?php

namespace SfphpProject\src\Migrations;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Stops two processes from running the same migrations at once.
 *
 * A deploy that starts several instances and lets each one migrate on boot has
 * them all reading the same list of pending migrations at the same moment. Two
 * can decide the same file is pending and both run it, which for a `CREATE
 * TABLE` is an error and for a data migration is the same rows written twice.
 *
 * Running migrations as a single step of a pipeline avoids it, and that is
 * still the better shape — but it is operational discipline, and the framework
 * should not depend on everyone having it.
 *
 * Both servers the framework targets have an advisory lock: a named lock that
 * belongs to a connection, is not tied to any table, and is released when the
 * connection goes away. That last property is what makes it the right tool: a
 * deploy killed mid-migration must not leave a lock nobody can clear.
 *
 * A driver without one is not refused. It would be worse to make migrations
 * fail on SQLite than to leave the guard off where a single writer is the norm
 * anyway; `heldBy()` reports which it was.
 */
final class MigrationLock
{
    /** The lock's name, shared by every process migrating the same database. */
    private const NAME = 'sfphp_migrations';

    /**
     * A 64-bit key for PostgreSQL, which numbers its advisory locks.
     *
     * Derived from the name so it is stable across processes and versions, and
     * masked to 63 bits because the value is signed.
     */
    private const KEY = 0x5346504850_4D4947 & 0x7FFFFFFFFFFFFFFF;

    private bool $held = false;

    private ?string $mechanism = null;

    /**
     * Create the lock.
     *
     * @param PDO $pdo The connection the migrations run on
     * @param int $timeout Seconds to wait for another process to finish
     */
    public function __construct(private PDO $pdo, private int $timeout = 60)
    {
    }

    /**
     * Take the lock, waiting for whoever has it.
     *
     * @return bool True when this process holds the lock, false when the driver has none
     * @throws RuntimeException When another process held it for longer than the timeout
     */
    public function acquire(): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => $this->acquireMySql(),
            'pgsql' => $this->acquirePostgres(),
            default => $this->unsupported($driver),
        };
    }

    /**
     * Give the lock back.
     *
     * @return void
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        try {
            if ($this->mechanism === 'mysql') {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::NAME]);
            } elseif ($this->mechanism === 'pgsql') {
                $this->pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::KEY]);
            }
        } catch (PDOException $e) {
            /*
             * The connection closing releases it anyway, which is the whole
             * reason an advisory lock was the right choice. Failing here would
             * turn a completed migration into a reported failure.
             */
            logger()->warning('could not release the migration lock', ['detail' => $e->getMessage()]);
        }

        $this->held = false;
    }

    /**
     * Whether this process currently holds the lock.
     *
     * @return bool True while held
     */
    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * Which mechanism took the lock, or null when the driver has none.
     *
     * @return string|null The driver name, or null
     */
    public function heldBy(): ?string
    {
        return $this->mechanism;
    }

    /**
     * Take MySQL's named lock.
     *
     * @return bool True when taken
     * @throws RuntimeException When the wait ran out
     */
    private function acquireMySql(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::NAME, $this->timeout]);

        /*
         * 1 taken, 0 timed out, NULL an error. The three are worth telling
         * apart: a timeout means another deploy is still migrating, and an
         * error means something about the connection, so the same message for
         * both would send whoever reads it looking in the wrong place.
         */
        $result = $statement->fetchColumn();

        if ($result === null || $result === false) {
            throw new RuntimeException('The migration lock could not be taken: the server reported an error.');
        }

        if ((int) $result !== 1) {
            throw new RuntimeException(sprintf(
                'Another process has been migrating for more than %d seconds. Waiting stopped.',
                $this->timeout
            ));
        }

        $this->held = true;
        $this->mechanism = 'mysql';

        return true;
    }

    /**
     * Take PostgreSQL's advisory lock.
     *
     * @return bool True when taken
     * @throws RuntimeException When the wait ran out
     */
    private function acquirePostgres(): bool
    {
        /*
         * pg_advisory_lock() waits forever and pg_try_advisory_lock() does not
         * wait at all, so the wait is built here: try, sleep, try again. A
         * deploy that gives up after a minute is better than one that hangs a
         * pipeline until someone notices.
         */
        /*
         * microtime(), not time(): a one-second granularity makes the loop give
         * up after a single tick of the clock rather than after the timeout,
         * so a two-second wait ended in one. The difference only shows up when
         * something is actually contending, which is the moment that matters.
         */
        $deadline = microtime(true) + $this->timeout;

        do {
            $statement = $this->pdo->prepare('SELECT pg_try_advisory_lock(?)');
            $statement->execute([self::KEY]);

            $taken = $statement->fetchColumn();

            // PDO reports a PostgreSQL boolean as true, "t" or "1" depending on
            // the driver build, so all three count as taken.
            if ($taken === true || $taken === 't' || $taken === '1' || $taken === 1) {
                $this->held = true;
                $this->mechanism = 'pgsql';

                return true;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(250_000);
        } while (true);

        throw new RuntimeException(sprintf(
            'Another process has been migrating for more than %d seconds. Waiting stopped.',
            $this->timeout
        ));
    }

    /**
     * Report that this driver has no advisory lock.
     *
     * @param string $driver The driver name
     * @return bool Always false
     */
    private function unsupported(string $driver): bool
    {
        logger()->notice('migrations are running without a lock', [
            'driver' => $driver,
            'detail' => 'This driver has no advisory lock. Run migrations from one process.',
        ]);

        return false;
    }
}
