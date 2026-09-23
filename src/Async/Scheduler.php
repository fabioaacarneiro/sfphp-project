<?php

namespace SfphpProject\src\Async;

/**
 * Simple event loop that manages the execution of multiple Tasks (Fibers)
 *
 * The Scheduler:
 * 1. Keeps a queue of Tasks to run
 * 2. Iterates through them, starting or resuming each one
 * 3. Continues until all Tasks are complete
 *
 * This is a cooperative scheduler - Tasks must yield control
 * (via Fiber::suspend() in await()) for other Tasks to run
 */
class Scheduler
{
    /**
     * Queue of Tasks to execute
     * @var Task[]
     */
    private array $tasks = [];

    /**
     * Currently executing Task (for debugging/context)
     */
    private ?Task $currentTask = null;

    /**
     * Flag to prevent recursive scheduler calls
     */
    private bool $running = false;

    /**
     * Add a Task to the scheduler's queue
     */
    public function schedule(Task $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Run the scheduler until all Tasks are complete
     *
     * This is the main event loop. It:
     * 1. Starts Tasks that haven't been started yet
     * 2. Resumes Tasks that were suspended in await()
     * 3. Continues until all Tasks are complete
     */
    public function run(): void
    {
        if ($this->running) {
            // Prevent recursive scheduler calls
            return;
        }

        $this->running = true;

        try {
            while (!empty($this->tasks)) {
                $tasksToRun = $this->tasks;
                $this->tasks = [];

                foreach ($tasksToRun as $task) {
                    if ($task->isTerminated()) {
                        // Task already finished, skip
                        continue;
                    }

                    $this->currentTask = $task;

                    try {
                        if (!$task->isPending() && !$task->isTerminated()) {
                            // First time - start the Task
                            $task->start();
                        } elseif ($task->isPending()) {
                            // Resume a suspended Task
                            $task->resume();
                        }
                    } catch (\Throwable $e) {
                        // Task threw an unhandled exception
                        // This shouldn't happen if tasks properly catch exceptions
                        // but we handle it for robustness
                    }

                    if (!$task->isTerminated()) {
                        // Task still running or suspended, put it back in queue
                        $this->tasks[] = $task;
                    }

                    $this->currentTask = null;
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Get the currently executing Task
     *
     * Useful for debugging
     */
    public function getCurrentTask(): ?Task
    {
        return $this->currentTask;
    }

    /**
     * Get the number of pending Tasks
     */
    public function getPendingCount(): int
    {
        return count($this->tasks);
    }

    /**
     * Check if the scheduler is currently running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
