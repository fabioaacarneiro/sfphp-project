<?php

/**
 * The async exceptions used to be declared here, three classes in one file.
 *
 * PSR-4 maps one class to one file, so none of them could be autoloaded: any
 * code that reached for TimeoutException got "Class not found" instead — which
 * is what a timeout actually produced, rather than the exception it promised.
 * They now live in files of their own, and this one is kept so that an
 * application that required it directly still loads.
 */

require_once __DIR__ . '/AsyncException.php';
require_once __DIR__ . '/TimeoutException.php';
require_once __DIR__ . '/CancelledException.php';
