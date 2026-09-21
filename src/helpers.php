<?php

use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Queue\QueueManager;
use SfphpProject\src\Queue\Job;

if (!function_exists('cache')) {
    function cache(): CacheManager
    {
        static $cache = null;

        if ($cache === null) {
            $cache = new CacheManager();
        }

        return $cache;
    }
}

if (!function_exists('dispatch')) {
    function dispatch(Job $job, ?int $delay = null): string
    {
        static $queue = null;

        if ($queue === null) {
            $queue = new QueueManager();
        }

        return $queue->push($job, $delay);
    }
}
