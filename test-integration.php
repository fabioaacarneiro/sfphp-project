<?php

/**
 * Integration Tests - Real-world async scenarios
 */

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\AsyncAware;
use SfphpProject\src\Async\Adapters\HttpFuture;
use SfphpProject\src\Async\Adapters\CacheFuture;
use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Async\ComponentFuture;
use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Async\WebSocketFuture;
use SfphpProject\src\Async\StreamFuture;
use SfphpProject\src\Async\EventBroadcaster;
use SfphpProject\src\Async\FileFuture;
use SfphpProject\src\Http\Middleware\EnableAsync;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

echo "=== Integration Tests - Real-World Scenarios ===\n\n";

// Test 1: Controller with auto-setup middleware
echo "Test 1: Controller with EnableAsync middleware... ";
Context::clear();

class UserController {
    use AsyncAware;

    public function show($id) {
        $mockCache = new class {
            private $data = ['user:1' => ['id' => 1, 'name' => 'John']];
            public function get($key) { return $this->data[$key] ?? null; }
            public function set($key, $value, $ttl) { $this->data[$key] = $value; return true; }
            public function delete($key) { unset($this->data[$key]); return true; }
        };

        // Simulated async work
        $user = await(
            async(fn() => $mockCache->get("user:$id") ?: ['id' => $id, 'name' => 'Async User'])
        );

        return Response::json(['user' => $user]);
    }
}

$controller = new UserController();
$middleware = new EnableAsync();
$request = new Request('GET', '/users/1', [], [], [], [], [], []);

$response = $middleware->handle($request, function ($req) use ($controller) {
    return $controller->show(1);
});

assert($response !== null, "Response should not be null");
echo "✓ PASS\n";

// Test 2: Dashboard with parallel data loading
echo "Test 2: Dashboard with parallel data loading... ";
Context::clear();

class DashboardController {
    use AsyncAware;

    public function index($userId) {
        // Simulate parallel loading
        [$user, $stats, $activity] = await(
            CompositeFuture::all(
                async(fn() => ['id' => $userId, 'name' => 'User']),
                async(fn() => ['views' => 1000, 'clicks' => 500]),
                async(fn() => ['recent' => []])
            )
        );

        return Response::json(compact('user', 'stats', 'activity'));
    }
}

$controller = new DashboardController();
$middleware = new EnableAsync();

$response = $middleware->handle($request, function ($req) use ($controller) {
    return $controller->index(1);
});

assert($response !== null, "Response should not be null");
echo "✓ PASS\n";

// Test 3: Reactive component state management
echo "Test 3: Reactive component with state... ";
Context::clear();

class UserProfileComponent {
    use AsyncAware;
    private ReactiveState $userState;

    public function __construct() {
        $this->userState = new ReactiveState(null, ttl: 3600);
    }

    public function loadUser($id) {
        $this->userState->setLoading(true);
        try {
            $user = await(async(fn() => ['id' => $id, 'name' => 'Loaded User']));
            $this->userState->setValue($user);
        } catch (\Exception $e) {
            $this->userState->setError($e);
        }
    }

    public function getState() {
        return $this->userState->toArray();
    }
}

$component = new UserProfileComponent();
$middleware = new EnableAsync();

$middleware->handle($request, function ($req) use ($component) {
    $component->loadUser(1);
    return Response::json(['ok' => true]);
});

$state = $component->getState();
assert($state['value']['id'] === 1, "User should be loaded");
echo "✓ PASS\n";

// Test 4: Cache invalidation on state change
echo "Test 4: Cache invalidation on state change... ";
Context::clear();

$mockCache = new class {
    public $deleted = [];
    public function delete($key) { $this->deleted[] = $key; return true; }
};

$invalidator = new CacheInvalidator($mockCache);
$state = new ReactiveState(['id' => 1]);

$invalidator->invalidateOnStateChange($state, ['user:1', 'user:1:posts']);
$state->setValue(['id' => 1, 'updated' => true]);

assert(count($mockCache->deleted) === 2, "Should invalidate 2 keys");
echo "✓ PASS\n";

// Test 5: Component with automatic retry
echo "Test 5: Component rendering with retry... ";
Context::clear();

$callCount = 0;
$future = new ComponentFuture(
    function () use (&$callCount) {
        $callCount++;
        if ($callCount < 3) {
            throw new \Exception("Render failed");
        }
        return '<div>Success</div>';
    },
    maxRetries: 2
);

$middleware = new EnableAsync();
$result = $middleware->handle($request, function ($req) use ($future) {
    return Response::json(['html' => $future->getValue()]);
});

assert($callCount === 3, "Should retry twice and succeed");
echo "✓ PASS\n";

// Test 6: Event broadcasting
echo "Test 6: Event broadcasting... ";

$broadcaster = new EventBroadcaster();
$eventData = [];

$broadcaster->subscribe('user.created', function ($event, $payload) use (&$eventData) {
    $eventData[] = ['event' => $event, 'data' => $payload];
    return true;
});

$result = $broadcaster->broadcastSync('user.created', ['id' => 1, 'name' => 'New User']);

assert(count($eventData) === 1, "Should broadcast event");
assert($eventData[0]['event'] === 'user.created', "Event name should match");
echo "✓ PASS\n";

// Test 7: Stream processing
echo "Test 7: Stream processing... ";
Context::clear();

$data = [1, 2, 3, 4, 5];
$stream = new StreamFuture($data);
$stream->map(fn($x) => $x * 2)
       ->filter(fn($x) => $x > 4);

$result = $stream->getValue();
assert(count($result) === 3, "Should process stream correctly");
echo "✓ PASS\n";

// Test 8: File operations
echo "Test 8: File operations async... ";
Context::clear();

$testFile = '/tmp/sfphp_test_' . uniqid() . '.txt';

// Write file
$writeResult = await(async(fn() => FileFuture::write($testFile, 'Test content')->getValue()));
assert($writeResult > 0, "Write should succeed");

// Read file
$readResult = await(async(fn() => FileFuture::read($testFile)->getValue()));
assert($readResult === 'Test content', "Content should match");

// Check exists
$exists = await(async(fn() => FileFuture::exists($testFile)->getValue()));
assert($exists === true, "File should exist");

// Delete file
$deleteResult = await(async(fn() => FileFuture::delete($testFile)->getValue()));
assert($deleteResult === true, "Delete should succeed");

echo "✓ PASS\n";

// Test 9: Multiple sequential async operations
echo "Test 9: Sequential async operations... ";
Context::clear();

$middleware = new EnableAsync();
$results = [];

$middleware->handle($request, function ($req) use (&$results) {
    for ($i = 1; $i <= 3; $i++) {
        $val = await(async(fn() => $i * 100));
        $results[] = $val;
    }
    return Response::json(['ok' => true]);
});

assert(count($results) === 3, "Should execute 3 operations");
assert($results === [100, 200, 300], "Results should be correct");
echo "✓ PASS\n";

// Test 10: Error handling in async pipeline
echo "Test 10: Error handling in pipeline... ";
Context::clear();

$errorCaught = false;
$middleware = new EnableAsync();

try {
    $middleware->handle($request, function ($req) {
        $result = await(async(fn() => throw new \Exception("Pipeline error")));
        return Response::json(['result' => $result]);
    });
} catch (\Exception $e) {
    $errorCaught = true;
}

assert($errorCaught === true, "Should catch error from pipeline");
echo "✓ PASS\n";

// Test 11: Service with AsyncAware trait
echo "Test 11: Service using AsyncAware trait... ";
Context::clear();

class UserService {
    use AsyncAware;

    public function getUser($id) {
        return $this->withAsync(function () use ($id) {
            return await(async(fn() => ['id' => $id, 'name' => 'User']));
        });
    }
}

$service = new UserService();
$middleware = new EnableAsync();

$user = $middleware->handle($request, function ($req) use ($service) {
    return Response::json(['user' => $service->getUser(1)]);
});

assert($user !== null, "Should return user from service");
echo "✓ PASS\n";

// Test 12: Complex async composition
echo "Test 12: Complex async composition... ";
Context::clear();

$middleware = new EnableAsync();

$result = $middleware->handle($request, function ($req) {
    $val1 = await(async(fn() => 10));
    $val2 = await(async(fn() => $val1 * 2));
    $val3 = await(async(fn() => $val2 + 5));
    return Response::json(['result' => $val3]);
});

assert($result !== null, "Should process composition");
echo "✓ PASS\n";

echo "\n=== All Integration Tests Passed! ===\n";
echo "\nCoverage:\n";
echo "✅ EnableAsync middleware auto-setup\n";
echo "✅ Parallel data loading with CompositeFuture\n";
echo "✅ Reactive component state management\n";
echo "✅ Cache invalidation on state changes\n";
echo "✅ Component rendering with retry logic\n";
echo "✅ Event broadcasting with pub/sub\n";
echo "✅ Stream processing with map/filter\n";
echo "✅ Async file I/O operations\n";
echo "✅ Sequential async operations\n";
echo "✅ Error handling in pipelines\n";
echo "✅ Services with AsyncAware trait\n";
echo "✅ Chained futures\n";
echo "\nIntegration tests complete! Ready for production.\n";
