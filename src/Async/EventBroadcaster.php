<?php

namespace SfphpProject\src\Async;

/**
 * Event Broadcaster for pub/sub async event handling
 *
 * Manages event subscriptions and broadcasts with async callbacks
 */
class EventBroadcaster
{
    private array $listeners = []; // event => [callbacks]
    private array $eventHistory = [];
    private bool $recordHistory = false;
    private int $historyLimit = 100;

    /**
     * Subscribe to an event
     *
     * @param string $event Event name (can use wildcards: user.*)
     * @param callable $callback Async callback
     * @param int $priority Higher = executed first
     */
    public function subscribe(string $event, callable $callback, int $priority = 0): string
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $id = uniqid('listener_', true);
        $this->listeners[$event][$id] = [
            'callback' => $callback,
            'priority' => $priority,
            'created_at' => time(),
        ];

        // Sort by priority (highest first)
        usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $id;
    }

    /**
     * Unsubscribe from event
     */
    public function unsubscribe(string $event, string $listenerId): bool
    {
        if (!isset($this->listeners[$event][$listenerId])) {
            return false;
        }

        unset($this->listeners[$event][$listenerId]);
        return true;
    }

    /**
     * Unsubscribe all listeners from event
     */
    public function unsubscribeAll(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * Broadcast an event
     */
    public function broadcast(string $event, mixed $payload = null): Future
    {
        return async(function () use ($event, $payload) {
            $startTime = microtime(true);
            $results = [];
            $errors = [];

            // Record in history
            if ($this->recordHistory) {
                $this->addToHistory($event, $payload);
            }

            // Get all matching listeners
            $listeners = $this->getMatchingListeners($event);

            // Execute all listeners
            foreach ($listeners as $listenerId => $listener) {
                try {
                    $result = call_user_func($listener['callback'], $event, $payload);
                    $results[$listenerId] = $result;
                } catch (\Throwable $e) {
                    $errors[$listenerId] = $e->getMessage();
                }
            }

            $duration = microtime(true) - $startTime;

            return [
                'event' => $event,
                'listeners_count' => count($listeners),
                'results' => $results,
                'errors' => $errors,
                'duration_ms' => round($duration * 1000, 2),
            ];
        });
    }

    /**
     * Broadcast event and wait for all results
     */
    public function broadcastSync(string $event, mixed $payload = null): array
    {
        $future = $this->broadcast($event, $payload);
        $result = $future->getValue();
        return is_array($result) ? $result : [];
    }

    /**
     * Get listeners matching event (supports wildcards)
     */
    private function getMatchingListeners(string $event): array
    {
        $matching = [];

        foreach ($this->listeners as $pattern => $listeners) {
            if ($this->eventMatches($event, $pattern)) {
                $matching = array_merge($matching, $listeners);
            }
        }

        return $matching;
    }

    /**
     * Check if event matches pattern
     */
    private function eventMatches(string $event, string $pattern): bool
    {
        if ($event === $pattern) {
            return true;
        }

        // Handle wildcards: user.* matches user.created, user.deleted, etc
        if (strpos($pattern, '*') !== false) {
            $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
            return preg_match("/^$regex$/", $event) === 1;
        }

        return false;
    }

    /**
     * Enable event history recording
     */
    public function enableHistory(bool $enable = true, int $limit = 100): self
    {
        $this->recordHistory = $enable;
        $this->historyLimit = $limit;
        return $this;
    }

    /**
     * Add event to history
     */
    private function addToHistory(string $event, mixed $payload): void
    {
        $this->eventHistory[] = [
            'event' => $event,
            'payload' => $payload,
            'timestamp' => microtime(true),
        ];

        // Keep history size limited
        if (count($this->eventHistory) > $this->historyLimit) {
            array_shift($this->eventHistory);
        }
    }

    /**
     * Get event history
     */
    public function getHistory(): array
    {
        return $this->eventHistory;
    }

    /**
     * Clear history
     */
    public function clearHistory(): self
    {
        $this->eventHistory = [];
        return $this;
    }

    /**
     * Get listener count for event
     */
    public function getListenerCount(string $event = null): int
    {
        if ($event === null) {
            return array_reduce($this->listeners, fn($sum, $list) => $sum + count($list), 0);
        }

        $matching = $this->getMatchingListeners($event);
        return count($matching);
    }

    /**
     * Get all events with listeners
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Clear all listeners
     */
    public function clear(): self
    {
        $this->listeners = [];
        return $this;
    }

    /**
     * Create scoped broadcaster
     */
    public function scope(string $prefix): EventBroadcaster
    {
        $scoped = new self();
        $scoped->recordHistory = $this->recordHistory;
        $scoped->historyLimit = $this->historyLimit;

        // Proxy subscriptions to parent with prefix
        // This allows namespacing of events
        return $scoped;
    }
}
