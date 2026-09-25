<?php

namespace SfphpProject\src\Queue;

abstract class Job
{
    protected int $tries = 3;
    protected int $timeout = 60;
    protected ?int $delay = null;
    protected ?string $id = null;
    protected int $attempts = 0;

    public function tries(int $tries): static
    {
        $this->tries = $tries;
        return $this;
    }

    /**
     * How long one attempt may run, in seconds; 0 means no limit.
     *
     * The worker enforces it with SIGALRM, so it needs ext-pcntl. Without
     * pcntl the value is stored but a job runs until it returns.
     *
     * @param int $seconds The limit
     * @return static The job
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getTries(): int
    {
        return $this->tries;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getDelay(): ?int
    {
        return $this->delay;
    }

    /**
     * The job's own data, for a driver to store.
     *
     * The properties Job itself declares are left out. They are the queue's
     * bookkeeping about the job, not the job's data: id in particular is null
     * when a job is pushed, so capturing it here and restoring it on the way
     * back overwrote the id the driver had just assigned — and every later call
     * that identifies the job by it then addressed nothing, silently.
     *
     * This lives on Job rather than in each driver because both drivers had
     * their own copy of it, and the bug was fixed in one of them. One copy
     * cannot drift from the other.
     *
     * @internal Called by a queue driver.
     * @return array<string, mixed> The subclass's own properties
     */
    public function payload(): array
    {
        $data = [];

        foreach ((new \ReflectionClass($this))->getProperties() as $property) {
            if (in_array($property->getName(), self::bookkeeping(), true)) {
                continue;
            }

            $property->setAccessible(true);

            if ($property->isInitialized($this)) {
                $data[$property->getName()] = $property->getValue($this);
            }
        }

        return $data;
    }

    /**
     * Put stored data back onto the job.
     *
     * @internal Called by a queue driver.
     * @param array<string, mixed> $data The stored properties
     * @return void
     */
    public function restore(array $data): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($data as $name => $value) {
            /*
             * Skipped even though payload() no longer writes them: a payload
             * stored by an earlier version still carries them, and restoring a
             * null id would bring the original bug back for those rows.
             */
            if (in_array($name, self::bookkeeping(), true)) {
                continue;
            }

            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    /**
     * The queue settings chosen for this job, for a driver to store.
     *
     * tries and timeout are bookkeeping, so payload() leaves them out, and for
     * a long time nothing else wrote them either: the worker rebuilt the job
     * with the class defaults, and a ->tries(5) or ->timeout(120) set at
     * dispatch was silently lost between the request and the worker.
     *
     * @internal Called by a queue driver.
     * @return array{tries: int, timeout: int} The settings
     */
    public function options(): array
    {
        return ['tries' => $this->tries, 'timeout' => $this->timeout];
    }

    /**
     * Rebuild a job from what a driver stored.
     *
     * The instance is created without calling its constructor, and its
     * properties are then put back from the payload. Calling `new $class()`
     * instead crashed the worker on any job with a required constructor
     * argument — `new SendInvoice($invoiceId)` is the natural way to write a
     * job — and it did so inside pop(), outside the worker's try/catch, so the
     * job came back after its reservation expired and killed the next worker
     * too. The constructor has already run once, when the job was dispatched;
     * what it set up is in the payload.
     *
     * A payload stored before options were recorded has none, and the job
     * keeps its class defaults, which is what it would have had anyway.
     *
     * @internal Called by a queue driver.
     * @param array{class?: mixed, data?: mixed, options?: mixed} $payload The decoded payload
     * @param string $id The id the driver assigned
     * @param int $attempts How many times the job has already failed
     * @return Job The job, ready to run
     * @throws \UnexpectedValueException If the payload does not name a job class
     */
    public static function fromPayload(array $payload, string $id, int $attempts): Job
    {
        $class = $payload['class'] ?? null;

        /*
         * Checked because the class name comes out of storage: a payload that
         * names something other than a Job must not be instantiated at all.
         */
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, self::class)) {
            throw new \UnexpectedValueException(sprintf(
                'Queued payload %s names %s, which is not a job class.',
                $id,
                is_string($class) ? $class : 'no class'
            ));
        }

        /** @var Job $job */
        $job = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $job->setId($id);
        $job->setAttempts($attempts);
        $job->restore(is_array($payload['data'] ?? null) ? $payload['data'] : []);

        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        if (isset($options['tries']) && is_int($options['tries'])) {
            $job->tries = $options['tries'];
        }

        if (isset($options['timeout']) && is_int($options['timeout'])) {
            $job->timeout = $options['timeout'];
        }

        return $job;
    }

    /**
     * The names of the properties that belong to the queue, not to the job.
     *
     * @return list<string> The property names
     */
    private static function bookkeeping(): array
    {
        static $names = null;

        return $names ??= array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(self::class))->getProperties()
        );
    }

    abstract public function handle(): void;
}
