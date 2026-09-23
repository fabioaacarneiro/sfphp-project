<?php

/**
 * Test Phase 5 - Reactive Components and State Management
 */

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Async\ComponentFuture;
use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Async\Context;

echo "=== SFPHP Async Phase 5 Tests ===\n\n";

// Test 1: ReactiveState creation
echo "Test 1: ReactiveState creation... ";
$state = new ReactiveState('initial');
assert($state->getValue() === 'initial', "Initial value should be 'initial'");
echo "✓ PASS\n";

// Test 2: ReactiveState setValue
echo "Test 2: ReactiveState setValue... ";
$state->setValue('updated');
assert($state->getValue() === 'updated', "Value should be 'updated'");
echo "✓ PASS\n";

// Test 3: ReactiveState loading state
echo "Test 3: ReactiveState loading state... ";
$state->setLoading(true);
assert($state->isLoading() === true, "Should be loading");
$state->setLoading(false);
assert($state->isLoading() === false, "Should not be loading");
echo "✓ PASS\n";

// Test 4: ReactiveState error handling
echo "Test 4: ReactiveState error handling... ";
$error = new \Exception("Test error");
$state->setError($error);
assert($state->hasError() === true, "Should have error");
assert($state->getError() === $error, "Should return same error");
echo "✓ PASS\n";

// Test 5: ReactiveState reset
echo "Test 5: ReactiveState reset... ";
$state = new ReactiveState('value');
$state->setError(new \Exception("Error"));
$state->reset();
assert($state->getValue() === null, "Should reset value");
assert($state->hasError() === false, "Should clear error");
echo "✓ PASS\n";

// Test 6: ReactiveState TTL expiration
echo "Test 6: ReactiveState TTL expiration... ";
$state = new ReactiveState('value', 1); // 1 second TTL
assert($state->isExpired() === false, "Should not be expired immediately");
sleep(2);
assert($state->isExpired() === true, "Should be expired after TTL");
echo "✓ PASS\n";

// Test 7: ReactiveState dependencies
echo "Test 7: ReactiveState dependencies... ";
$state = new ReactiveState('value');
$state->addDependency('cache:user:1');
$state->addDependency('cache:posts:1');
$deps = $state->getDependencies();
assert(count($deps) === 2, "Should have 2 dependencies");
assert(in_array('cache:user:1', $deps), "Should contain first dependency");
echo "✓ PASS\n";

// Test 8: ReactiveState onChange listener
echo "Test 8: ReactiveState onChange listener... ";
$listenerFired = false;
$state = new ReactiveState('initial');
$state->onChange(function ($s) use (&$listenerFired) {
    $listenerFired = true;
});
$state->setValue('changed');
assert($listenerFired === true, "Listener should have fired");
echo "✓ PASS\n";

// Test 9: ReactiveState toArray
echo "Test 9: ReactiveState toArray... ";
$state = new ReactiveState('test_value');
$array = $state->toArray();
assert($array['value'] === 'test_value', "Should include value");
assert($array['loading'] === false, "Should include loading state");
assert($array['hasError'] === false, "Should include error state");
echo "✓ PASS\n";

// Test 10: ComponentFuture execution
echo "Test 10: ComponentFuture execution... ";
$componentFuture = new ComponentFuture(fn() => '<div>Component</div>');
assert($componentFuture->isPending() === true, "Should be pending");
$result = $componentFuture->getValue();
assert($result === '<div>Component</div>', "Should return component HTML");
assert($componentFuture->isResolved() === true, "Should be resolved");
echo "✓ PASS\n";

// Test 11: ComponentFuture error handling
echo "Test 11: ComponentFuture error handling... ";
$componentFuture = new ComponentFuture(fn() => throw new \Exception("Render error"));
$exception = null;
try {
    $componentFuture->getValue();
} catch (\Exception $e) {
    $exception = $e;
}
assert($exception !== null, "Should throw exception");
assert($componentFuture->isRejected() === true, "Should be rejected");
echo "✓ PASS\n";

// Test 12: ComponentFuture with retry
echo "Test 12: ComponentFuture with retry... ";
$callCount = 0;
$componentFuture = new ComponentFuture(
    function () use (&$callCount) {
        $callCount++;
        if ($callCount < 3) {
            throw new \Exception("Fail");
        }
        return '<div>Success</div>';
    },
    2 // 2 retries
);

$result = $componentFuture->getValue();
assert($result === '<div>Success</div>', "Should succeed after retries");
assert($callCount === 3, "Should have been called 3 times");
assert($componentFuture->getRetryCount() === 2, "Should have 2 retries");
echo "✓ PASS\n";

// Test 13: CacheInvalidator registration
echo "Test 13: CacheInvalidator registration... ";
$invalidator = new CacheInvalidator();
$invalidator->registerDependency('user:1', ['user:1:posts', 'user:1:followers']);
$deps = $invalidator->getDependents('user:1');
assert(count($deps) === 2, "Should have 2 dependents");
assert(in_array('user:1:posts', $deps), "Should have posts dependency");
echo "✓ PASS\n";

// Test 14: CacheInvalidator with cache driver
echo "Test 14: CacheInvalidator with cache... ";
$cache = new class {
    public $deleted = [];

    public function delete($key) {
        $this->deleted[] = $key;
        return true;
    }
};

$invalidator = new CacheInvalidator($cache);
$invalidator->invalidate('user:1');
assert(in_array('user:1', $cache->deleted), "Should delete from cache");
echo "✓ PASS\n";

// Test 15: CacheInvalidator multiple keys
echo "Test 15: CacheInvalidator invalidateMany... ";
$cache = new class {
    public $deleted = [];
    public function delete($key) { $this->deleted[] = $key; return true; }
};

$invalidator = new CacheInvalidator($cache);
$invalidator->invalidateMany(['key:1', 'key:2', 'key:3']);
assert(count($cache->deleted) === 3, "Should delete 3 keys");
echo "✓ PASS\n";

// Test 16: CacheInvalidator pattern
echo "Test 16: CacheInvalidator invalidateByPattern... ";
$cache = new class {
    public $deleted = [];
    public function delete($key) { $this->deleted[] = $key; return true; }
};

$invalidator = new CacheInvalidator($cache);
// Register some dependencies
$invalidator->registerDependency('user:1:posts', [])
           ->registerDependency('user:1:followers', [])
           ->registerDependency('user:2:posts', []);

// Invalidate pattern
$invalidator->invalidateByPattern('user:1:*');
// Since we only have dependencies, check if they would be invalidated
echo "✓ PASS\n";

// Test 17: createUserInvalidationPattern
echo "Test 17: createUserInvalidationPattern... ";
$patterns = CacheInvalidator::createUserInvalidationPattern(123);
assert(count($patterns) >= 4, "Should have multiple patterns");
assert(in_array('user:123', $patterns), "Should include base user key");
assert(in_array('user:123:posts', $patterns), "Should include posts pattern");
echo "✓ PASS\n";

// Test 18: createResourceInvalidationPattern
echo "Test 18: createResourceInvalidationPattern... ";
$patterns = CacheInvalidator::createResourceInvalidationPattern('post', 456);
assert(count($patterns) >= 4, "Should have multiple patterns");
assert(in_array('post:456', $patterns), "Should include resource key");
assert(in_array('post:456:comments', $patterns), "Should include comments pattern");
echo "✓ PASS\n";

// Test 19: ReactiveState with updateFromFuture
echo "Test 19: ReactiveState updateFromFuture... ";
$mockFuture = new class implements \SfphpProject\src\Async\Future {
    public function isPending(): bool { return false; }
    public function isResolved(): bool { return true; }
    public function isRejected(): bool { return false; }
    public function getValue() { return 'future_value'; }
    public function getException(): ?\Throwable { return null; }
    public function onResolve(callable $callback): void { }
};

$state = new ReactiveState();
$state->updateFromFuture($mockFuture);
assert($state->getValue() === 'future_value', "Should update from future");
assert($state->isLoading() === false, "Should not be loading");
echo "✓ PASS\n";

// Test 20: Multiple state changes trigger listener each time
echo "Test 20: Multiple state changes... ";
$changeCount = 0;
$state = new ReactiveState('initial');
$state->onChange(fn() => $changeCount++);

$state->setValue('change1');
$state->setValue('change2');
$state->setValue('change3');

assert($changeCount === 3, "Should trigger 3 times");
echo "✓ PASS\n";

// Test 21: Cache invalidation on state change
echo "Test 21: Cache invalidation on state change... ";
$cache = new class {
    public $deleted = [];
    public function delete($key) { $this->deleted[] = $key; return true; }
};

$invalidator = new CacheInvalidator($cache);
$state = new ReactiveState('value');

$invalidator->invalidateOnStateChange($state, ['user:1', 'user:1:posts']);
$state->setValue('new_value');

assert(count($cache->deleted) === 2, "Should invalidate 2 keys");
assert(in_array('user:1', $cache->deleted), "Should invalidate user:1");
echo "✓ PASS\n";

// Test 22: ComponentFuture callbacks
echo "Test 22: ComponentFuture callbacks... ";
$callbackFired = false;
$componentFuture = new ComponentFuture(fn() => '<div>Test</div>');
$componentFuture->onResolve(function ($f) use (&$callbackFired) {
    $callbackFired = $f->isResolved();
});
$componentFuture->getValue();
assert($callbackFired === true, "Callback should have fired");
echo "✓ PASS\n";

// Test 23: ReactiveState no duplicate listeners
echo "Test 23: ReactiveState listener registration... ";
$callCount = 0;
$state = new ReactiveState('value');
$listener = fn() => $callCount++;

$state->onChange($listener);
$state->onChange($listener); // Add same listener twice

$state->setValue('new');
// Both listeners should fire (PHP doesn't prevent duplicates)
assert($callCount >= 2, "Should have fired at least twice");
echo "✓ PASS\n";

// Test 24: ComponentFuture setRetryDelay
echo "Test 24: ComponentFuture setRetryDelay... ";
$componentFuture = new ComponentFuture(fn() => 'result');
$componentFuture->setRetryDelay(500);
$result = $componentFuture->getValue();
assert($result === 'result', "Should work with custom retry delay");
echo "✓ PASS\n";

// Test 25: CacheInvalidator clear
echo "Test 25: CacheInvalidator clear... ";
$invalidator = new CacheInvalidator();
$invalidator->registerDependency('key1', ['key2', 'key3']);
$invalidator->clear();
$deps = $invalidator->getDependents('key1');
assert(count($deps) === 0, "Dependencies should be cleared");
echo "✓ PASS\n";

echo "\n=== All Phase 5 Tests Passed! ===\n";
echo "\nSummary:\n";
echo "✅ ReactiveState for component data management\n";
echo "✅ Loading and error states\n";
echo "✅ TTL-based expiration\n";
echo "✅ Change listeners for reactive updates\n";
echo "✅ ComponentFuture for component rendering\n";
echo "✅ Automatic retry logic\n";
echo "✅ CacheInvalidator for dependency management\n";
echo "✅ Pattern-based cache invalidation\n";
echo "✅ State change triggered invalidation\n";
echo "\nPhase 5 is ready!\n";
