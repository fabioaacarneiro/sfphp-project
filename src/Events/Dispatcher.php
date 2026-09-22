<?php

namespace SfphpProject\src\Events;

use RuntimeException;
use SfphpProject\src\Container;
use SfphpProject\src\Log\Level;
use Throwable;

/**
 * Lets one part of an application tell the others something happened.
 *
 * `make:event` and `make:listener` generated classes for four rounds with
 * nothing to dispatch them — a generator producing code for infrastructure that
 * does not exist is worse than no generator, because it looks like a feature.
 * This is that infrastructure, and it is deliberately the smallest thing that
 * earns the name.
 *
 *     Dispatcher::listen(OrderPlaced::class, SendReceipt::class);
 *     Dispatcher::listen(OrderPlaced::class, fn (OrderPlaced $e) => ...);
 *
 *     Dispatcher::dispatch(new OrderPlaced($order));
 *
 * An event is any object. There is no base class to extend and no interface to
 * implement, because neither would carry information: what makes something an
 * event is that somebody listens for it.
 *
 * > **Under a persistent runtime the listeners are registered once, not per
 * > request.** They live in a static, like the route registry, and that is the
 * > right shape for something an application declares at boot. What must not go
 * > in a listener is per-request state captured in a closure, which would
 * > outlive the request that created it.
 */
final class Dispatcher
{
    /** @var array<string, list<callable|string>> */
    private static array $listeners = [];

    private static ?Container $container = null;

    /**
     * Resolve listener class names through this container.
     *
     * @param Container|null $container The container, or null to use a fresh one
     * @return void
     */
    public static function useContainer(?Container $container): void
    {
        self::$container = $container;
    }

    /**
     * Register a listener for an event class.
     *
     * A listener is a callable, or the name of a class with a handle() method.
     * The class name form is resolved through the container when the event
     * fires and not before, so a listener that needs a database connection does
     * not open one at boot for an event that may never happen.
     *
     * @param string $event The event class name
     * @param callable|string $listener The listener
     * @return void
     */
    public static function listen(string $event, callable|string $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Send an event to everything listening for it.
     *
     * Listeners run in the order they were registered. A listener that throws
     * does not stop the others and does not reach the caller: dispatching is
     * telling, not asking, and an event whose third listener failed has still
     * happened. The failure is logged with the event and listener named, which
     * is the only way anyone would find out otherwise.
     *
     * @param object $event The event
     * @return object The same event, so a caller can keep using it
     */
    public static function dispatch(object $event): object
    {
        foreach (self::listenersFor($event) as $listener) {
            try {
                self::call($listener, $event);
            } catch (Throwable $throwable) {
                logger()->exception($throwable, Level::Error, [
                    'event' => $event::class,
                    'listener' => is_string($listener) ? $listener : 'closure',
                ]);
            }
        }

        return $event;
    }

    /**
     * Send an event and let a listener's failure reach the caller.
     *
     * For the case where the caller genuinely depends on the listeners having
     * run — a hook that must complete before a request is answered. It is the
     * exception, which is why it is a separate method rather than a flag:
     * making dispatch() throw by default would mean one broken listener
     * breaking the action that fired the event.
     *
     * @param object $event The event
     * @return object The same event
     * @throws Throwable Whatever a listener threw
     */
    public static function dispatchOrFail(object $event): object
    {
        foreach (self::listenersFor($event) as $listener) {
            self::call($listener, $event);
        }

        return $event;
    }

    /**
     * Whether anything is listening for this event class.
     *
     * @param string $event The event class name
     * @return bool True when at least one listener is registered
     */
    public static function hasListeners(string $event): bool
    {
        return isset(self::$listeners[$event]) && self::$listeners[$event] !== [];
    }

    /**
     * Remove every listener, or every listener for one event.
     *
     * @param string|null $event The event class name, or null for all
     * @return void
     */
    public static function forget(?string $event = null): void
    {
        if ($event === null) {
            self::$listeners = [];

            return;
        }

        unset(self::$listeners[$event]);
    }

    /**
     * The listeners that should receive this event.
     *
     * Registering against a parent class or an interface works, which is what
     * makes "log every domain event" expressible without naming each one.
     *
     * @param object $event The event
     * @return list<callable|string> The listeners, in registration order
     */
    private static function listenersFor(object $event): array
    {
        $listeners = [];

        foreach (self::$listeners as $registered => $registeredListeners) {
            if ($event instanceof $registered) {
                foreach ($registeredListeners as $listener) {
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    /**
     * Run one listener.
     *
     * @param callable|string $listener The listener
     * @param object $event The event
     * @return void
     */
    private static function call(callable|string $listener, object $event): void
    {
        if (is_callable($listener)) {
            $listener($event);

            return;
        }

        $instance = (self::$container ?? new Container())->get($listener);

        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException($listener . ' has no handle() method to receive the event.');
        }

        $instance->handle($event);
    }
}
