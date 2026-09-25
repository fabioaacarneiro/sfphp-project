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

    /**
     * Run jobs until told to stop or until $timeout seconds have passed.
     *
     * With ext-pcntl, SIGTERM and SIGINT stop the worker gracefully: the job
     * that is running finishes, and the loop exits before taking another. Each
     * job's own timeout() is enforced with SIGALRM, and a job that overruns
     * fails that attempt with a JobTimedOutException. Without pcntl there is
     * no graceful stop and no per-job limit; a job runs until it returns.
     *
     * @param int $timeout How long the worker runs, in seconds
     * @return void
     */
    public function work(int $timeout = 3600): void
    {
        $stop = false;
        $start = time();

        $signals = $this->captureSignals($stop);

        try {
            while (!$stop && (time() - $start) < $timeout) {
                if (!$job = $this->driver->pop()) {
                    usleep(100000);
                    continue;
                }

                $this->runJob($job, $signals);
            }
        } finally {
            if ($signals) {
                pcntl_alarm(0);
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_signal(SIGALRM, SIG_DFL);
            }
        }
    }

    /**
     * Run one job and record the outcome.
     *
     * @param Job $job The job
     * @param bool $enforceTimeout Whether SIGALRM is available to cut it short
     * @return void
     */
    protected function runJob(Job $job, bool $enforceTimeout): void
    {
        try {
            if ($enforceTimeout && $job->getTimeout() > 0) {
                pcntl_alarm($job->getTimeout());
            }

            try {
                $job->handle();
            } finally {
                if ($enforceTimeout) {
                    pcntl_alarm(0);
                }
            }

            $this->driver->delete($job);
            echo "[" . gmdate('Y-m-d H:i:s') . "] Job {$job->getId()} succeeded\n";
        } catch (\Throwable $e) {
            /*
             * The attempt that just failed is counted once. This used to add
             * one here and then call retry(), which adds one again, so a job
             * with tries = 3 got two runs. retry() is the driver's to count
             * with; only the final failure is counted here, because it goes
             * to failed() instead.
             */
            if ($job->getAttempts() + 1 >= $job->getTries()) {
                $job->setAttempts($job->getAttempts() + 1);
                $this->driver->failed($job, $e);
                echo "[" . gmdate('Y-m-d H:i:s') . "] Job {$job->getId()} failed: {$e->getMessage()}\n";
            } else {
                $this->driver->retry($job);
                echo "[" . gmdate('Y-m-d H:i:s') . "] Job {$job->getId()} retrying (attempt {$job->getAttempts()})\n";
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

    /**
     * Install the stop and timeout handlers, when pcntl is there.
     *
     * pcntl_async_signals() is what makes the handlers run. They were
     * installed without it, and without ticks or pcntl_signal_dispatch() a
     * handler never fires; installing one still replaces the default action,
     * so the worker ignored SIGTERM altogether and only exited when its
     * --timeout ran out, which a deploy or an orchestrator waiting to stop the
     * container sees as a hang.
     *
     * @param bool $stop Set to true when the worker is asked to stop
     * @return bool Whether the handlers were installed
     */
    protected function captureSignals(bool &$stop): bool
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return false;
        }

        pcntl_async_signals(true);

        $handler = static function () use (&$stop): void {
            $stop = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        /*
         * Thrown from the handler, so it surfaces inside handle() and is
         * treated like any other failure of that attempt. A job blocked in a
         * call that does not return on a signal only sees it once that call
         * returns, and a job that catches \Throwable itself swallows it.
         */
        pcntl_signal(SIGALRM, static function (): void {
            throw new JobTimedOutException('The job ran longer than its timeout.');
        });

        return true;
    }
}
