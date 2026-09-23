# SFPHP Async/Await - Usage Guide

## Overview

SFPHP includes a **native async/await system** using PHP 8.1+ Fibers. Write asynchronous code without callbacks, and load multiple database queries concurrently.

```php
// Load user profile, posts, and comments in parallel
[$user, $posts, $comments] = await(
    Future::all(
        async(fn () => User::query()->findAsync(1)),
        async(fn () => Post::query()->where('user_id', 1)->getAsync()),
        async(fn () => Comment::query()->where('user_id', 1)->getAsync()),
    )
);
```

**No dependencies.** Uses only PHP's native Fibers.

---

## Quick Start

### 1. Basic Usage

```php
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;
use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Scheduler;

// Setup
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);

try {
    // Create an async task
    $task = async(fn () => 42);

    // Await the result
    $result = await($task);
    echo $result; // 42

} finally {
    Context::popScheduler();
}
```

### 2. Query Database Asynchronously

```php
// Single query
$user = await(User::query()->findAsync(1));

// Multiple queries in parallel
[$user, $posts] = await(Future::all(
    User::query()->findAsync(1),
    Post::query()->where('user_id', 1)->getAsync(),
));
```

### 3. In Controllers

```php
class UserController
{
    public function show(int $id)
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Load data concurrently
            $user = await(User::query()->findAsync($id));
            $posts = await(Post::query()->where('user_id', $id)->getAsync());

            return $this->view('user.show', ['user' => $user, 'posts' => $posts]);
        } finally {
            Context::popScheduler();
        }
    }
}
```

---

## API Reference

### `async(callable): Task`

Create an async task from a callable.

```php
$task = async(function () {
    return User::query()->find(1);
});

$user = await($task);
```

### `await(Future, ?int $timeout): mixed`

Await a Future's result. Suspends the current Fiber until resolved.

```php
$result = await($future);

// With timeout (milliseconds)
try {
    $result = await($future, timeout: 5000);
} catch (TimeoutException $e) {
    // Timeout exceeded
}
```

### `Future::all(Future ...$futures): Future`

Await multiple Futures concurrently (waits for all to complete).

```php
[$user, $posts, $comments] = await(Future::all(
    $userTask,
    $postsTask,
    $commentsTask,
));
```

### `Future::race(Future ...$futures): Future`

Await multiple Futures and return when first completes.

```php
$result = await(Future::race(
    async(fn () => slow_operation()),
    delay(5000)->then(fn () => throw new TimeoutException()),
));
```

### Database Query Async Methods

These methods are available on `ModelQuery`:

```php
// Get multiple rows
$users = await(User::query()->where('active', true)->getAsync());

// Get first row
$user = await(User::query()->where('id', 1)->firstAsync());

// Find by ID
$user = await(User::query()->findAsync(1));

// Count rows
$count = await(User::query()->where('active', true)->countAsync());
```

---

## Examples

### Example 1: Dashboard with Parallel Loading

```php
function showDashboard(int $userId)
{
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);

    try {
        // Load all sections in parallel
        [$user, $appointments, $notifications] = await(
            Future::all(
                async(fn () => User::query()->findAsync($userId)),
                async(fn () => Appointment::query()
                    ->where('user_id', $userId)
                    ->where('date', '>=', now())
                    ->getAsync()
                ),
                async(fn () => Notification::query()
                    ->where('user_id', $userId)
                    ->where('read', false)
                    ->getAsync()
                ),
            )
        );

        return render('dashboard', compact('user', 'appointments', 'notifications'));
    } finally {
        Context::popScheduler();
    }
}

// Before: 300ms (3 queries × 100ms each, sequential)
// After:  100ms (max of 100ms, with concurrency)
```

### Example 2: Search with Eager Loading

```php
function searchUsers(string $query)
{
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);

    try {
        // Find users
        $users = await(User::query()
            ->whereLike('name', "%$query%")
            ->limit(10)
            ->getAsync()
        );

        // Load posts for each user in parallel
        $userIds = array_map(fn ($u) => $u->id, $users);
        
        $postsByUser = await(Future::all(
            ...array_map(
                fn ($id) => async(fn () => Post::query()
                    ->where('user_id', $id)
                    ->limit(5)
                    ->getAsync()
                ),
                $userIds
            )
        ));

        return compact('users', 'postsByUser');
    } finally {
        Context::popScheduler();
    }
}
```

### Example 3: Component with Async Data

```php
// Component (would be compiled from .phpx)
function UserProfile($userId)
{
    // Components automatically get a Scheduler
    $user = await(User::query()->findAsync($userId));
    $posts = await(Post::query()->where('user_id', $userId)->getAsync());

    return view('profile', ['user' => $user, 'posts' => $posts]);
}
```

---

## Performance

### Concurrency vs. Sequential

**Sequential (without async):**
```
Database time: 100ms + 150ms + 50ms = 300ms
```

**Concurrent (with async):**
```
Database time: max(100ms, 150ms, 50ms) = 150ms
→ 50% faster!
```

The database can process multiple queries in parallel. Async lets PHP initiate all queries before waiting for any of them.

---

## Error Handling

### Exceptions in Async Tasks

```php
try {
    $user = await(async(function () {
        throw new Exception("Database error");
    }));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Timeout Exception

```php
use SfphpProject\src\Async\TimeoutException;

try {
    $result = await($future, timeout: 1000);
} catch (TimeoutException $e) {
    echo "Operation timed out";
}
```

### Exception in Composite Future

```php
try {
    [$a, $b, $c] = await(Future::all($task1, $task2, $task3));
} catch (Exception $e) {
    // If ANY task fails, the entire Future::all fails
    echo "One of the tasks failed";
}
```

---

## Limitations (Current)

### I/O Still Blocks

Currently, database I/O operations still block:

```php
// These queries run "in parallel" from PHP's perspective,
// but PDO still blocks on each connection
[$user, $posts] = await(Future::all(...));
```

However:
- ✅ The database can process queries simultaneously
- ✅ PHP code structure is clean (no callbacks)
- ✅ Future roadmap includes true non-blocking I/O

### Single Process per Request

In PHP-FPM, each request is a separate process, so async doesn't give traditional concurrency across requests. It helps within a single request loading multiple pieces of data.

### No Async in Production without Scheduler

Attempting `await()` without a Scheduler throws an error:

```php
// ❌ WRONG
$user = await(User::query()->find(1));
// AsyncException: No active Scheduler

// ✅ CORRECT
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);
try {
    $user = await(User::query()->find(1));
} finally {
    Context::popScheduler();
}
```

---

## Roadmap

### Phase 2 (In Progress)
- [ ] Automatic Scheduler in Controller base class
- [ ] Automatic Scheduler in component rendering
- [ ] More async-aware helpers

### Phase 3 (Future)
- [ ] Timeouts with proper cancellation
- [ ] Retry logic
- [ ] Circuit breaker pattern

### Phase 4 (Long-term)
- [ ] True non-blocking I/O (socket support)
- [ ] Multiple connection pooling
- [ ] HTTP client integration
- [ ] Cache integration

---

## Best Practices

1. **Create Scheduler early in request**
   ```php
   $scheduler = new Scheduler();
   Context::pushScheduler($scheduler);
   // ... your code
   Context::popScheduler();
   ```

2. **Use Future::all() for parallel operations**
   ```php
   // Good: Load 3 things in parallel
   [$a, $b, $c] = await(Future::all($task1, $task2, $task3));

   // Not as efficient: Sequential
   $a = await($task1);
   $b = await($task2);
   $c = await($task3);
   ```

3. **Combine related operations**
   ```php
   // Good: Query builder calls are lazy
   $users = User::query();
   $active = await($users->where('active', true)->getAsync());

   // Also good: Inline
   $active = await(User::query()->where('active', true)->getAsync());
   ```

4. **Handle exceptions properly**
   ```php
   try {
       $result = await($future);
   } catch (Exception $e) {
       // Log and handle
   }
   ```

---

## Testing

Test async code with `syncRun()`:

```php
use function SfphpProject\src\Async\syncRun;

// Test without manual scheduler setup
$result = syncRun(async(fn () => 42));
assert($result === 42);

// Test with database
$user = syncRun(User::query()->findAsync(1));
assert($user->id === 1);
```

---

## See Also

- [ASYNC_ARCHITECTURE.md](./ASYNC_ARCHITECTURE.md) - Deep dive into design
- [test-async.php](../test-async.php) - Test suite
- [example-async-controller.php](../example-async-controller.php) - Controller examples
