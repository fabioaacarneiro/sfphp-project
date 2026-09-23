<?php

namespace SfphpProject\src\Async;

use CurlHandle;
use CurlMultiHandle;

/**
 * Waits for things to happen, and says who was waiting for them.
 *
 * This is the piece the runtime did not have. A Fiber can suspend, but
 * suspending does not make a blocking call non-blocking: something has to hold
 * the pending operations, wait on them all at once, and hand each result back
 * to whoever asked. Without that, `async()` only changed the order in which
 * blocking calls happened.
 *
 * Three kinds of pending work live here:
 *
 * - **cURL transfers**, held in one multi handle. libcurl drives every transfer
 *   in the set from the same wait, which is what lets fifty requests be in
 *   flight while the process sits in a single select().
 * - **Timers**, so a delay is a deadline the loop wakes up for rather than a
 *   `usleep()` that stops the whole program.
 * - **Streams**, for any backend that can expose a socket — a database driver
 *   that can, a queue, a subprocess. Nothing in the framework uses them yet;
 *   the seam exists so that adding one does not mean rewriting the loop.
 *
 * `tick()` is the only place the process is allowed to wait, and while it waits
 * it waits for everything at once.
 *
 * **What stock PHP does not offer.** There is no `curl_multi_fdset()`, so the
 * file descriptors libcurl is watching cannot be folded into the same
 * `stream_select()` as the stream watchers. When both kinds are pending the
 * loop waits on cURL with a short bound and polls the streams without blocking,
 * which costs a wake-up every few milliseconds in that mixed case and nothing
 * at all in the common one.
 */
final class EventLoop
{
    /** How long a single wait may last, so a tick stays responsive. */
    private const MAX_WAIT = 0.05;

    /**
     * How long to pause when cURL reports it has nothing to wait on yet.
     *
     * curl_multi_select() returns immediately while a transfer is still being
     * set up — resolving a name, for instance — and a loop that trusted it
     * would spin a core at a hundred per cent for the duration.
     */
    private const SETUP_PAUSE = 0.001;

    private ?CurlMultiHandle $multi = null;

    /** @var array<int, array{handle: CurlHandle, onDone: callable}> */
    private array $transfers = [];

    /** @var array<int, array{at: float, onFire: callable}> */
    private array $timers = [];

    /** @var array<int, array{stream: resource, onReady: callable, write: bool}> */
    private array $watchers = [];

    private int $nextId = 1;

    /**
     * Watch a cURL transfer, and call back when it finishes.
     *
     * The handle is added to the shared multi handle, so the transfer starts
     * progressing on the next tick whether or not anybody is awaiting it yet.
     *
     * @param CurlHandle $handle A configured handle
     * @param callable(CurlHandle, int, string): void $onDone Receives the handle, the cURL error number and message
     * @return void
     */
    public function addTransfer(CurlHandle $handle, callable $onDone): void
    {
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }

        curl_multi_add_handle($this->multi, $handle);

        $this->transfers[spl_object_id($handle)] = ['handle' => $handle, 'onDone' => $onDone];
    }

    /**
     * Stop watching a transfer, leaving it unfinished.
     *
     * @param CurlHandle $handle The handle
     * @return void
     */
    public function cancelTransfer(CurlHandle $handle): void
    {
        $id = spl_object_id($handle);

        if (!isset($this->transfers[$id])) {
            return;
        }

        unset($this->transfers[$id]);

        if ($this->multi !== null) {
            curl_multi_remove_handle($this->multi, $handle);
        }

        curl_close($handle);
    }

    /**
     * Call something after a delay.
     *
     * @param float $seconds How long to wait
     * @param callable(): void $onFire What to run
     * @return int An id, for cancelling
     */
    public function addTimer(float $seconds, callable $onFire): int
    {
        $id = $this->nextId++;

        $this->timers[$id] = ['at' => microtime(true) + max(0.0, $seconds), 'onFire' => $onFire];

        return $id;
    }

    /**
     * Cancel a timer that has not fired.
     *
     * @param int $id The id returned by addTimer()
     * @return void
     */
    public function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /**
     * Call something when a stream becomes readable or writable.
     *
     * @param resource $stream The stream
     * @param callable(resource): void $onReady What to run
     * @param bool $write True to wait for writability instead of readability
     * @return int An id, for cancelling
     */
    public function addWatcher($stream, callable $onReady, bool $write = false): int
    {
        $id = $this->nextId++;

        $this->watchers[$id] = ['stream' => $stream, 'onReady' => $onReady, 'write' => $write];

        return $id;
    }

    /**
     * Stop watching a stream.
     *
     * @param int $id The id returned by addWatcher()
     * @return void
     */
    public function cancelWatcher(int $id): void
    {
        unset($this->watchers[$id]);
    }

    /**
     * Whether the loop has anything left to wait for.
     *
     * @return bool True when nothing is pending
     */
    public function isEmpty(): bool
    {
        return $this->transfers === [] && $this->timers === [] && $this->watchers === [];
    }

    /**
     * How much work the loop is holding.
     *
     * @return array{transfers: int, timers: int, watchers: int} The counts
     */
    public function pending(): array
    {
        return [
            'transfers' => count($this->transfers),
            'timers' => count($this->timers),
            'watchers' => count($this->watchers),
        ];
    }

    /**
     * Wait for something to happen, then hand out what did.
     *
     * @param bool $block False to check without waiting
     * @return void
     */
    public function tick(bool $block = true): void
    {
        $timeout = $block ? $this->waitFor() : 0.0;

        if ($this->transfers !== []) {
            $this->pollTransfers($timeout);
        } elseif ($this->watchers !== []) {
            $this->pollWatchers($timeout);
        } elseif ($timeout > 0.0) {
            // Only timers are pending, so the loop has nothing to watch but a clock.
            usleep((int) ($timeout * 1_000_000));
        }

        if ($this->transfers !== [] && $this->watchers !== []) {
            // Both kinds pending: the streams were not part of the wait above.
            $this->pollWatchers(0.0);
        }

        $this->fireTimers();
    }

    /**
     * How long this tick may wait.
     *
     * @return float Seconds
     */
    private function waitFor(): float
    {
        $timeout = self::MAX_WAIT;

        foreach ($this->timers as $timer) {
            $timeout = min($timeout, max(0.0, $timer['at'] - microtime(true)));
        }

        return $timeout;
    }

    /**
     * Drive the cURL transfers, and settle the ones that finished.
     *
     * @param float $timeout How long to wait for activity
     * @return void
     */
    private function pollTransfers(float $timeout): void
    {
        if ($this->multi === null) {
            return;
        }

        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0 && $timeout > 0.0) {
            /*
             * -1 means libcurl had no descriptor to wait on, which happens
             * while a transfer is still being set up. Sleeping briefly is
             * libcurl's own advice; without it this is a spin loop.
             */
            if (curl_multi_select($this->multi, $timeout) === -1) {
                usleep((int) (self::SETUP_PAUSE * 1_000_000));
            }

            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        while (($message = curl_multi_info_read($this->multi)) !== false) {
            if ($message['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $handle = $message['handle'];
            $id = spl_object_id($handle);

            if (!isset($this->transfers[$id])) {
                continue;
            }

            $onDone = $this->transfers[$id]['onDone'];
            unset($this->transfers[$id]);

            $errno = (int) $message['result'];
            $error = $errno === CURLE_OK ? '' : (curl_error($handle) ?: curl_strerror($errno) ?? '');

            curl_multi_remove_handle($this->multi, $handle);

            /*
             * The callback reads the body and the transfer information off the
             * handle, so the handle is closed after it has run, not before.
             */
            $onDone($handle, $errno, (string) $error);

            curl_close($handle);
        }
    }

    /**
     * Wait for the watched streams, and hand out the ready ones.
     *
     * @param float $timeout How long to wait
     * @return void
     */
    private function pollWatchers(float $timeout): void
    {
        $read = [];
        $write = [];

        foreach ($this->watchers as $id => $watcher) {
            if ($watcher['write']) {
                $write[$id] = $watcher['stream'];

                continue;
            }

            $read[$id] = $watcher['stream'];
        }

        if ($read === [] && $write === []) {
            return;
        }

        $except = null;
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);

        if (@stream_select($read, $write, $except, $seconds, $microseconds) < 1) {
            return;
        }

        foreach (array_merge(array_keys($read), array_keys($write)) as $id) {
            if (!isset($this->watchers[$id])) {
                continue;
            }

            $watcher = $this->watchers[$id];
            unset($this->watchers[$id]);

            ($watcher['onReady'])($watcher['stream']);
        }
    }

    /**
     * Run the timers whose moment has come.
     *
     * @return void
     */
    private function fireTimers(): void
    {
        $now = microtime(true);

        foreach ($this->timers as $id => $timer) {
            if ($timer['at'] > $now) {
                continue;
            }

            unset($this->timers[$id]);

            ($timer['onFire'])();
        }
    }
}
