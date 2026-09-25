<?php

namespace Tests;

use SfphpProject\src\Testing\TestCase;
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
        $this->assertTrue($task instanceof Task);
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
            $this->assertThrows(fn () => await(async(function () {
                throw new \Exception("Test error");
            })), \Exception::class);
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
            $this->assertTrue($elapsed >= 0.05);
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

            // Three tasks waiting on timers. usleep() would block the process and
            // nothing could overlap; delay() is the loop's, so the three wait together.
            [$a, $b, $c] = await(
                CompositeFuture::all(
                    async(function () {
                        await(delay(50)); // 50ms, without blocking
                        return 'a';
                    }),
                    async(function () {
                        await(delay(100)); // 100ms, without blocking
                        return 'b';
                    }),
                    async(function () {
                        await(delay(30)); // 30ms, without blocking
                        return 'c';
                    }),
                )
            );

            $elapsed = microtime(true) - $start;

            // Sequential would be 180ms (50+100+30)
            // Parallel should be around 100ms (max of three)
            // We allow 150ms for overhead
            $this->assertTrue($elapsed < 0.15);
            $this->assertEquals('a', $a);
            $this->assertEquals('b', $b);
            $this->assertEquals('c', $c);
        } finally {
            Context::popScheduler();
        }
    }
}
