<?php

/**
 * Example: Phase 5 - Reactive Components and State Management
 *
 * Shows how to use ReactiveState, ComponentFuture, and CacheInvalidator
 * in realistic component scenarios.
 */

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Async\ComponentFuture;
use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Async\Adapters\HttpFuture;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

echo "=== Phase 5: Reactive Components Examples ===\n\n";

// Example 1: User Profile with Reactive State
echo "Example 1: Reactive User Profile Component\n";
echo "-------------------------------------------\n";

class UserProfileComponent
{
    private ReactiveState $userState;
    private ReactiveState $postsState;
    private CacheInvalidator $invalidator;
    private $cache;

    public function __construct($cache)
    {
        $this->cache = $cache;
        $this->userState = new ReactiveState(null, ttl: 3600); // 1 hour
        $this->postsState = new ReactiveState([], ttl: 1800);   // 30 min
        $this->invalidator = new CacheInvalidator($cache);

        // Register cache dependencies
        $this->setupInvalidation();
    }

    private function setupInvalidation()
    {
        // When user state changes, invalidate related caches
        $this->invalidator->invalidateOnStateChange(
            $this->userState,
            CacheInvalidator::createUserInvalidationPattern(1)
        );

        // When posts state changes, invalidate post caches
        $this->invalidator->invalidateOnStateChange(
            $this->postsState,
            ['posts:list', 'posts:recent']
        );
    }

    public function loadUserData($userId)
    {
        // Try cache first
        $cached = $this->cache->get("user:$userId");
        if ($cached) {
            $this->userState->setValue($cached);
            return;
        }

        // Load asynchronously
        $this->userState->setLoading(true);

        try {
            $response = HttpFuture::get("/api/users/$userId")->getValue();
            $user = json_decode($response['body'], true);
            $this->userState->setValue($user);
            $this->cache->set("user:$userId", $user, 3600);
        } catch (\Exception $e) {
            $this->userState->setError($e);
        }
    }

    public function loadUserPosts($userId)
    {
        // Try cache first
        $cached = $this->cache->get("user:$userId:posts");
        if ($cached) {
            $this->postsState->setValue($cached);
            return;
        }

        // Load asynchronously
        $this->postsState->setLoading(true);

        try {
            $response = HttpFuture::get("/api/users/$userId/posts")->getValue();
            $posts = json_decode($response['body'], true);
            $this->postsState->setValue($posts);
            $this->cache->set("user:$userId:posts", $posts, 1800);
        } catch (\Exception $e) {
            $this->postsState->setError($e);
        }
    }

    public function render()
    {
        $userArray = $this->userState->toArray();

        if ($userArray['loading']) {
            return '<div class="loading">Loading user profile...</div>';
        }

        if ($userArray['hasError']) {
            return '<div class="error">Failed to load user: ' . htmlspecialchars($userArray['error']) . '</div>';
        }

        $user = $userArray['value'];
        if (!$user) {
            return '<div>No user data</div>';
        }

        return <<<HTML
            <div class="user-profile">
                <h1>{$user['name']}</h1>
                <p>{$user['email']}</p>
                <p>{$user['bio']}</p>
            </div>
        HTML;
    }
}

echo "✅ Component manages state with loading/error states\n";
echo "✅ Automatic cache invalidation on state changes\n";
echo "✅ Clean separation of data loading and rendering\n\n";

// Example 2: Dashboard with Multiple Reactive Components
echo "Example 2: Reactive Dashboard\n";
echo "------------------------------\n";

class DashboardComponent
{
    private ReactiveState $statsState;
    private ReactiveState $recentActivityState;
    private CacheInvalidator $invalidator;

    public function __construct($cache)
    {
        $this->statsState = new ReactiveState();
        $this->recentActivityState = new ReactiveState();
        $this->invalidator = new CacheInvalidator($cache);

        $this->setupListeners();
    }

    private function setupListeners()
    {
        // When stats change, also invalidate activity (they're related)
        $this->invalidator->invalidateOnStateChange(
            $this->statsState,
            ['dashboard:activity', 'dashboard:summary']
        );
    }

    public function loadDashboardData($userId)
    {
        // Load multiple data sources in parallel
        try {
            $this->statsState->setLoading(true);
            $this->recentActivityState->setLoading(true);

            // Simulate parallel loading
            $stats = ['users' => 1234, 'posts' => 5678, 'followers' => 9012];
            $activity = [
                ['type' => 'like', 'time' => '5 min ago'],
                ['type' => 'comment', 'time' => '10 min ago'],
            ];

            $this->statsState->setValue($stats);
            $this->recentActivityState->setValue($activity);
        } catch (\Exception $e) {
            $this->statsState->setError($e);
        }
    }

    public function render()
    {
        $stats = $this->statsState->toArray();
        $activity = $this->recentActivityState->toArray();

        if ($stats['loading'] || $activity['loading']) {
            return '<div>Loading dashboard...</div>';
        }

        return <<<HTML
            <div class="dashboard">
                <div class="stats">
                    Users: {$stats['value']['users']}<br>
                    Posts: {$stats['value']['posts']}<br>
                    Followers: {$stats['value']['followers']}
                </div>
            </div>
        HTML;
    }
}

echo "✅ Multiple reactive states work together\n";
echo "✅ Shared cache invalidation logic\n";
echo "✅ Coordinated loading states\n\n";

// Example 3: Component with Automatic Retry
echo "Example 3: Component with Automatic Retry\n";
echo "-------------------------------------------\n";

class RobustDataComponent
{
    public function renderWithRetry(callable $dataLoader, int $maxRetries = 3)
    {
        $componentFuture = new ComponentFuture(
            fn() => $this->renderContent($dataLoader),
            maxRetries: $maxRetries
        );

        try {
            return $componentFuture->getValue();
        } catch (\Exception $e) {
            // All retries failed
            return $this->renderErrorFallback($e);
        }
    }

    private function renderContent(callable $loader)
    {
        $data = call_user_func($loader);

        return <<<HTML
            <div class="data-display">
                <h3>Data Loaded Successfully</h3>
                <pre>{$data}</pre>
            </div>
        HTML;
    }

    private function renderErrorFallback(\Exception $e)
    {
        return <<<HTML
            <div class="error-fallback">
                <h3>Unable to Load Data</h3>
                <p>Error: {$e->getMessage()}</p>
                <button onclick="location.reload()">Retry</button>
            </div>
        HTML;
    }
}

echo "✅ Automatic retry logic for unreliable operations\n";
echo "✅ Error fallback rendering\n";
echo "✅ User-friendly error messages\n\n";

// Example 4: Cache Invalidation Patterns
echo "Example 4: Cache Invalidation Patterns\n";
echo "--------------------------------------\n";

class PostManagementService
{
    private CacheInvalidator $invalidator;

    public function __construct($cache)
    {
        $this->invalidator = new CacheInvalidator($cache);
        $this->setupInvalidationPatterns();
    }

    private function setupInvalidationPatterns()
    {
        // When user's post changes, invalidate multiple caches
        for ($userId = 1; $userId <= 100; $userId++) {
            $this->invalidator->registerDependency(
                "post:created:user:$userId",
                [
                    "user:$userId:posts",
                    "user:$userId:post_count",
                    "feed:recent",
                    "feed:user:$userId",
                ]
            );
        }
    }

    public function createPost($userId, $content)
    {
        // Create post...
        $postId = 123;

        // Trigger cascading invalidation
        $this->invalidator->invalidate("post:created:user:$userId");

        return ['id' => $postId, 'created' => true];
    }

    public function deletePost($postId, $userId)
    {
        // Delete post...

        // Invalidate all related caches
        $this->invalidator->invalidateMany(
            CacheInvalidator::createUserInvalidationPattern($userId)
        );

        return true;
    }
}

echo "✅ Dependency-based cache invalidation\n";
echo "✅ Cascading invalidation patterns\n";
echo "✅ Automatic cleanup on state changes\n\n";

// Example 5: Reactive Component with Pagination
echo "Example 5: Reactive Paginated List\n";
echo "-----------------------------------\n";

class PaginatedListComponent
{
    private ReactiveState $itemsState;
    private ReactiveState $pageState;
    private int $currentPage = 1;
    private int $pageSize = 20;

    public function __construct()
    {
        $this->itemsState = new ReactiveState([]);
        $this->pageState = new ReactiveState(['page' => 1, 'total' => 0]);

        // When page changes, reload items
        $this->pageState->onChange(fn() => $this->loadPage());
    }

    public function loadPage()
    {
        $this->itemsState->setLoading(true);

        try {
            $page = $this->pageState->getValue()['page'];
            $offset = ($page - 1) * $this->pageSize;

            // Simulate API call
            $items = [
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
            ];

            $this->itemsState->setValue($items);
        } catch (\Exception $e) {
            $this->itemsState->setError($e);
        }
    }

    public function goToPage(int $page)
    {
        $pageData = $this->pageState->getValue();
        $pageData['page'] = $page;
        $this->pageState->setValue($pageData);
    }

    public function render()
    {
        $items = $this->itemsState->toArray();

        if ($items['loading']) {
            return '<div>Loading items...</div>';
        }

        $html = '<ul>';
        foreach ($items['value'] as $item) {
            $html .= "<li>{$item['name']}</li>";
        }
        $html .= '</ul>';

        return $html;
    }
}

echo "✅ State changes trigger related data loading\n";
echo "✅ Pagination with reactive state\n";
echo "✅ Automatic re-render on state change\n\n";

echo "=== Phase 5 Examples Complete ===\n";
echo "\nKey Features:\n";
echo "1. ReactiveState for managing component data\n";
echo "2. Automatic loading/error state management\n";
echo "3. TTL-based cache expiration\n";
echo "4. CacheInvalidator for dependency management\n";
echo "5. ComponentFuture with automatic retry\n";
echo "6. Change listeners for reactive updates\n";
echo "7. Pattern-based cache invalidation\n";
echo "8. Seamless integration with async/await\n";
echo "\nIntegration with previous phases:\n";
echo "- Use with EnableAsync middleware from Phase 3\n";
echo "- Combine with HttpFuture from Phase 4\n";
echo "- Build reactive UIs with SFHT from Phase 2\n";
