<?php

/*
 * Time and time zones.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Dotenv;
use SfphpProject\src\Time;

$tests->run('the runtime is UTC and now() is the instant', function () use ($tests): void {
    $tests->assertSame('UTC', date_default_timezone_get());
    $tests->assertSame('UTC', Time::now()->getTimezone()->getName());
    $tests->assertSame('UTC', now()->getTimezone()->getName());

    // now() is the helper the documentation has been showing all along.
    $tests->assertTrue(function_exists('now'));
    $tests->assertTrue(abs(now()->getTimestamp() - time()) <= 1);
});

$tests->run('a zone is for showing a time, not for storing one', function () use ($tests): void {
    $instant = Time::parse('2026-09-21T23:00:00+00:00');

    $tokyo = Time::in($instant, 'Asia/Tokyo');
    $tests->assertSame('2026-09-22 08:00:00', $tokyo->format('Y-m-d H:i:s'));

    // The same moment, seen from elsewhere: the instant did not move.
    $tests->assertSame($instant->getTimestamp(), $tokyo->getTimestamp());

    // And storing it stores the same instant, in UTC.
    $tests->assertSame('2026-09-21 23:00:00', Time::toDatabase($tokyo));

    $tests->assertSame('2026-09-21 23:00:00', Time::display($instant));
});

$tests->run('the clock can be held still so a time test is not a race', function () use ($tests): void {
    $frozen = Time::freeze('2026-01-01T12:00:00+00:00');

    $tests->assertTrue(Time::frozen());
    $tests->assertSame('2026-01-01T12:00:00+00:00', now()->format(DATE_ATOM));
    $tests->assertSame($frozen->getTimestamp(), Time::now()->getTimestamp());

    Time::unfreeze();
    $tests->assertTrue(!Time::frozen());
    $tests->assertTrue(abs(now()->getTimestamp() - time()) <= 1);
});

$tests->run('a date is read as written or not at all, and .env reads quoted values and export lines', function () use ($tests): void {
    $tests->assertSame(null, Time::parse('2026-02-30'));
    $tests->assertSame(null, Time::parse('next monday'));
    $tests->assertSame(null, Time::parse('2026-09-21 25:00:00'));
    $tests->assertSame('2026-09-21T23:00:00+00:00', Time::parse('2026-09-21 23:00:00+00')->format(DATE_ATOM));
    $tests->assertSame('2026-09-21T00:00:00+00:00', Time::parse('2026-09-21')->format(DATE_ATOM));

    $file = sys_get_temp_dir() . '/sfphp-env-' . bin2hex(random_bytes(4));
    $key = 'SFPHP_TEST_' . strtoupper(bin2hex(random_bytes(3)));
    file_put_contents($file, "{$key}_A=\"My App\" # the name\nexport {$key}_B=x\n");
    Dotenv::loadEnv($file);

    $tests->assertSame('My App', getenv($key . '_A'));
    $tests->assertSame('x', getenv($key . '_B'));
    unlink($file);
});
