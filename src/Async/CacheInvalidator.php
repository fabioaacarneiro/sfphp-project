<?php

namespace SfphpProject\src\Async;

/**
 * Manages cache invalidation based on dependencies
 *
 * Allows automatic invalidation of related cache entries when data changes
 *
 * The cache is the framework's — `cache()`, a CacheManager or any
 * {@see \SfphpProject\src\Cache\Cache} — and keys are removed with forget().
 * An object that only has delete() (PSR-16 style) is accepted as well.
 */
class CacheInvalidator
{
    private array $dependencies = []; // key => [dependent_keys]
    private $cache;

    /**
     * Create cache invalidator
     */
    public function __construct($cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * Register a dependency relationship
     *
     * When $source changes, $dependents should be invalidated
     */
    public function registerDependency(string $source, array $dependents): self
    {
        if (!isset($this->dependencies[$source])) {
            $this->dependencies[$source] = [];
        }

        foreach ($dependents as $dependent) {
            if (!in_array($dependent, $this->dependencies[$source])) {
                $this->dependencies[$source][] = $dependent;
            }
        }

        return $this;
    }

    /**
     * Invalidate a key and all its dependents
     */
    public function invalidate(string $key): self
    {
        $this->invalidateOnce($key, []);

        return $this;
    }

    /**
     * Invalidate a key and its dependents, visiting each key only once.
     *
     * The set of keys already visited is what stops a cycle — a depends on b
     * and b on a — from recursing until the stack runs out.
     *
     * @param array<string, true> $visited Keys already invalidated in this pass
     * @return array<string, true> The keys visited so far
     */
    private function invalidateOnce(string $key, array $visited): array
    {
        if (isset($visited[$key])) {
            return $visited;
        }

        $visited[$key] = true;
        $this->forget($key);

        foreach ($this->dependencies[$key] ?? [] as $dependent) {
            $visited = $this->invalidateOnce($dependent, $visited);
        }

        return $visited;
    }

    /**
     * Remove one key from the cache.
     *
     * forget() is the framework cache's name for it. This used to call
     * delete(), which the framework cache does not have, so invalidating with
     * `cache()` failed with "Call to undefined method".
     */
    private function forget(string $key): void
    {
        if (!$this->cache) {
            return;
        }

        if (method_exists($this->cache, 'forget')) {
            $this->cache->forget($key);

            return;
        }

        $this->cache->delete($key);
    }

    /**
     * Invalidate multiple keys
     */
    public function invalidateMany(array $keys): self
    {
        foreach ($keys as $key) {
            $this->invalidate($key);
        }
        return $this;
    }

    /**
     * Invalidate by pattern (e.g., "user:123:*")
     */
    public function invalidateByPattern(string $pattern): self
    {
        // Simple pattern matching
        // For example: "user:123:*" matches "user:123:posts", "user:123:followers", etc.

        if ($this->cache && method_exists($this->cache, 'deleteByPattern')) {
            $this->cache->deleteByPattern($pattern);
            return $this;
        }

        /*
         * Fallback: iterate through dependencies. preg_quote() escapes each
         * "*" to "\*", so it is the escaped form that becomes the wildcard;
         * replacing the bare "*" left a backslash behind and matched nothing.
         */
        $regex = str_replace('\\*', '.*', preg_quote($pattern, '/'));

        foreach ($this->dependencies as $source => $dependents) {
            if (preg_match('/^' . $regex . '$/u', (string) $source)) {
                $this->invalidate($source);
            }
        }

        return $this;
    }

    /**
     * Get all dependents of a key
     */
    public function getDependents(string $key): array
    {
        return $this->dependencies[$key] ?? [];
    }

    /**
     * Clear all dependency information
     */
    public function clear(): self
    {
        $this->dependencies = [];
        return $this;
    }

    /**
     * Invalidate when a state changes
     */
    public function invalidateOnStateChange(
        ReactiveState $state,
        array $cacheKeysToInvalidate
    ): void {
        $state->onChange(function () use ($cacheKeysToInvalidate) {
            $this->invalidateMany($cacheKeysToInvalidate);
        });
    }

    /**
     * Create invalidation triggers for common patterns
     */
    public static function createUserInvalidationPattern(int $userId): array
    {
        return [
            "user:$userId",
            "user:$userId:profile",
            "user:$userId:posts",
            "user:$userId:followers",
            "user:$userId:following",
            "user:$userId:messages",
        ];
    }

    /**
     * Create invalidation triggers for common patterns (resource)
     */
    public static function createResourceInvalidationPattern(string $resourceType, int $resourceId): array
    {
        return [
            "$resourceType:$resourceId",
            "$resourceType:$resourceId:details",
            "$resourceType:$resourceId:comments",
            "$resourceType:$resourceId:stats",
            "$resourceType:list",
        ];
    }
}
