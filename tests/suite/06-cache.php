<?php

/*
 * Cache drivers and the atomic counter.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Config;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\FileDriver;
use SfphpProject\src\Cache\RedisDriver as CacheRedisDriver;
use SfphpProject\src\RedisConnection;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Database\Factory;
use SfphpProject\src\Database\Seeder;
use SfphpProject\src\Queue\DatabaseDriver;
use SfphpProject\src\Queue\QueueManager;
use SfphpProject\src\Queue\RedisDriver;

$tests->run('cache and queue classes are autoloadable', function () use ($tests): void {
    // These lived under a "SfPhp\" namespace the PSR-4 map never covered, so
    // every one of them was a fatal error at runtime.
    foreach ([
        CacheManager::class,
        FileDriver::class,
        MemoryDriver::class,
        QueueManager::class,
        DatabaseDriver::class,
        Seeder::class,
        Factory::class,
    ] as $class) {
        $tests->assertTrue(class_exists($class));
    }

    $tests->assertTrue(function_exists('cache'));
    $tests->assertTrue(function_exists('dispatch'));

    // Every queue driver has to be able to list what failed; the manager used
    // to return a hardcoded empty array.
    foreach ([DatabaseDriver::class, RedisDriver::class] as $driver) {
        $tests->assertTrue((new ReflectionClass($driver))->hasMethod('failedJobs'));
    }
});

$tests->run('memory cache driver honours the cache contract', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());

    $cache->put('key', ['a' => 1]);
    $tests->assertSame(['a' => 1], $cache->get('key'));
    $tests->assertTrue($cache->has('key'));
    $tests->assertSame('computed', $cache->remember('lazy', 60, fn () => 'computed'));
    $tests->assertSame('computed', $cache->get('lazy'));

    $cache->forget('key');
    $tests->assertSame(false, $cache->has('key'));

    $cache->flush();
    $tests->assertSame(false, $cache->has('lazy'));
});

$tests->run('every cache driver keeps an entry stored without a lifetime, and agrees on what a lifetime means', function () use ($tests): void {
    /*
     * The file driver — the default — read an entry stored without a TTL as
     * missing, because it checked isset($data['expires']) and that is false
     * for null. put() without a lifetime, remember() with null and a counter
     * without a window were all written and never found.
     */
    $directory = sys_get_temp_dir() . '/sfphp-cache-contract-' . bin2hex(random_bytes(6));

    foreach ([new MemoryDriver(), new FileDriver($directory)] as $driver) {
        $cache = new CacheManager($driver);
        $name = get_class($driver);

        $cache->put('forever', 'v');
        $tests->assertSame('v', $cache->get('forever'), $name);
        $tests->assertTrue($cache->has('forever'), $name);

        $cache->put('zero', 'z', 0);
        $tests->assertSame('z', $cache->get('zero'), $name);

        $tests->assertSame(1, $cache->increment('ctr'), $name);
        $tests->assertSame(2, $cache->increment('ctr'), $name);
        $tests->assertSame(2, $cache->get('ctr'), $name);

        $calls = 0;
        $cache->remember('r', null, function () use (&$calls): string { $calls++; return 'x'; });
        $cache->remember('r', null, function () use (&$calls): string { $calls++; return 'x'; });
        $tests->assertSame(1, $calls, $name);

        // A stored null is an entry: has() says so in every driver.
        $cache->put('nothing', null);
        $tests->assertTrue($cache->has('nothing'), $name);
        $tests->assertSame('default', $cache->get('absent', 'default'), $name);

        // Values are what JSON holds, the same in every driver.
        $cache->put('obj', (object) ['a' => 1]);
        $tests->assertSame(['a' => 1], $cache->get('obj'), $name);
        $cache->put('float', 1.0);
        $tests->assertSame(1.0, $cache->get('float'), $name);

        $tests->assertThrows(fn () => $cache->put('neg', 'x', -1), InvalidArgumentException::class);

        $cache->flush();
    }

    exec('rm -rf ' . escapeshellarg($directory));
});

$tests->run('cache:clear removes only what has expired; cache:flush removes everything', function () use ($tests): void {
    /*
     * cache:clear used to flush. The cache holds the revoked-token list, the
     * rate-limit counters and cache-held sessions, so "clear expired entries"
     * brought revoked tokens back to life and logged everyone out.
     */
    $directory = sys_get_temp_dir() . '/sfphp-cache-prune-' . bin2hex(random_bytes(6));

    foreach ([new MemoryDriver(), new FileDriver($directory)] as $driver) {
        $driver->put('live', 'yes', 3600);
        $driver->put('kept', 'yes');
        $driver->put('gone', 'no', 1);

        // Age the entry without sleeping: rewrite its expiry into the past.
        if ($driver instanceof FileDriver) {
            $file = $directory . '/' . md5('gone') . '.cache';
            file_put_contents($file, json_encode(['value' => 'no', 'expires' => time() - 10]));
        } else {
            (fn () => $this->store['gone']['expires'] = time() - 10)->call($driver);
        }

        $tests->assertSame(1, $driver->prune());
        $tests->assertSame('yes', $driver->get('live'));
        $tests->assertSame('yes', $driver->get('kept'));
        $driver->flush();
        $tests->assertSame(null, $driver->get('live'));
    }

    exec('rm -rf ' . escapeshellarg($directory));
});

$tests->run('the file cache lives in a private directory inside the project', function () use ($tests): void {
    /*
     * It used to be sys_get_temp_dir()/sfphp-cache, created 0755 with files
     * 0644: shared by every application and readable by every user on the
     * machine, sessions included.
     */
    $directory = sys_get_temp_dir() . '/sfphp-private-' . bin2hex(random_bytes(6));
    $driver = new FileDriver($directory);
    $driver->put('k', 'v');

    $tests->assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
    $tests->assertSame('0600', substr(sprintf('%o', fileperms($directory . '/' . md5('k') . '.cache')), -4));

    // A directory others can write to is tightened before it is used.
    chmod($directory, 0777);
    new FileDriver($directory);

    /*
     * Before PHP 8.3 chmod() does not clear the stat cache, so without this the
     * test read back the mode it had set itself — 0777 on 8.1 and 8.2 — while
     * the directory on disk was already 0700.
     */
    clearstatcache();
    $tests->assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));

    // A relative CACHE_PATH resolves against the project, not the working directory.
    $tests->assertSame(Bootstrap::basePath('storage/x'), \SfphpProject\src\PrivateDirectory::resolve('storage/x'));

    exec('rm -rf ' . escapeshellarg($directory));
});

$tests->run('the cache counts atomically and does not move the window', function () use ($tests): void {
    foreach ([new MemoryDriver(), new FileDriver(sys_get_temp_dir() . '/sfphp-increment-' . getmypid())] as $driver) {
        $cache = new CacheManager($driver);
        $cache->flush();

        $tests->assertSame(1, $cache->increment('hits', 1, 60));
        $tests->assertSame(2, $cache->increment('hits', 1, 60));
        $tests->assertSame(7, $cache->increment('hits', 5, 60));

        // The counter is readable as an ordinary value.
        $tests->assertSame(7, (int) $cache->get('hits'));

        /*
         * The lifetime belongs to the counter that was created, not to every
         * hit afterwards. A client that keeps knocking must not be able to
         * push its own window forward and stay inside the limit forever.
         */
        $ttl = $cache->ttl('hits');
        $tests->assertTrue($ttl !== null && $ttl <= 60);
        $cache->increment('hits', 1, 3600);
        $tests->assertTrue($cache->ttl('hits') <= 60);

        // A counter that was never created reports no deadline.
        $tests->assertSame(null, $cache->ttl('never-set'));

        /*
         * The corollary of "the lifetime applies only on creation": a counter
         * first created without one never gets one. Documented, because it is
         * the kind of thing that is only surprising after it has happened.
         */
        $cache->increment('immortal');
        $cache->increment('immortal', 1, 60);
        $tests->assertSame(null, $cache->ttl('immortal'));

        $tests->assertSame(-2, $cache->decrement('down', 2, 60));

        $cache->flush();
    }
});

$tests->run('concurrent processes do not lose counts', function () use ($tests): void {
    /*
     * The bug this guards against cannot be reproduced in one process: get()
     * followed by put() only loses a hit when something else writes in
     * between. So this really does fork PHP processes that all increment the
     * same key at once, and asserts the total is exact.
     *
     * Before Cache::increment() existed, the rate limiter counted with a read
     * and a write, and this test would land well short of the total — which is
     * how an attacker opening parallel connections got more attempts than the
     * limit allowed.
     */
    $directory = sys_get_temp_dir() . '/sfphp-concurrency-' . bin2hex(random_bytes(6));
    $autoload = dirname(__DIR__) . '/../vendor/autoload.php';
    $script = $directory . '-worker.php';

    file_put_contents($script, <<<'WORKER'
        <?php
        require $argv[1];
        $driver = new SfphpProject\src\Cache\FileDriver($argv[2]);
        for ($i = 0; $i < (int) $argv[4]; $i++) {
            $driver->increment($argv[3], 1, 60);
        }
        WORKER);

    $workers = 12;
    $perWorker = 25;
    $processes = [];

    for ($i = 0; $i < $workers; $i++) {
        $command = escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($autoload)
            . ' ' . escapeshellarg($directory)
            . ' ' . escapeshellarg('shared')
            . ' ' . escapeshellarg((string) $perWorker);

        /*
         * Output goes to /dev/null rather than to pipes nobody reads. With
         * pipes, closing the read end while a worker was still writing killed
         * that worker mid-loop, and the count came up short for a reason that
         * had nothing to do with locking — which is exactly the failure this
         * test is supposed to be able to attribute.
         */
        $processes[] = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes[$i]
        );
    }

    foreach ($processes as $process) {
        proc_close($process);
    }

    $driver = new FileDriver($directory);
    $tests->assertSame($workers * $perWorker, (int) $driver->get('shared'));

    $driver->flush();
    @rmdir($directory);
    @unlink($script);
});

$tests->run('the cache and the queue read the driver they are told to use', function () use ($tests): void {
    /*
     * Both used to hardcode their driver, and neither read any setting. The
     * consequence was not a missing feature: it was that the token denylist,
     * the rate limit counters and cache-backed sessions were all kept per
     * machine, silently, with no way to change it short of writing code.
     */
    Config::set('CACHE_DRIVER', 'array');
    $tests->assertSame(MemoryDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::set('CACHE_DRIVER', 'file');
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    // An unknown name falls back to the file driver rather than failing: a
    // typo in .env should not take an application down.
    Config::set('CACHE_DRIVER', 'nosuchdriver');
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::set('QUEUE_DRIVER', 'database');
    $tests->assertSame(DatabaseDriver::class, get_class(QueueManager::fromConfig()->getDriver()));

    Config::forget('CACHE_DRIVER');
    Config::forget('QUEUE_DRIVER');

    // The defaults are what an application gets when it says nothing.
    $tests->assertSame(FileDriver::class, get_class(CacheManager::fromConfig()->getDriver()));
    $tests->assertSame(DatabaseDriver::class, get_class(QueueManager::fromConfig()->getDriver()));
});

$tests->run('redis is selected through the shared connection, or refused out loud', function () use ($tests): void {
    if (!extension_loaded('redis')) {
        /*
         * Selecting redis without the extension must fail rather than quietly
         * handing back a file driver. An operator who believes revocation is
         * shared between instances when it is not has a security hole that
         * shows up months later and never as an error.
         */
        Config::set('CACHE_DRIVER', 'redis');
        $tests->assertThrows(fn () => CacheManager::fromConfig(), RuntimeException::class);
        Config::forget('CACHE_DRIVER');

        return;
    }

    // With the extension present, a connection the application supplies is
    // used as it is — no second socket to the same server.
    $fake = new \Redis();
    RedisConnection::use($fake);
    $tests->assertSame(true, RedisConnection::isConnected());
    $tests->assertSame($fake, RedisConnection::get());

    Config::set('CACHE_DRIVER', 'redis');
    $tests->assertSame(CacheRedisDriver::class, get_class(CacheManager::fromConfig()->getDriver()));

    Config::forget('CACHE_DRIVER');
    RedisConnection::use(null);
});

$tests->run('the cache adapters work with the framework cache', function () use ($tests): void {
    $cache = new CacheManager(new MemoryDriver());

    SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::set('greeting', 'olá', 60, $cache));
    $tests->assertSame('olá', SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::get('greeting', $cache)));
    $tests->assertSame(true, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::has('greeting', $cache)));
    $tests->assertSame(3, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::increment('hits', 3, $cache)));
    // A bare driver has no decrement(), so the adapter increments by the negative.
    $tests->assertSame(-1, SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::decrement('left', 1, new MemoryDriver())));

    SfphpProject\src\Async\await(SfphpProject\src\Async\Adapters\CacheFuture::delete('greeting', $cache));
    $tests->assertSame(false, $cache->has('greeting'));

    // Awaiting an adapter that has already run returns its value again.
    $read = SfphpProject\src\Async\Adapters\CacheFuture::get('hits', $cache);
    $read->getValue();
    $tests->assertSame(3, SfphpProject\src\Async\await($read));

    $cache->put('user:1', 'a');
    $cache->put('user:1:posts', 'b');
    $cache->put('user:1:followers', 'c');

    $invalidator = (new SfphpProject\src\Async\CacheInvalidator($cache))
        ->registerDependency('user:1', ['user:1:posts'])
        ->registerDependency('user:1:posts', ['user:1']);

    // The cycle is deliberate: it used to recurse until the stack ran out.
    $invalidator->invalidate('user:1');
    $tests->assertSame([false, false, true], [$cache->has('user:1'), $cache->has('user:1:posts'), $cache->has('user:1:followers')]);
});
