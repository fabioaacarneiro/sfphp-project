<?php

namespace SfphpProject\src\Queue;

interface Queue
{
    public function push(Job $job, ?int $delay = null): string;

    public function pop(): ?Job;

    public function failed(Job $job, \Throwable $exception): void;

    /**
     * List the jobs that exhausted their retries.
     *
     * @return array<int, array{id: string, exception: string, failed_at: int}>
     */
    public function failedJobs(): array;

    public function retry(Job $job): void;

    public function release(Job $job, ?int $delay = null): void;

    public function delete(Job $job): void;

    public function flush(): void;

    public function size(): int;
}
