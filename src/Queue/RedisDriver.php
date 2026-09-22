<?php

namespace SfphpProject\src\Queue;

class RedisDriver implements Queue
{
    protected \Redis $redis;
    protected string $prefix = 'queue:';
    protected string $failedPrefix = 'failed:';

    public function __construct(?\Redis $redis = null)
    {
        if ($redis === null) {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
        }

        $this->redis = $redis;
    }

    public function push(Job $job, ?int $delay = null): string
    {
        $id = uniqid('job_', true);
        $delay = $delay ?? $job->getDelay();
        $availableAt = $delay ? time() + $delay : time();

        $payload = [
            'id' => $id,
            'class' => get_class($job),
            'data' => $this->serializeJob($job),
            'attempts' => 0,
            'available_at' => $availableAt,
        ];

        $score = $availableAt;
        $this->redis->zAdd($this->prefix . 'default', $score, json_encode($payload));

        return $id;
    }

    public function pop(): ?Job
    {
        $now = time();
        $items = $this->redis->zRangeByScore(
            $this->prefix . 'default',
            0,
            $now,
            ['limit' => [0, 1]]
        );

        if (empty($items)) {
            return null;
        }

        /*
         * zRem reports how many members it removed, and that report is the
         * claim: two workers can both read the same item, and only the one
         * whose zRem returns 1 actually took it. Ignoring the result meant both
         * ran the job — the same duplicated side effect the database driver had.
         */
        if ((int) $this->redis->zRem($this->prefix . 'default', $items[0]) !== 1) {
            return null;
        }

        $payload = json_decode($items[0], true);

        $instance = new $payload['class']();
        $instance->setId($payload['id']);
        $instance->setAttempts($payload['attempts']);
        $this->unserializeJob($instance, $payload['data']);

        return $instance;
    }

    public function failed(Job $job, \Throwable $exception): void
    {
        $failedJob = [
            'uuid' => $job->getId(),
            'class' => get_class($job),
            'exception' => $exception->getMessage(),
            'failed_at' => time(),
        ];

        $this->redis->hSet(
            $this->failedPrefix . $job->getId(),
            'data',
            json_encode($failedJob)
        );

        $this->delete($job);
    }

    /**
     * List the jobs that exhausted their retries.
     *
     * @return array<int, array{id: string, exception: string, failed_at: int}>
     */
    public function failedJobs(): array
    {
        $jobs = [];

        foreach ($this->redis->keys($this->failedPrefix . '*') as $key) {
            $raw = $this->redis->hGet($key, 'data');
            if (!is_string($raw)) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $jobs[] = [
                'id' => (string) ($decoded['uuid'] ?? ''),
                'exception' => (string) ($decoded['exception'] ?? ''),
                'failed_at' => (int) ($decoded['failed_at'] ?? 0),
            ];
        }

        usort($jobs, static fn (array $a, array $b): int => $b['failed_at'] <=> $a['failed_at']);

        return $jobs;
    }

    public function retry(Job $job): void
    {
        $job->setAttempts($job->getAttempts() + 1);

        if ($job->getAttempts() >= $job->getTries()) {
            $this->failed($job, new \Exception('Max retries exceeded'));
            return;
        }

        $this->release($job, 60);
    }

    public function release(Job $job, ?int $delay = null): void
    {
        $delay = $delay ?? 60;
        $availableAt = time() + $delay;

        $payload = [
            'id' => $job->getId(),
            'class' => get_class($job),
            'data' => $this->serializeJob($job),
            'attempts' => $job->getAttempts(),
            'available_at' => $availableAt,
        ];

        $this->redis->zAdd(
            $this->prefix . 'default',
            $availableAt,
            json_encode($payload)
        );
    }

    public function delete(Job $job): void
    {
        $this->redis->del($this->prefix . $job->getId());
    }

    public function flush(): void
    {
        $this->redis->flushDb();
    }

    public function size(): int
    {
        return (int) $this->redis->zCard($this->prefix . 'default');
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
}
