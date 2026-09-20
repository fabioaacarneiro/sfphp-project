<?php

namespace SfPhp\Queue;

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

    abstract public function handle(): void;
}
