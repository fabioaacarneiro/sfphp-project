<?php

/**
 * Invariants that hold from the moment the package is loaded.
 *
 * Loaded by Composer, before any application code runs, and deliberately tiny:
 * everything here is a correctness rule rather than a setting, which is why it
 * does not wait for Bootstrap::load() to be called.
 */

/*
 * The runtime is UTC.
 *
 * A naive timestamp in a database column is only an instant if something says
 * which zone wrote it. If that answer is "whatever the server was set to", then
 * moving the server silently changes what every existing row means, and no
 * later fix can recover the intent because it was never written down.
 *
 * This is not APP_TIMEZONE. That one decides how times are *shown*, and it is
 * read when something is rendered, not here.
 */
date_default_timezone_set('UTC');
