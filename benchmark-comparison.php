<?php

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

echo "=== Real Benchmark: Sequential vs Parallel I/O ===\n\n";

// Test 1: Sequential
echo "Test 1: SEQUENTIAL (3 x 100ms operations)\n";
$start = microtime(true);

await(async(fn() => usleep(100000))); // 100ms
await(async(fn() => usleep(100000))); // 100ms
await(async(fn() => usleep(100000))); // 100ms

$seq_ms = round((microtime(true) - $start) * 1000, 1);
echo "Time: ${seq_ms}ms (expected: ~300ms)\n\n";

// Test 2: Parallel
echo "Test 2: PARALLEL (3 x 100ms operations)\n";
$start = microtime(true);

await(CompositeFuture::all(
    async(fn() => usleep(100000)), // 100ms
    async(fn() => usleep(100000)), // 100ms
    async(fn() => usleep(100000))  // 100ms
));

$par_ms = round((microtime(true) - $start) * 1000, 1);
echo "Time: ${par_ms}ms (expected: ~100ms)\n\n";

// Results
$speedup = round($seq_ms / $par_ms, 2);
echo "Speedup: ${speedup}x\n";
echo "Improvement: " . round((($seq_ms - $par_ms) / $seq_ms) * 100, 1) . "%\n";

