<?php

namespace SfPhp\Queue;

class QueueManager
{
    protected Queue $driver;

    public function __construct(Queue $driver = null)
    {
        $this->driver = $driver ?? new DatabaseDriver();
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

    public function failed(): array
    {
        // Placeholder for failed jobs retrieval
        return [];
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
