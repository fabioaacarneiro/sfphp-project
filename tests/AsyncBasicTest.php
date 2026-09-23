<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Scheduler;
use SfphpProject\src\Async\Task;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;
use function SfphpProject\src\Async\delay;
use function SfphpProject\src\Async\syncRun;

class AsyncBasicTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear context before each test
        Context::clear();
    }

    public function testAsyncReturnsTask()
    {
        $task = async(fn () => 42);
        $this->assertInstanceOf(Task::class, $task);
    }

    public function testAwaitSimpleValue()
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            $result = await(async(fn () => 42));
            $this->assertEquals(42, $result);
        } finally {
            Context::popScheduler();
        }
    }

    public function testAwaitMultipleTasks()
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            $task1 = async(fn () => 1);
            $task2 = async(fn () => 2);
            $task3 = async(fn () => 3);

            [$a, $b, $c] = await(
                CompositeFuture::all($task1, $task2, $task3)
            );

            $this->assertEquals(1, $a);
            $this->assertEquals(2, $b);
            $this->assertEquals(3, $c);
        } finally {
            Context::popScheduler();
        }
    }

    public function testExceptionPropagation()
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Test error");

            await(async(function () {
                throw new \Exception("Test error");
            }));
        } finally {
            Context::popScheduler();
        }
    }

    public function testSyncRunExecutesAsync()
    {
        $result = syncRun(async(fn () => 123));
        $this->assertEquals(123, $result);
    }

    public function testDelayWorks()
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            $start = microtime(true);
            await(delay(50)); // 50ms delay
            $elapsed = microtime(true) - $start;

            // Should take at least 50ms
            $this->assertGreaterThanOrEqual(0.05, $elapsed);
        } finally {
            Context::popScheduler();
        }
    }

    public function testConcurrencyReducesTime()
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            $start = microtime(true);

            // Three tasks with delays
            [$a, $b, $c] = await(
                CompositeFuture::all(
                    async(function () {
                        usleep(50000); // 50ms
                        return 'a';
                    }),
                    async(function () {
                        usleep(100000); // 100ms
                        return 'b';
                    }),
                    async(function () {
                        usleep(30000); // 30ms
                        return 'c';
                    }),
                )
            );

            $elapsed = microtime(true) - $start;

            // Sequential would be 180ms (50+100+30)
            // Parallel should be around 100ms (max of three)
            // We allow 150ms for overhead
            $this->assertLessThan(0.15, $elapsed);
            $this->assertEquals('a', $a);
            $this->assertEquals('b', $b);
            $this->assertEquals('c', $c);
        } finally {
            Context::popScheduler();
        }
    }
}
