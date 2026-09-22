<?php

namespace SfphpProject\src\Queue;

use SfphpProject\src\Config;
use SfphpProject\src\RedisConnection;

class QueueManager
{
    protected Queue $driver;

    public function __construct(?Queue $driver = null)
    {
        $this->driver = $driver ?? new DatabaseDriver();
    }

    /**
     * Build the queue QUEUE_DRIVER names.
     *
     * The worker command and dispatch() both go through the helper queue(),
     * which keeps one of these. That is the point: a job pushed by a request
     * and a job taken by a worker cannot land on different queues because one
     * side was constructed by hand.
     *
     * @return static The queue
     */
    public static function fromConfig(): static
    {
        $driver = match (Config::get('QUEUE_DRIVER', 'database')) {
            'redis' => new RedisDriver(RedisConnection::get()),
            default => new DatabaseDriver(
                Config::int('QUEUE_RESERVATION_SECONDS', 900),
                Config::get('QUEUE_TABLE') ?: null,
                Config::get('QUEUE_FAILED_TABLE') ?: null
            ),
        };

        return new static($driver);
    }

    /**
     * Which driver this manager is using.
     *
     * @return Queue The driver
     */
    public function getDriver(): Queue
    {
        return $this->driver;
    }

    public function driver(Queue $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    public function push(Job $job, ?int $delay = null): string
    {
        return $this->driver->push($job, $delay);
    }

    public function pop(): ?Job
    {
        return $this->driver->pop();
    }

    public function work(int $timeout = 3600): void
    {
        $stop = false;
        $start = time();

        $this->captureSignals($stop);

        while (!$stop && (time() - $start) < $timeout) {
            if (!$job = $this->driver->pop()) {
                usleep(100000);
                continue;
            }

            try {
                $job->handle();
                $this->driver->delete($job);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$job->getId()} succeeded\n";
            } catch (\Throwable $e) {
                $job->setAttempts($job->getAttempts() + 1);

                if ($job->getAttempts() >= $job->getTries()) {
                    $this->driver->failed($job, $e);
                    echo "[" . date('Y-m-d H:i:s') . "] Job {$job->getId()} failed: {$e->getMessage()}\n";
                } else {
                    $this->driver->retry($job);
                    echo "[" . date('Y-m-d H:i:s') . "] Job {$job->getId()} retrying (attempt {$job->getAttempts()})\n";
                }
            }
        }
    }

    /**
     * List the jobs that exhausted their retries.
     *
     * @return array<int, array{id: string, exception: string, failed_at: int}>
     */
    public function failed(): array
    {
        return $this->driver->failedJobs();
    }

    public function flush(): void
    {
        $this->driver->flush();
    }

    public function size(): int
    {
        return $this->driver->size();
    }

    protected function captureSignals(&$stop): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function() use (&$stop) {
                $stop = true;
            });
            pcntl_signal(SIGINT, function() use (&$stop) {
                $stop = true;
            });
        }
    }
}
