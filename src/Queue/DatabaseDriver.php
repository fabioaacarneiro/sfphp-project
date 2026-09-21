<?php

namespace SfphpProject\src\Queue;

use SfphpProject\src\Database;

class DatabaseDriver implements Queue
{
    protected string $table = 'jobs';
    protected string $failedTable = 'failed_jobs';

    private bool $tablesEnsured = false;


    public function push(Job $job, ?int $delay = null): string
    {
        $this->ensureTables();

        $id = uniqid('job_', true);
        $delay = $delay ?? $job->getDelay();
        $availableAt = $delay ? time() + $delay : time();

        Database::table($this->table)->insert([
            'id' => $id,
            'queue' => 'default',
            'payload' => json_encode([
                'class' => get_class($job),
                'data' => $this->serializeJob($job),
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => time(),
        ]);

        return $id;
    }

    public function pop(): ?Job
    {
        $this->ensureTables();

        $job = Database::table($this->table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', time())
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$job) {
            return null;
        }

        $payload = json_decode($job['payload'], true);
        Database::table($this->table)
            ->where('id', $job['id'])
            ->update(['reserved_at' => time()]);

        $instance = new $payload['class']();
        $instance->setId($job['id']);
        $instance->setAttempts($job['attempts']);
        $this->unserializeJob($instance, $payload['data']);

        return $instance;
    }

    public function failed(Job $job, \Throwable $exception): void
    {
        $this->ensureTables();

        Database::table($this->failedTable)->insert([
            'uuid' => $job->getId(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'class' => get_class($job),
                'data' => $this->serializeJob($job),
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

        Database::table($this->table)
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

        Database::table($this->table)
            ->where('id', $job->getId())
            ->update([
                'reserved_at' => null,
                'available_at' => time() + $delay,
            ]);
    }

    public function delete(Job $job): void
    {
        Database::table($this->table)
            ->where('id', $job->getId())
            ->delete();
    }

    public function flush(): void
    {
        $this->ensureTables();

        Database::table($this->table)->delete();
        Database::table($this->failedTable)->delete();
    }

    public function size(): int
    {
        $this->ensureTables();

        return Database::table($this->table)->count();
    }

    protected function serializeJob(Job $job): array
    {
        $reflection = new \ReflectionClass($job);
        $properties = $reflection->getProperties();
        $data = [];

        foreach ($properties as $property) {
            $property->setAccessible(true);
            if ($property->isInitialized($job)) {
                $data[$property->getName()] = $property->getValue($job);
            }
        }

        return $data;
    }

    protected function unserializeJob(Job $job, array $data): void
    {
        $reflection = new \ReflectionClass($job);

        foreach ($data as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($job, $value);
            }
        }
    }

    protected function ensureTables(): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        $schema = new \SfphpProject\src\Migrations\Schema(Database::connect());

        if (!$schema->hasTable($this->table)) {
            $schema->create($this->table, function (\SfphpProject\src\Migrations\Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('reserved_at')->nullable();
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
                $table->timestamp('failed_at');
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

        $rows = Database::table($this->failedTable)
            ->orderBy('failed_at', 'desc')
            ->get();

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['uuid'],
            'exception' => (string) $row['exception'],
            'failed_at' => (int) $row['failed_at'],
        ], $rows);
    }
}
