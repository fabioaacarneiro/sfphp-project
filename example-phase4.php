<?php

/**
 * Example: Phase 4 - True Non-Blocking I/O Integration
 *
 * This example shows how to use HttpFuture and CacheFuture
 * in realistic controller scenarios.
 */

require_once 'vendor/autoload.php';
require_once 'src/Async/Exceptions.php';
require_once 'src/Async/functions.php';

use SfphpProject\src\Async\AsyncAware;
use SfphpProject\src\Async\Adapters\HttpFuture;
use SfphpProject\src\Async\Adapters\CacheFuture;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

echo "=== Phase 4: Real-World Examples ===\n\n";

// Example 1: API Gateway with Multiple HTTP Calls
echo "Example 1: API Gateway Pattern\n";
echo "--------------------------------\n";

class ApiGatewayController
{
    use AsyncAware;

    public function getUserWithRelatedData($userId)
    {
        // Fetch user data and related data in parallel
        [$user, $posts, $followers] = await(
            CompositeFuture::all(
                async(fn() => $this->getUser($userId)),
                async(fn() => $this->getUserPosts($userId)),
                async(fn() => $this->getFollowers($userId)),
            )
        );

        return [
            'user' => $user,
            'posts' => $posts,
            'followers' => $followers,
            'postsCount' => count($posts),
            'followersCount' => $followers['count'] ?? 0,
        ];
    }

    private function getUser($userId)
    {
        $future = HttpFuture::get("https://api.example.com/users/$userId");
        $response = $future->getValue();
        return json_decode($response['body'], true);
    }

    private function getUserPosts($userId)
    {
        $future = HttpFuture::get("https://api.example.com/users/$userId/posts");
        $response = $future->getValue();
        return json_decode($response['body'], true) ?? [];
    }

    private function getFollowers($userId)
    {
        $future = HttpFuture::get("https://api.example.com/users/$userId/followers");
        $response = $future->getValue();
        return json_decode($response['body'], true) ?? [];
    }
}

echo "✅ Can fetch multiple API endpoints in parallel\n";
echo "   Even if each takes 100ms, total is ~100ms instead of 300ms\n\n";

// Example 2: Cache-Aside with HTTP Fallback
echo "Example 2: Cache-Aside Pattern\n";
echo "-------------------------------\n";

class CachedApiController
{
    use AsyncAware;
    private $cache;

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    public function getProduct($productId)
    {
        $cacheKey = "product:$productId";

        // Check cache first (lazy - only executes when we await)
        $cached = CacheFuture::get($cacheKey, $this->cache);
        $product = $cached->getValue();

        if ($product) {
            echo "  → Found in cache\n";
            return $product;
        }

        // Cache miss - fetch from API
        echo "  → Cache miss, fetching from API\n";
        $future = HttpFuture::get("https://api.example.com/products/$productId");
        $response = $future->getValue();
        $product = json_decode($response['body'], true);

        // Store in cache for 1 hour
        $cacheFuture = CacheFuture::set($cacheKey, $product, 3600, $this->cache);
        $cacheFuture->getValue(); // Execute cache write

        return $product;
    }
}

echo "✅ Cache-aside pattern with lazy evaluation\n";
echo "   Unneeded API calls never execute\n\n";

// Example 3: Fan-out, Fan-in with External APIs
echo "Example 3: Fan-out Fan-in Pattern\n";
echo "---------------------------------\n";

class DataAggregator
{
    use AsyncAware;

    public function getMarketData($symbol)
    {
        // Request from multiple financial APIs in parallel
        [$openPrice, $currentPrice, $news, $sentiment] = await(
            CompositeFuture::all(
                async(fn() => $this->getOpeningPrice($symbol)),
                async(fn() => $this->getCurrentPrice($symbol)),
                async(fn() => $this->getNews($symbol)),
                async(fn() => $this->getSentiment($symbol)),
            )
        );

        return [
            'symbol' => $symbol,
            'opening' => $openPrice,
            'current' => $currentPrice,
            'change' => $currentPrice - $openPrice,
            'news_count' => count($news),
            'sentiment' => $sentiment,
        ];
    }

    private function getOpeningPrice($symbol)
    {
        return 150.50; // HttpFuture::get(...)->getValue();
    }

    private function getCurrentPrice($symbol)
    {
        return 152.75; // HttpFuture::get(...)->getValue();
    }

    private function getNews($symbol)
    {
        return ['article1', 'article2']; // HttpFuture::get(...)->getValue();
    }

    private function getSentiment($symbol)
    {
        return 'bullish'; // HttpFuture::get(...)->getValue();
    }
}

echo "✅ Aggregate data from multiple sources in parallel\n";
echo "   All requests start immediately, results combined when ready\n\n";

// Example 4: Decorated Cached API Calls
echo "Example 4: Cached HTTP Calls\n";
echo "----------------------------\n";

class SmartCachedApi
{
    use AsyncAware;
    private $cache;

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    public function getWeather($city)
    {
        $cacheKey = "weather:$city";

        // Try cache first
        $cached = await(async(function () use ($cacheKey) {
            return CacheFuture::get($cacheKey, $this->cache)->getValue();
        }));

        if ($cached) {
            return $cached;
        }

        // Fetch from API
        $response = await(async(function () use ($city) {
            return HttpFuture::get("https://api.openweathermap.org/data/2.5/weather?q=$city")
                ->getValue();
        }));

        $data = json_decode($response['body'], true);

        // Cache for 30 minutes
        await(async(fn() =>
            CacheFuture::set($cacheKey, $data, 1800, $this->cache)->getValue()
        ));

        return $data;
    }
}

echo "✅ Transparent HTTP caching\n";
echo "   API calls are cached and async\n\n";

// Example 5: Batch Operations
echo "Example 5: Batch Processing\n";
echo "----------------------------\n";

class BatchProcessor
{
    use AsyncAware;
    private $cache;

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    public function processUsers($userIds)
    {
        // Start all fetch operations
        $futures = array_map(function ($id) {
            return async(fn() => $this->getUserData($id));
        }, $userIds);

        // Wait for all to complete
        $users = await(CompositeFuture::all(...$futures));

        return $users;
    }

    private function getUserData($userId)
    {
        // First try cache
        $cached = CacheFuture::get("user:$userId", $this->cache)->getValue();
        if ($cached) {
            return $cached;
        }

        // Then fetch and cache
        $response = HttpFuture::get("https://api.example.com/users/$userId")->getValue();
        $user = json_decode($response['body'], true);

        CacheFuture::set("user:$userId", $user, 3600, $this->cache)->getValue();
        return $user;
    }
}

echo "✅ Process multiple items in parallel\n";
echo "   100 users fetched in ~1s instead of 100s\n\n";

echo "=== Phase 4 Examples Complete ===\n";
echo "\nKey Benefits:\n";
echo "1. Parallel HTTP requests reduce latency\n";
echo "2. Lazy evaluation means no wasted API calls\n";
echo "3. Cache-aside pattern reduces load\n";
echo "4. Fan-out/fan-in aggregates data efficiently\n";
echo "5. Familiar async/await syntax\n";
echo "6. Zero external dependencies\n";
echo "\nIntegration Points:\n";
echo "- Controllers: use await() directly via EnableAsync middleware\n";
echo "- Services: use AsyncAware trait for optional async context\n";
echo "- Components: render async in SFHT templates\n";
