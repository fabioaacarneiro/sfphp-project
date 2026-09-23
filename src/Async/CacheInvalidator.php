<?php

namespace SfphpProject\src\Async;

/**
 * Manages cache invalidation based on dependencies
 *
 * Allows automatic invalidation of related cache entries when data changes
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
        if ($this->cache) {
            $this->cache->delete($key);
        }

        // Invalidate all dependents
        if (isset($this->dependencies[$key])) {
            foreach ($this->dependencies[$key] as $dependent) {
                $this->invalidate($dependent);
            }
        }

        return $this;
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

        // Fallback: iterate through dependencies
        $regex = str_replace('*', '.*', preg_quote($pattern, '/'));

        foreach ($this->dependencies as $source => $dependents) {
            if (preg_match("/^$regex$/", $source)) {
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
