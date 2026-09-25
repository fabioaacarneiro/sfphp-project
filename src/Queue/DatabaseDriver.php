<?php

namespace SfphpProject\src\Queue;

use SfphpProject\src\Database;

class DatabaseDriver implements Queue
{
    /** How many jobs a worker will try to claim before giving the turn up. */
    private const CLAIM_ATTEMPTS = 10;

    protected string $table = 'jobs';
    protected string $failedTable = 'failed_jobs';

    private bool $tablesEnsured = false;

    /**
     * Create the driver.
     *
     * @param int $reservationSeconds How long a reserved job may sit before it is considered abandoned
     * @param string|null $table The table holding queued jobs
     * @param string|null $failedTable The table holding jobs that ran out of retries
     */
    public function __construct(
        private int $reservationSeconds = 900,
        ?string $table = null,
        ?string $failedTable = null,
        private ?\PDO $connection = null
    ) {
        $this->table = $table ?? $this->table;
        $this->failedTable = $failedTable ?? $this->failedTable;
    }

    /**
     * Start a query against one of the queue's tables.
     *
     * Goes through an injected connection when one was given, which is what
     * lets the integration tests point the queue at the same throwaway database
     * they point the schema builder at.
     *
     * @param string $table The table name
     * @return \SfphpProject\src\QueryBuilder The query
     */
    private function query(string $table): \SfphpProject\src\QueryBuilder
    {
        return $this->connection === null
            ? Database::table($table)
            : (new \SfphpProject\src\QueryBuilder($this->connection))->from($table);
    }

    /**
     * The connection the queue writes to.
     *
     * @return \PDO The connection
     */
    private function connection(): \PDO
    {
        return $this->connection ?? Database::connect();
    }


    public function push(Job $job, ?int $delay = null): string
    {
        $this->ensureTables();

        $id = uniqid('job_', true);
        $delay = $delay ?? $job->getDelay();
        $availableAt = $delay ? time() + $delay : time();

        $this->query($this->table)->insert([
            'id' => $id,
            'queue' => 'default',
            'payload' => json_encode([
                'class' => get_class($job),
                'data' => $job->payload(),
                'options' => $job->options(),
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => time(),
        ]);

        return $id;
    }

    /**
     * Take the next job, if this worker wins the claim.
     *
     * The claim is the whole method. Selecting a row and then updating it is
     * not a reservation: two workers read the same row, both write their own
     * reserved_at, and both run the job. For a queue that is not a slowdown but
     * a duplicated side effect — the same e-mail twice, the same card charged
     * twice — and it only happens once there is more than one worker, which is
     * exactly when nobody is watching.
     *
     * So the UPDATE carries the condition: only the worker whose statement
     * still finds reserved_at NULL has it, and it knows because the update
     * reports one affected row. A worker that loses moves to the next job
     * rather than running one that is already somebody else's.
     *
     * This is done with a conditional update rather than SELECT ... FOR UPDATE
     * SKIP LOCKED because the framework supports seven drivers and SKIP LOCKED
     * is not in all of them. Under heavy contention it costs extra round trips;
     * a queue that needs more than that should be on Redis.
     *
     * @return Job|null The claimed job, or null when there is nothing to do
     */
    public function pop(): ?Job
    {
        $this->ensureTables();

        $this->releaseStale();

        /*
         * Bounded rather than a while(true): a worker that keeps losing gives
         * the turn up instead of spinning. Losing does not need a skip list —
         * the worker that won has already set reserved_at, so the same filter
         * that finds work excludes the row on the next pass.
         */
        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $job = $this->query($this->table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', time())
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$job) {
                return null;
            }

            $claimed = $this->query($this->table)
                ->where('id', $job['id'])
                ->whereNull('reserved_at')
                ->update(['reserved_at' => time()]);

            if ($claimed !== 1) {
                // Another worker got there first. Look again.
                continue;
            }

            $payload = json_decode($job['payload'], true);

            return Job::fromPayload(is_array($payload) ? $payload : [], (string) $job['id'], (int) $job['attempts']);
        }

        return null;
    }

    /**
     * Put back jobs reserved by a worker that never finished them.
     *
     * A worker that is killed between reserving a job and finishing it leaves
     * reserved_at set with nobody working on it, and the job is never picked up
     * again. With one worker that is rare; with several instances being
     * deployed, restarted and scaled it is routine, and the job disappears
     * silently, which is the worst way for work to be lost.
     *
     * @return void
     */
    private function releaseStale(): void
    {
        $this->query($this->table)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', time() - $this->reservationSeconds)
            ->update(['reserved_at' => null]);
    }

    public function failed(Job $job, \Throwable $exception): void
    {
        $this->ensureTables();

        $this->query($this->failedTable)->insert([
            'uuid' => $job->getId(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'class' => get_class($job),
                'data' => $job->payload(),
                'options' => $job->options(),
            ]),
            'exception' => $exception->getMessage(),
            'failed_at' => time(),
        ]);

        $this->delete($job);
    }

    public function retry(Job $job): void
    {
        $job->setAttempts($job->getAttempts() + 1);

        if ($job->getAttempts() >= $job->getTries()) {
            $this->failed($job, new \Exception('Max retries exceeded'));
            return;
        }

        $this->query($this->table)
            ->where('id', $job->getId())
            ->update([
                'attempts' => $job->getAttempts(),
                'reserved_at' => null,
                'available_at' => time() + 60,
            ]);
    }

    public function release(Job $job, ?int $delay = null): void
    {
        $delay = $delay ?? 60;

        $this->query($this->table)
            ->where('id', $job->getId())
            ->update([
                'reserved_at' => null,
                'available_at' => time() + $delay,
            ]);
    }

    public function delete(Job $job): void
    {
        $this->query($this->table)
            ->where('id', $job->getId())
            ->delete();
    }

    public function flush(): void
    {
        $this->ensureTables();

        $this->query($this->table)->delete();
        $this->query($this->failedTable)->delete();
    }

    public function size(): int
    {
        $this->ensureTables();

        return $this->query($this->table)->count();
    }



    protected function ensureTables(): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        $schema = new \SfphpProject\src\Migrations\Schema($this->connection());

        if (!$schema->hasTable($this->table)) {
            $schema->create($this->table, function (\SfphpProject\src\Migrations\Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedInteger('attempts')->default(0);
                /*
                 * An integer, like available_at and created_at beside it. This
                 * was a timestamp column receiving time(), which MySQL refuses
                 * outright — "Incorrect datetime value: '1790041711'" — so the
                 * driver could push a job and never reserve one.
                 */
                $table->unsignedBigInteger('reserved_at')->nullable();
                $table->integer('available_at');
                $table->integer('created_at');
            });
        }

        if (!$schema->hasTable($this->failedTable)) {
            $schema->create($this->failedTable, function (\SfphpProject\src\Migrations\Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('connection');
                $table->string('queue');
                $table->text('payload');
                $table->text('exception');
                $table->unsignedBigInteger('failed_at');
            });
        }

        $this->tablesEnsured = true;
    }

    /**
     * List the jobs that exhausted their retries.
     *
     * @return array<int, array{id: string, exception: string, failed_at: int}>
     */
    public function failedJobs(): array
    {
        $this->ensureTables();

        $rows = $this->query($this->failedTable)
            ->orderBy('failed_at', 'desc')
            ->get();

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['uuid'],
            'exception' => (string) $row['exception'],
            'failed_at' => (int) $row['failed_at'],
        ], $rows);
    }
}
