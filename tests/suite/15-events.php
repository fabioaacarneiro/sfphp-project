<?php

/*
 * Events.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Container;
use SfphpProject\src\Events\Dispatcher;
use SfphpProject\src\Log\MemoryDriver as LogMemoryDriver;
use SfphpProject\src\Log\NullDriver as LogNullDriver;

$tests->run('events reach their listeners, and a broken one does not stop the rest', function () use ($tests): void {
    /*
     * make:event and make:listener generated classes for four rounds with
     * nothing to dispatch them. A generator producing code for infrastructure
     * that does not exist is worse than no generator: it looks like a feature.
     */
    Dispatcher::forget();

    $event = new DispatcherEvent();
    $tests->assertSame(false, Dispatcher::hasListeners(DispatcherEvent::class));

    Dispatcher::listen(DispatcherEvent::class, static function (DispatcherEvent $e): void {
        $e->seen[] = 'closure';
    });
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);

    $tests->assertSame(true, Dispatcher::hasListeners(DispatcherEvent::class));
    $tests->assertSame($event, Dispatcher::dispatch($event));
    $tests->assertSame(['closure', 'class'], $event->seen);

    /*
     * Dispatching is telling, not asking: an event whose second listener failed
     * has still happened, so the others still run and the caller is not made to
     * handle somebody else's failure. The failure is logged instead.
     */
    Dispatcher::forget();
    $log = new LogMemoryDriver();
    logger()->driver($log);

    $resilient = new DispatcherEvent();
    Dispatcher::listen(DispatcherEvent::class, DispatcherBrokenListener::class);
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);
    Dispatcher::dispatch($resilient);

    $tests->assertSame(['class'], $resilient->seen);
    $tests->assertSame('listener failed', $log->last()['message']);
    $tests->assertSame(DispatcherEvent::class, $log->last()['context']['event']);
    $tests->assertSame(DispatcherBrokenListener::class, $log->last()['context']['listener']);

    logger()->driver(new LogNullDriver());

    // dispatchOrFail is for the caller that genuinely depends on the listeners.
    $tests->assertThrows(
        fn () => Dispatcher::dispatchOrFail(new DispatcherEvent()),
        RuntimeException::class
    );

    /*
     * Listening for a parent class catches its children, which is what makes
     * "record every domain event" expressible without naming each one.
     */
    Dispatcher::forget();
    $child = new DispatcherChildEvent();
    Dispatcher::listen(DispatcherEvent::class, DispatcherListener::class);
    Dispatcher::dispatch($child);
    $tests->assertSame(['class'], $child->seen);

    // A listener class with no handle() says so rather than failing silently.
    Dispatcher::forget();
    Dispatcher::listen(DispatcherEvent::class, Container::class);
    $tests->assertThrows(
        fn () => Dispatcher::dispatchOrFail(new DispatcherEvent()),
        RuntimeException::class
    );

    Dispatcher::forget();
});

/*
 * The async helpers beyond the scheduler — events, streams, cache adapters,
 * reactive state. Each test names the defect it guards: these classes shipped
 * with none, and every one of the bugs below was reachable from its first call.
 */
$tests->run('a synchronous broadcast waits for its listeners instead of throwing', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $events->subscribe('user.created', static fn (string $event, mixed $payload): string => 'hello ' . $payload['name']);

    $result = $events->broadcastSync('user.created', ['name' => 'Ana']);

    $tests->assertSame(1, $result['listeners_count']);
    $tests->assertSame(['hello Ana'], array_values($result['results']));
    $tests->assertSame([], $result['errors']);
});

$tests->run('event wildcards match exact, leading, middle and trailing patterns', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $heard = [];

    foreach (['user.created', 'user.*', '*.created', 'order.*.shipped'] as $pattern) {
        $events->subscribe($pattern, static function (string $event) use (&$heard, $pattern): void {
            $heard[$pattern][] = $event;
        });
    }

    foreach (['user.created', 'user.profile.updated', 'order.created', 'order.42.shipped', 'users.created', 'user'] as $event) {
        $events->broadcastSync($event);
    }

    $tests->assertSame(['user.created'], $heard['user.created']);
    $tests->assertSame(['user.created', 'user.profile.updated'], $heard['user.*']);
    $tests->assertSame(['user.created', 'order.created', 'users.created'], $heard['*.created']);
    $tests->assertSame(['order.42.shipped'], $heard['order.*.shipped']);
});

$tests->run('a listener can be unsubscribed with the id subscribe returned', function () use ($tests): void {
    $events = new SfphpProject\src\Async\EventBroadcaster();
    $calls = [];

    $low = $events->subscribe('ping', static function () use (&$calls): void { $calls[] = 'low'; }, 1);
    $events->subscribe('ping', static function () use (&$calls): void { $calls[] = 'high'; }, 10);

    $events->broadcastSync('ping');
    $tests->assertSame(['high', 'low'], $calls);

    $tests->assertSame(true, $events->unsubscribe('ping', $low));
    $tests->assertSame(1, $events->getListenerCount('ping'));

    // A scope shares the listeners and prefixes the names.
    $billing = $events->scope('billing');
    $billing->subscribe('paid', static function (string $event) use (&$calls): void { $calls[] = $event; });
    $events->broadcastSync('billing.paid');
    $tests->assertSame('billing.paid', end($calls));
});
