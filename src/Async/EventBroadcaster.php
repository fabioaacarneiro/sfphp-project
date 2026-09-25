<?php

namespace SfphpProject\src\Async;

/**
 * Event Broadcaster for pub/sub async event handling
 *
 * Manages event subscriptions and broadcasts with async callbacks.
 *
 * A listener's pattern may contain `*`, which stands for one or more of any
 * character, dots included: `user.*` receives `user.created` and
 * `user.profile.updated`, `*.created` receives `order.created`, and
 * `order.*.shipped` receives `order.42.shipped`. Listeners run one after
 * another inside a single Task, highest priority first; the broadcast itself
 * is what runs alongside other work, not each listener.
 */
class EventBroadcaster
{
    private array $listeners = []; // event => [listener id => listener]
    private array $eventHistory = [];
    private bool $recordHistory = false;
    private int $historyLimit = 100;

    /*
     * A scoped broadcaster shares its parent's listeners and history and puts
     * this prefix in front of every event name it is given, so a module can
     * subscribe to "created" and broadcast "created" while the application
     * sees "billing.created".
     */
    private string $prefix = '';

    /**
     * Subscribe to an event
     *
     * @param string $event Event name (can use wildcards: user.*)
     * @param callable $callback Async callback
     * @param int $priority Higher = executed first
     */
    public function subscribe(string $event, callable $callback, int $priority = 0): string
    {
        $event = $this->qualify($event);

        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $id = uniqid('listener_', true);
        $this->listeners[$event][$id] = [
            'callback' => $callback,
            'priority' => $priority,
            'created_at' => time(),
        ];

        /*
         * uasort, not usort. usort renumbers the array, which threw away the
         * listener ids this method returns — so unsubscribe() could never find
         * the listener it was handed back.
         */
        uasort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $id;
    }

    /**
     * Unsubscribe from event
     */
    public function unsubscribe(string $event, string $listenerId): bool
    {
        $event = $this->qualify($event);

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
        unset($this->listeners[$this->qualify($event)]);
    }

    /**
     * Broadcast an event
     */
    public function broadcast(string $event, mixed $payload = null): Future
    {
        $event = $this->qualify($event);

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
        /*
         * Awaited, not read. broadcast() only queues the Task, so reading its
         * value straight away found it still pending and threw every time.
         */
        $result = await($this->broadcast($event, $payload));

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
                // The + keeps the listener ids; they are unique across patterns.
                $matching += $listeners;
            }
        }

        // Priority holds across patterns, not only within each one.
        uasort($matching, fn($a, $b) => $b['priority'] <=> $a['priority']);

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
        if (str_contains($pattern, '*')) {
            /*
             * The pattern is quoted first so that its dots are literal, and
             * preg_quote() turns each "*" into "\*" — so it is that escaped
             * form that has to be replaced. Replacing the bare "*" left its
             * backslash behind, so "user.*" became "user\.\.*" — "user"
             * followed by nothing but dots — and no real event matched it.
             */
            $regex = str_replace('\\*', '.+', preg_quote($pattern, '/'));

            return preg_match('/^' . $regex . '$/u', $event) === 1;
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
    public function getListenerCount(?string $event = null): int
    {
        if ($event === null) {
            return array_reduce($this->listeners, fn($sum, $list) => $sum + count($list), 0);
        }

        $matching = $this->getMatchingListeners($this->qualify($event));
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
        if ($this->prefix === '') {
            $this->listeners = [];

            return $this;
        }

        // A scope clears its own listeners, not the whole application's.
        foreach (array_keys($this->listeners) as $event) {
            if (str_starts_with($event, $this->prefix)) {
                unset($this->listeners[$event]);
            }
        }

        return $this;
    }

    /**
     * Create scoped broadcaster
     *
     * The scoped broadcaster shares this one's listeners and history, and
     * prefixes every event name with "$prefix.". It used to return a fresh,
     * unconnected broadcaster, so nothing subscribed through a scope ever
     * heard anything broadcast outside it.
     */
    public function scope(string $prefix): EventBroadcaster
    {
        $scoped = new self();
        $scoped->listeners = &$this->listeners;
        $scoped->eventHistory = &$this->eventHistory;
        $scoped->recordHistory = &$this->recordHistory;
        $scoped->historyLimit = &$this->historyLimit;
        $scoped->prefix = $this->qualify($prefix) . '.';

        return $scoped;
    }

    /**
     * The full name of an event, with this broadcaster's scope in front.
     */
    private function qualify(string $event): string
    {
        return $this->prefix . $event;
    }
}
