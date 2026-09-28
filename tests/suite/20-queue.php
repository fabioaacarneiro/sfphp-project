<?php

/*
 * Queues and the worker.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Queue\QueueManager;

$tests->run('a queued job with constructor arguments comes back whole, with its dispatch options', function () use ($tests): void {
    $job = (new QueueProbeInvoiceJob(42, 'ana@example.com'))->tries(5)->timeout(120);
    $payload = json_decode(json_encode([
        'class' => get_class($job),
        'data' => $job->payload(),
        'options' => $job->options(),
    ]), true);

    // new $class() used to throw ArgumentCountError here and kill the worker.
    $restored = \SfphpProject\src\Queue\Job::fromPayload($payload, 'job_1', 2);

    $tests->assertSame('job_1', $restored->getId());
    $tests->assertSame(2, $restored->getAttempts());
    $tests->assertSame(5, $restored->getTries());
    $tests->assertSame(120, $restored->getTimeout());

    QueueProbeInvoiceJob::$sent = [];
    $restored->handle();
    $tests->assertSame(['42:ana@example.com'], QueueProbeInvoiceJob::$sent);

    // A payload stored before options were recorded keeps the class defaults.
    unset($payload['options']);
    $old = \SfphpProject\src\Queue\Job::fromPayload($payload, 'job_2', 0);
    $tests->assertSame(3, $old->getTries());
    $tests->assertSame(60, $old->getTimeout());

    // A payload that names something other than a job is never instantiated.
    $tests->assertThrows(
        fn () => \SfphpProject\src\Queue\Job::fromPayload(['class' => ArrayObject::class, 'data' => []], 'job_3', 0),
        UnexpectedValueException::class
    );
});

$tests->run('the redis queue deletes a job, and flushing it touches nothing else', function () use ($tests, $fakeRedis): void {
    $redis = $fakeRedis();
    $redis->hSet('cache:user:1', 'name', 'Ana');                      // someone else's data
    $redis->hSet('failed:login-attempts', 'ip', '10.0.0.1');          // a failed:* key that is not ours

    $queue = new \SfphpProject\src\Queue\RedisDriver($redis);

    $first = $queue->push(new QueueProbeInvoiceJob(1, 'a@example.com'));
    $queue->push(new QueueProbeInvoiceJob(2, 'b@example.com'));
    $tests->assertSame(2, $queue->size());

    // delete() removed a key that never existed, so the job stayed queued.
    $job = \SfphpProject\src\Queue\Job::fromPayload(
        json_decode((string) $redis->hGet('queue:jobs', $first), true),
        $first,
        0
    );
    $queue->delete($job);
    $tests->assertSame(1, $queue->size());
    $tests->assertSame(false, $redis->hGet('queue:jobs', $first));

    // A popped job comes back whole, and releasing it reschedules the same id.
    $popped = $queue->pop();
    $tests->assertTrue($popped instanceof QueueProbeInvoiceJob);
    $tests->assertSame(0, $queue->size());
    $queue->release($popped, 0);
    $tests->assertSame(1, $queue->size());

    // A job queued by the previous layout — payload as the member — still runs.
    $redis->zAdd('queue:default', time(), json_encode([
        'id' => 'job_legacy', 'class' => QueueProbeInvoiceJob::class,
        'data' => (new QueueProbeInvoiceJob(3, 'c@example.com'))->payload(), 'attempts' => 0,
    ]));
    $tests->assertSame(2, $queue->size());

    $queue->failed($popped, new RuntimeException('gateway down'));
    $tests->assertSame('gateway down', $queue->failedJobs()[0]['exception']);

    // flushDb() used to erase the whole database, cache and sessions included.
    $queue->flush();
    $tests->assertSame(0, $queue->size());
    $tests->assertSame([], $queue->failedJobs());
    $tests->assertSame('Ana', $redis->hGet('cache:user:1', 'name'));
    $tests->assertSame('10.0.0.1', $redis->hGet('failed:login-attempts', 'ip'));
});

$tests->run('the worker honours tries, enforces timeouts and stops on SIGTERM', function () use ($tests): void {
    if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill')) {
        return;
    }

    $driver = new QueueProbeDriver();
    $queue = new QueueManager($driver);

    // tries(4) set at dispatch: four runs, one failure — not the class's 3, and not one run short.
    QueueProbeFailingJob::$runs = 0;
    $queue->push((new QueueProbeFailingJob())->tries(4));

    // A job that overruns its timeout fails that attempt instead of holding the worker.
    $queue->push((new QueueProbeSlowJob())->tries(1)->timeout(1));

    /*
     * When the queue runs dry the driver sends SIGTERM. The handlers used to
     * be installed but never dispatched, so the worker ignored it and ran
     * until its own 30 s timeout.
     */
    ob_start();
    $startedAt = microtime(true);

    try {
        $queue->work(30);
    } finally {
        ob_end_clean();
    }

    $elapsed = microtime(true) - $startedAt;

    $failures = array_column($driver->failures, 'exception');
    sort($failures);

    $tests->assertSame(4, QueueProbeFailingJob::$runs);
    $tests->assertSame([RuntimeException::class, \SfphpProject\src\Queue\JobTimedOutException::class], $failures);
    $tests->assertTrue($elapsed < 4.0);
});

$tests->run('a job refuses a property the queue cannot bring back, and a stale payload fails instead of killing the worker', function () use ($tests): void {
    eval('namespace SfphpTest\Jobs; final class Invoice extends \SfphpProject\src\Queue\Job {
        public function __construct(public mixed $due, public array $lines = []) {}
        public function handle(): void {}
    }
    final class Typed extends \SfphpProject\src\Queue\Job {
        public int $count = 0;
        public function handle(): void {}
    }');

    $tests->assertThrows(fn () => (new \SfphpTest\Jobs\Invoice(new DateTimeImmutable()))->payload(), InvalidArgumentException::class);
    $tests->assertThrows(fn () => (new \SfphpTest\Jobs\Invoice('2026-10-01', [new stdClass()]))->payload(), InvalidArgumentException::class);
    $tests->assertSame(['due' => '2026-10-01', 'lines' => [1, 2]], (new \SfphpTest\Jobs\Invoice('2026-10-01', [1, 2]))->payload());

    // A payload whose type no longer fits says so, as an exception the driver can fail the job with.
    $tests->assertThrows(
        fn () => \SfphpProject\src\Queue\Job::fromPayload(['class' => \SfphpTest\Jobs\Typed::class, 'data' => ['count' => ['a']]], 'j1', 0),
        UnexpectedValueException::class
    );

    $tests->assertSame('gateway down', \SfphpProject\src\Queue\DatabaseDriver::messageOf(
        \SfphpProject\src\Queue\DatabaseDriver::describe(new RuntimeException('gateway down'))
    ));
});
