<?php

namespace SfphpProject\src\Async;

/**
 * Manages the async context for the current request/scope
 *
 * Uses static stacks to track active Schedulers and Fibers
 * per request/scope (not truly thread-safe, but suitable for PHP)
 */
class Context
{
    private static ?\SplStack $schedulerStack = null;
    private static ?\SplStack $fiberStack = null;

    /**
     * Initialize stacks if not already initialized
     */
    private static function initialize(): void
    {
        if (self::$schedulerStack === null) {
            self::$schedulerStack = new \SplStack();
            self::$fiberStack = new \SplStack();
        }
    }

    /**
     * Push a new Scheduler onto the stack
     *
     * Typically called at the start of a request/controller
     */
    public static function pushScheduler(Scheduler $scheduler): void
    {
        self::initialize();
        self::$schedulerStack->push($scheduler);
    }

    /**
     * Pop the current Scheduler from the stack
     *
     * Typically called at the end of a request/controller
     */
    public static function popScheduler(): Scheduler
    {
        self::initialize();
        if (self::$schedulerStack->isEmpty()) {
            throw new AsyncException("No Scheduler to pop");
        }
        return self::$schedulerStack->pop();
    }

    /**
     * The scheduler everything runs on, creating one if nothing did.
     *
     * A request goes through the EnableAsync middleware and gets its own, but
     * a console command, a queue worker and a test do not — and `await()` has
     * to work in all of them. Falling back to a lazily created root scheduler
     * is what makes the public API usable outside a web request, which it was
     * not: `await()` outside the middleware simply returned null.
     *
     * @return Scheduler The scheduler
     */
    public static function scheduler(): Scheduler
    {
        self::initialize();

        if (self::$schedulerStack->isEmpty()) {
            self::$schedulerStack->push(new Scheduler());
        }

        return self::$schedulerStack->top();
    }

    /**
     * Get the current active Scheduler
     *
     * @throws AsyncException If no Scheduler is active
     */
    public static function getScheduler(): Scheduler
    {
        self::initialize();
        if (self::$schedulerStack->isEmpty()) {
            throw new AsyncException("No active Scheduler");
        }
        return self::$schedulerStack->top();
    }

    /**
     * Check if a Scheduler is currently active
     */
    public static function hasScheduler(): bool
    {
        self::initialize();
        return !self::$schedulerStack->isEmpty();
    }

    /**
     * Push a Fiber onto the stack
     */
    public static function pushFiber(\Fiber $fiber): void
    {
        self::initialize();
        self::$fiberStack->push($fiber);
    }

    /**
     * Pop the current Fiber from the stack
     */
    public static function popFiber(): ?\Fiber
    {
        self::initialize();
        if (self::$fiberStack->isEmpty()) {
            return null;
        }
        return self::$fiberStack->pop();
    }

    /**
     * Get the current active Fiber
     */
    public static function getCurrentFiber(): ?\Fiber
    {
        self::initialize();
        if (self::$fiberStack->isEmpty()) {
            return null;
        }
        return self::$fiberStack->top();
    }

    /**
     * Clear all stacks (useful for cleanup in tests)
     */
    public static function clear(): void
    {
        self::$schedulerStack = null;
        self::$fiberStack = null;
    }
}
