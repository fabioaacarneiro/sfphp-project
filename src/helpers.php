<?php

use SfPhp\Cache\CacheManager;

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
