<?php

require_once 'vendor/autoload.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Scheduler;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;
use function SfphpProject\src\Async\syncRun;

echo "=== SFPHP Async Test Suite ===\n\n";

// Test 1: Simple async/await
echo "Test 1: Simple async/await... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $result = await(async(fn () => 42));
    assert($result === 42, "Expected 42, got $result");
    echo "✓ PASS\n";
} finally {
    Context::popScheduler();
}

// Test 2: Multiple tasks in parallel
echo "Test 2: Multiple tasks (all)... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $task1 = async(fn () => 'a');
    $task2 = async(fn () => 'b');
    $task3 = async(fn () => 'c');

    [$a, $b, $c] = await(
        CompositeFuture::all($task1, $task2, $task3)
    );

    assert($a === 'a' && $b === 'b' && $c === 'c', "Results mismatch");
    echo "✓ PASS\n";
} finally {
    Context::popScheduler();
}

// Test 3: Exception handling
echo "Test 3: Exception handling... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $failed = false;
    try {
        await(async(function () {
            throw new \Exception("Test error");
        }));
    } catch (\Exception $e) {
        $failed = ($e->getMessage() === "Test error");
    }

    assert($failed, "Exception was not caught");
    echo "✓ PASS\n";
} finally {
    Context::popScheduler();
}

// Test 4: Concurrency (time-based)
echo "Test 4: Concurrency timing... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $start = microtime(true);

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

    // Sequential would be 180ms, concurrent should be ~100-120ms
    assert($elapsed < 0.15, "Took too long: {$elapsed}s");
    assert($a === 'a' && $b === 'b' && $c === 'c', "Results mismatch");
    echo "✓ PASS ({$elapsed}s, expected ~0.1s)\n";
} finally {
    Context::popScheduler();
}

// Test 5: syncRun
echo "Test 5: syncRun function... ";
try {
    $result = syncRun(async(fn () => 999));
    assert($result === 999, "Expected 999, got $result");
    echo "✓ PASS\n";
} catch (\Throwable $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
}

// Test 6: Nested async
echo "Test 6: Nested async operations... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $task = async(function () {
        $inner = await(async(fn () => 100));
        return $inner * 2;
    });

    $result = await($task);
    assert($result === 200, "Expected 200, got $result");
    echo "✓ PASS\n";
} finally {
    Context::popScheduler();
}

// Test 7: Race (first to complete)
echo "Test 7: Future::race... ";
Context::clear();
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    $start = microtime(true);

    $results = await(
        CompositeFuture::race(
            async(function () {
                usleep(200000); // 200ms - slow
                return 'slow';
            }),
            async(function () {
                usleep(50000); // 50ms - fast
                return 'fast';
            }),
        )
    );

    $elapsed = microtime(true) - $start;

    // Race should complete in ~50ms (the fastest), not 200ms
    assert($elapsed < 0.15, "Race took too long: {$elapsed}s");
    // Note: In race, we get array but only first result matters
    echo "✓ PASS ({$elapsed}s, expected ~0.05s)\n";
} finally {
    Context::popScheduler();
}

echo "\n=== All Tests Passed! ===\n";
