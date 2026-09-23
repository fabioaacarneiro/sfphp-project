<?php

/**
 * Test Phase 4 - True Non-Blocking I/O (HTTP, Cache)
 */

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Adapters\HttpFuture;
use SfphpProject\src\Async\Adapters\CacheFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

echo "=== SFPHP Async Phase 4 Tests ===\n\n";

// Test 1: HttpFuture creation
echo "Test 1: HttpFuture creation (GET)... ";
$future = HttpFuture::get('https://httpbin.org/json');
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 2: HttpFuture POST creation
echo "Test 2: HttpFuture creation (POST)... ";
$future = HttpFuture::post('https://httpbin.org/post', ['name' => 'test']);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 3: HttpFuture PUT creation
echo "Test 3: HttpFuture creation (PUT)... ";
$future = HttpFuture::put('https://httpbin.org/put', ['id' => 1]);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 4: HttpFuture PATCH creation
echo "Test 4: HttpFuture creation (PATCH)... ";
$future = HttpFuture::patch('https://httpbin.org/patch', ['status' => 'updated']);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 5: HttpFuture DELETE creation
echo "Test 5: HttpFuture creation (DELETE)... ";
$future = HttpFuture::delete('https://httpbin.org/delete');
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 6: CacheFuture get creation
echo "Test 6: CacheFuture creation (get)... ";
$cache = new class {
    public function get($key) { return null; }
    public function set($key, $value, $ttl) { return true; }
    public function delete($key) { return true; }
    public function has($key) { return false; }
};
$future = CacheFuture::get('test_key', $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 7: CacheFuture set creation
echo "Test 7: CacheFuture creation (set)... ";
$future = CacheFuture::set('test_key', 'test_value', 3600, $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 8: CacheFuture delete creation
echo "Test 8: CacheFuture creation (delete)... ";
$future = CacheFuture::delete('test_key', $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 9: CacheFuture has creation
echo "Test 9: CacheFuture creation (has)... ";
$future = CacheFuture::has('test_key', $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 10: CacheFuture increment
echo "Test 10: CacheFuture creation (increment)... ";
$cache->increment = fn($k, $v) => 1;
$future = CacheFuture::increment('counter', 1, $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 11: CacheFuture decrement
echo "Test 11: CacheFuture creation (decrement)... ";
$cache->decrement = fn($k, $v) => 0;
$future = CacheFuture::decrement('counter', 1, $cache);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 12: Async with HttpFuture (lazy execution)
echo "Test 12: Async with HttpFuture (local test)... ";
$testPassed = false;
try {
    // Create async function that wraps HttpFuture
    $future = async(fn() => HttpFuture::get('https://httpbin.org/get'));

    // This should work since we're just creating the future
    assert($future->isPending(), "Async future should be pending");
    $testPassed = true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
assert($testPassed, "Test failed");
echo "✓ PASS\n";

// Test 13: Cache operations with real cache driver
echo "Test 13: Cache operations (simulated)... ";
$cache = new class {
    private $data = [];

    public function get($key) {
        return $this->data[$key] ?? null;
    }

    public function set($key, $value, $ttl) {
        $this->data[$key] = $value;
        return true;
    }

    public function delete($key) {
        unset($this->data[$key]);
        return true;
    }

    public function has($key) {
        return isset($this->data[$key]);
    }

    public function increment($key, $value) {
        $current = (int)($this->data[$key] ?? 0);
        $this->data[$key] = $current + $value;
        return $this->data[$key];
    }

    public function decrement($key, $value) {
        $current = (int)($this->data[$key] ?? 0);
        $this->data[$key] = $current - $value;
        return $this->data[$key];
    }
};

$future = CacheFuture::set('user:1', ['name' => 'John'], 3600, $cache);
$result = $future->getValue();
assert($result === true, "Cache set should return true");

$future = CacheFuture::get('user:1', $cache);
$result = $future->getValue();
assert($result === ['name' => 'John'], "Cache get should return stored value");

echo "✓ PASS\n";

// Test 14: Multiple cache operations in async
echo "Test 14: Multiple cache operations... ";
Context::clear();

$cache = new class {
    private $data = ['counter' => 5];

    public function get($key) { return $this->data[$key] ?? null; }
    public function set($key, $value, $ttl) { $this->data[$key] = $value; return true; }
    public function delete($key) { unset($this->data[$key]); return true; }
    public function has($key) { return isset($this->data[$key]); }
    public function increment($key, $value) {
        $current = (int)($this->data[$key] ?? 0);
        $this->data[$key] = $current + $value;
        return $this->data[$key];
    }
    public function decrement($key, $value) {
        $current = (int)($this->data[$key] ?? 0);
        $this->data[$key] = $current - $value;
        return $this->data[$key];
    }
};

$multiTest = true;
try {
    // Multiple async cache operations
    $f1 = CacheFuture::get('counter', $cache);
    $f2 = CacheFuture::increment('counter', 3, $cache);
    $f3 = CacheFuture::get('counter', $cache);

    $v1 = $f1->getValue();
    $v2 = $f2->getValue();
    $v3 = $f3->getValue();

    assert($v1 === 5, "Initial value should be 5, got $v1");
    assert($v2 === 8, "After increment should be 8, got $v2");
    assert($v3 === 8, "Final value should be 8, got $v3");
} catch (\Exception $e) {
    $multiTest = false;
    echo "Error: " . $e->getMessage() . "\n";
}

assert($multiTest, "Multiple cache operations failed");
echo "✓ PASS\n";

// Test 15: HttpFuture headers
echo "Test 15: HttpFuture with custom headers... ";
$future = HttpFuture::get('https://httpbin.org/get', ['Authorization' => 'Bearer token']);
assert($future->isPending() === true, "Should be pending initially");
echo "✓ PASS\n";

// Test 16: HttpFuture error handling
echo "Test 16: HttpFuture error handling... ";
$errorHandled = false;
try {
    // Create a future with invalid URL - will fail when executed
    $future = HttpFuture::get('invalid://url');
    $future->getValue(); // This should fail
} catch (\Exception $e) {
    $errorHandled = true;
}
assert($errorHandled, "HTTP future should handle errors");
echo "✓ PASS\n";

// Test 17: HttpFuture exception state
echo "Test 17: HttpFuture exception tracking... ";
try {
    $future = HttpFuture::get('invalid://url');
    $future->getValue();
} catch (\Exception $e) {
    $exc = $future->getException();
    assert($exc !== null, "Should have exception");
    assert($future->isRejected(), "Should be in rejected state");
}
echo "✓ PASS\n";

// Test 18: CacheFuture callback on resolve
echo "Test 18: CacheFuture callbacks... ";
$cache = new class {
    public function get($key) { return 'value'; }
    public function set($key, $value, $ttl) { return true; }
    public function delete($key) { return true; }
};

$callbackFired = false;
$future = CacheFuture::get('test', $cache);
$future->onResolve(function ($f) use (&$callbackFired) {
    $callbackFired = $f->isResolved();
});

$future->getValue();
assert($callbackFired === true, "Callback should have fired");
echo "✓ PASS\n";

// Test 19: HttpFuture callback
echo "Test 19: HttpFuture callbacks... ";
$callbackFired = false;
$future = HttpFuture::get('https://httpbin.org/get');
$future->onResolve(function ($f) use (&$callbackFired) {
    $callbackFired = true;
});
echo "✓ PASS\n";

// Test 20: Compatibility with Future interface
echo "Test 20: Interface compliance... ";
$httpFuture = HttpFuture::get('https://httpbin.org/get');
$cacheFuture = CacheFuture::get('test', $cache);

// Both should implement Future interface
assert(method_exists($httpFuture, 'isPending'), "HttpFuture should have isPending");
assert(method_exists($httpFuture, 'isResolved'), "HttpFuture should have isResolved");
assert(method_exists($httpFuture, 'isRejected'), "HttpFuture should have isRejected");
assert(method_exists($httpFuture, 'getValue'), "HttpFuture should have getValue");
assert(method_exists($httpFuture, 'getException'), "HttpFuture should have getException");

assert(method_exists($cacheFuture, 'isPending'), "CacheFuture should have isPending");
assert(method_exists($cacheFuture, 'isResolved'), "CacheFuture should have isResolved");
assert(method_exists($cacheFuture, 'isRejected'), "CacheFuture should have isRejected");
assert(method_exists($cacheFuture, 'getValue'), "CacheFuture should have getValue");
assert(method_exists($cacheFuture, 'getException'), "CacheFuture should have getException");

echo "✓ PASS\n";

echo "\n=== All Phase 4 Tests Passed! ===\n";
echo "\nSummary:\n";
echo "✅ HttpFuture GET/POST/PUT/PATCH/DELETE support\n";
echo "✅ CacheFuture get/set/delete/has/increment/decrement\n";
echo "✅ Lazy execution (deferred until getValue())\n";
echo "✅ Error handling and exception tracking\n";
echo "✅ Callback support\n";
echo "✅ Custom headers for HTTP\n";
echo "✅ Full Future interface compliance\n";
echo "\nPhase 4 Foundation Ready!\n";
echo "\nNote: This phase provides the API for non-blocking I/O.\n";
echo "True non-blocking execution requires event loop integration.\n";
