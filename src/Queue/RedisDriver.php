<?php

namespace SfphpProject\src\Queue;

use SfphpProject\src\RedisConnection;

/**
 * A queue in Redis.
 *
 * Three keys, all under the prefix:
 *
 *   queue:default   sorted set — job ids, scored by when they become available
 *   queue:jobs      hash — each job's payload, by id
 *   queue:failed    hash — the jobs that exhausted their retries, by id
 *
 * The sorted set used to hold the whole JSON payload as its member. That made
 * a job impossible to find by id, so delete() removed a key that never existed
 * and did nothing, and a released job and its earlier copy were two different
 * members. With ids as members, removing, releasing and claiming a job are
 * each one operation on one member.
 */
class RedisDriver implements Queue
{
    /**
     * The phpredis connection, or any object with the same methods — which is
     * what lets the tests exercise this driver without a Redis server.
     */
    protected object $redis;

    protected string $prefix = 'queue:';

    /**
     * Where failed jobs were kept before they moved into the queue:failed hash:
     * one key per job, failed:<id>. Matched with the job_ ids push() generates,
     * so reading or flushing them can never reach another feature's failed:*
     * keys.
     */
    protected string $legacyFailedPattern = 'failed:job_*';

    public function __construct(?object $redis = null)
    {
        // The configured connection, not a hard-coded localhost: an instance
        // that talked to its own Redis would keep a queue no other worker sees.
        $this->redis = $redis ?? RedisConnection::get();
    }

    public function push(Job $job, ?int $delay = null): string
    {
        $id = uniqid('job_', true);
        $delay = $delay ?? $job->getDelay();
        $availableAt = $delay ? time() + $delay : time();

        $this->store($id, $job, 0, $availableAt);

        return $id;
    }

    public function pop(): ?Job
    {
        $items = $this->redis->zRangeByScore(
            $this->key('default'),
            0,
            time(),
            ['limit' => [0, 1]]
        );

        if (empty($items)) {
            return null;
        }

        $member = (string) $items[0];

        /*
         * zRem reports how many members it removed, and that report is the
         * claim: two workers can both read the same item, and only the one
         * whose zRem returns 1 actually took it. Ignoring the result meant both
         * ran the job — the same duplicated side effect the database driver had.
         */
        if ((int) $this->redis->zRem($this->key('default'), $member) !== 1) {
            return null;
        }

        // A member that is a JSON object was queued by the previous layout,
        // which kept the payload in the sorted set itself.
        if (str_starts_with($member, '{')) {
            $payload = json_decode($member, true);
        } else {
            $raw = $this->redis->hGet($this->key('jobs'), $member);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
        }

        if (!is_array($payload)) {
            return null;
        }

        return Job::fromPayload($payload, (string) ($payload['id'] ?? ''), (int) ($payload['attempts'] ?? 0));
    }

    public function failed(Job $job, \Throwable $exception): void
    {
        $failedJob = [
            'uuid' => $job->getId(),
            'class' => get_class($job),
            'exception' => $exception->getMessage(),
            'failed_at' => time(),
        ];

        $this->redis->hSet($this->key('failed'), $job->getId(), json_encode($failedJob));

        $this->delete($job);
    }

    /**
     * List the jobs that exhausted their retries.
     *
     * Read from one hash rather than with KEYS failed:*, which walks every key
     * in the database and blocks Redis while it does — on a server shared with
     * the cache and the sessions, a pause for everybody.
     *
     * @return array<int, array{id: string, exception: string, failed_at: int}>
     */
    public function failedJobs(): array
    {
        $jobs = [];
        $entries = array_values((array) $this->redis->hGetAll($this->key('failed')));

        foreach ($this->legacyFailedKeys() as $key) {
            $raw = $this->redis->hGet($key, 'data');

            if (is_string($raw)) {
                $entries[] = $raw;
            }
        }

        foreach ($entries as $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

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

        $this->store($job->getId(), $job, $job->getAttempts(), time() + $delay);
    }

    /**
     * Remove a job from the queue, wherever it is: waiting, or already popped.
     */
    public function delete(Job $job): void
    {
        $id = $job->getId();

        $this->redis->zRem($this->key('default'), $id);
        $this->redis->hDel($this->key('jobs'), $id);
    }

    /**
     * Empty the queue and its failed jobs, and nothing else.
     *
     * This called flushDb(), which erases the whole Redis database: on a
     * server that also holds the cache, the sessions and the revoked-token
     * list — the usual arrangement once there is more than one instance —
     * clearing the queue logged everybody out.
     */
    public function flush(): void
    {
        $this->redis->del($this->key('default'), $this->key('jobs'), $this->key('failed'));

        foreach ($this->legacyFailedKeys() as $key) {
            $this->redis->del($key);
        }
    }

    public function size(): int
    {
        return (int) $this->redis->zCard($this->key('default'));
    }

    /**
     * Write a job's payload and schedule its id.
     */
    protected function store(string $id, Job $job, int $attempts, int $availableAt): void
    {
        $payload = [
            'id' => $id,
            'class' => get_class($job),
            'data' => $job->payload(),
            'options' => $job->options(),
            'attempts' => $attempts,
            'available_at' => $availableAt,
        ];

        $this->redis->hSet($this->key('jobs'), $id, json_encode($payload));
        $this->redis->zAdd($this->key('default'), $availableAt, $id);
    }

    protected function key(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * The failed-job keys the previous layout wrote, one per job.
     *
     * Found with SCAN, in steps, so an upgrade does not block Redis the way
     * KEYS did; once they are flushed or empty, the loop costs one round trip.
     *
     * @return list<string>
     */
    protected function legacyFailedKeys(): array
    {
        if (!method_exists($this->redis, 'scan')) {
            return array_values((array) $this->redis->keys($this->legacyFailedPattern));
        }

        $keys = [];
        $iterator = null;

        do {
            $batch = $this->redis->scan($iterator, $this->legacyFailedPattern, 100);

            if (is_array($batch)) {
                array_push($keys, ...$batch);
            }
        } while ($iterator !== 0 && $iterator !== null && $iterator !== '0');

        return $keys;
    }
}
