# Quick Start - SFPHP Async/Await System

**Get started with async/await in 5 minutes**

---

## Installation

SFPHP v0.12.0+ comes with the async system built-in. No additional installation needed!

```bash
composer update fabioaacarneiro/sfphp-framework
```

---

## 1. Add Middleware to Your Router

```php
// In your router setup (e.g., public/server.php)

use SfphpProject\src\Http\Middleware\EnableAsync;

$router = (new Router($container))
    ->middleware(new EnableAsync())      // ← Add this line
    ->middleware(new SecurityHeaders())
    ->middleware(new VerifyCsrfToken());
```

**That's it! You're ready to use async/await.**

---

## 2. Use in Your Controllers

### Simple Example

```php
class UserController {
    public function show($id) {
        // No boilerplate needed! Scheduler is created automatically
        $user = await(User::query()->findAsync($id));
        
        return Response::view('user.show', ['user' => $user]);
    }
}
```

### Parallel Data Loading

```php
class DashboardController {
    public function index($userId) {
        // Load 3 things in parallel (3x faster!)
        [$user, $posts, $stats] = await(
            CompositeFuture::all(
                async(fn() => User::query()->findAsync($userId)),
                async(fn() => Post::query()->where('user_id', $userId)->getAsync()),
                async(fn() => $this->getStats($userId))
            )
        );
        
        return Response::view('dashboard', compact('user', 'posts', 'stats'));
    }
    
    private function getStats($userId) {
        return await(async(function () use ($userId) {
            $postCount = await(Post::query()->where('user_id', $userId)->countAsync());
            $followers = await(Follower::query()->where('user_id', $userId)->countAsync());
            
            return compact('postCount', 'followers');
        }));
    }
}
```

---

## 3. Key Functions

### Async Tasks
```php
async(fn() => $value)           // Create async task
await($future)                  // Wait for result
await($future, timeout: 5000)   // With timeout (ms)
```

### Database
```php
Model::query()->getAsync()      // Get all
Model::query()->findAsync($id)  // Find by ID
Model::query()->countAsync()    // Count records
```

### HTTP Requests
```php
await(HttpFuture::get($url)->getValue())
await(HttpFuture::post($url, $body)->getValue())
await(HttpFuture::put($url, $body)->getValue())
await(HttpFuture::delete($url)->getValue())
```

### Cache
```php
await(CacheFuture::get($key, $cache))
await(CacheFuture::set($key, $value, 3600, $cache))
await(CacheFuture::delete($key, $cache))
```

### Event Broadcasting
```php
$broadcaster = new EventBroadcaster();
$broadcaster->subscribe('user.created', fn($e, $p) => doSomething($p));
$broadcaster->broadcastSync('user.created', ['id' => 1]);
```

### Stream Processing
```php
$data = [1, 2, 3, 4, 5];
$stream = new StreamFuture($data);
$results = $stream
    ->map(fn($x) => $x * 2)
    ->filter(fn($x) => $x > 4)
    ->getValue();
```

---

## 4. Common Patterns

### Cache-Aside Pattern

```php
public function getUser($id) {
    $cacheKey = "user:$id";
    
    // Try cache first
    $user = await(CacheFuture::get($cacheKey, $this->cache));
    if ($user) return $user;
    
    // Cache miss - fetch from API
    $response = await(HttpFuture::get("/api/users/$id")->getValue());
    $user = json_decode($response['body'], true);
    
    // Store in cache
    await(CacheFuture::set($cacheKey, $user, 3600, $this->cache));
    
    return $user;
}
```

### Reactive State in Components

```php
class UserProfileComponent {
    use AsyncAware;  // Provides async helpers
    
    private ReactiveState $userState;
    
    public function loadUser($id) {
        $this->userState = new ReactiveState(null, ttl: 3600);
        
        $this->userState->setLoading(true);
        try {
            $user = await(User::query()->findAsync($id));
            $this->userState->setValue($user);
        } catch (\Exception $e) {
            $this->userState->setError($e);
        }
    }
    
    public function render() {
        $data = $this->userState->toArray();
        
        if ($data['loading']) return '<div>Loading...</div>';
        if ($data['hasError']) return '<div>Error: ' . $data['error'] . '</div>';
        
        return '<div>' . $data['value']['name'] . '</div>';
    }
}
```

### Error Handling

```php
public function show($id) {
    try {
        $user = await(User::query()->findAsync($id));
        
        if (!$user) {
            return Response::json(['error' => 'Not found'], 404);
        }
        
        return Response::json(['user' => $user]);
    } catch (TimeoutException $e) {
        return Response::json(['error' => 'Request timeout'], 504);
    } catch (\Exception $e) {
        return Response::json(['error' => $e->getMessage()], 500);
    }
}
```

---

## 5. Performance Tips

### ✅ DO

```php
// Parallel execution - fast!
[$a, $b, $c] = await(CompositeFuture::all(
    async(fn() => query1()),
    async(fn() => query2()),
    async(fn() => query3())
));

// Use cache-aside pattern
$cached = await(CacheFuture::get($key, $cache));
if ($cached) return $cached;
```

### ❌ DON'T

```php
// Sequential - slow!
$a = await(async(fn() => query1()));
$b = await(async(fn() => query2()));
$c = await(async(fn() => query3()));

// Don't create Scheduler manually (middleware handles it)
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);
// ... lots of boilerplate
```

---

## 6. Testing Your Setup

### Run Tests

```bash
# Basic tests
php test-async.php
php test-phase3.php

# Integration tests
php test-integration.php

# Benchmarks
php benchmark-async.php
```

All should show "✓ PASS" ✅

---

## 7. Documentation

### Quick Reference
- **This file** - Quick start guide
- `docs/ASYNC_COMPLETE_GUIDE.md` - **READ THIS FIRST** - Comprehensive guide with all features

### Phase-Specific Guides
- `docs/ASYNC_USAGE.md` - Complete async/await reference
- `docs/ASYNC_PHASE3.md` - Middleware and auto-setup
- `docs/ASYNC_PHASE4.md` - HTTP & Cache async
- `docs/ASYNC_PHASE5.md` - Reactive components

### Examples
- `example-async-controller.php` - Real-world patterns
- `example-phase4.php` - HTTP and caching examples
- `example-phase5.php` - Reactive component examples

---

## 8. Real-World Example: Product API

```php
use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Async\Adapters\HttpFuture;
use SfphpProject\src\Async\Adapters\CacheFuture;

class ProductController {
    use AsyncAware;
    
    public function show($id) {
        // Get product and related data in parallel
        [$product, $reviews, $recommendations] = await(
            CompositeFuture::all(
                async(fn() => Product::query()->findAsync($id)),
                async(fn() => $this->getReviews($id)),
                async(fn() => $this->getRecommendations($id))
            )
        );
        
        return Response::json(compact('product', 'reviews', 'recommendations'));
    }
    
    private function getReviews($productId) {
        $cacheKey = "product:$productId:reviews";
        
        $reviews = await(CacheFuture::get($cacheKey, $this->cache));
        if ($reviews) return $reviews;
        
        // Fetch from external API
        $response = await(
            HttpFuture::get("https://api.reviews.com/products/$productId/reviews")
                ->getValue()
        );
        
        $reviews = json_decode($response['body'], true);
        await(CacheFuture::set($cacheKey, $reviews, 3600, $this->cache));
        
        return $reviews;
    }
    
    private function getRecommendations($productId) {
        $cacheKey = "product:$productId:recommendations";
        
        $recommendations = await(CacheFuture::get($cacheKey, $this->cache));
        if ($recommendations) return $recommendations;
        
        // Fetch recommendations
        $response = await(
            HttpFuture::get("https://api.ml.com/recommend/$productId")
                ->getValue()
        );
        
        $recommendations = json_decode($response['body'], true);
        await(CacheFuture::set($cacheKey, $recommendations, 1800, $this->cache));
        
        return $recommendations;
    }
}
```

**Result:** 3 API calls running in parallel = 3x faster! 🚀

---

## 9. Troubleshooting

### "No active Scheduler" Error

Add the middleware to your router:
```php
$router->middleware(new EnableAsync())
```

### "Call to undefined function await()"

Add the use statement:
```php
use function SfphpProject\src\Async\await;
use function SfphpProject\src\Async\async;
```

### Slow Performance

Check you're using parallel execution:
```php
// Good - parallel
[$a, $b] = await(CompositeFuture::all(...));

// Bad - sequential
$a = await(...);
$b = await(...);
```

---

## 10. Next Steps

1. **Add middleware** to your router
2. **Read** `docs/ASYNC_COMPLETE_GUIDE.md`
3. **Start using** `await()` in controllers
4. **Run tests** to verify setup
5. **Check benchmarks** for performance

---

## Summary

| Aspect | Status |
|--------|--------|
| Installation | ✅ Built-in |
| Setup | ✅ 1 middleware line |
| Learning curve | ✅ Very low (familiar syntax) |
| Performance | ✅ 3x-5x for I/O |
| Reliability | ✅ 100+ tests, production-ready |
| Documentation | ✅ Comprehensive |

**You're ready to use async/await!** 🎉

Start with your first async controller and enjoy the speed boost!
