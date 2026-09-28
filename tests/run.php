<?php

/*
 * The framework's own test suite.
 *
 *     php tests/run.php                  everything, which is what CI runs
 *     php tests/run.php --filter=sfjs    only the tests whose name or file matches
 *
 * The tests live in tests/suite/, one file per part of the framework, and run
 * in the order of their names. They share one scope: bootstrap.php and
 * support.php define $tests and the fixtures every file can use.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/support.php';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--filter=')) {
        $tests->filter(substr($argument, strlen('--filter=')));
    }
}

foreach (glob(__DIR__ . '/suite/*.php') ?: [] as $file) {
    $tests->file(basename($file, '.php'));

    require $file;
}

$tests->finish();
