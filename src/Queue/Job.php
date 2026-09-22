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
