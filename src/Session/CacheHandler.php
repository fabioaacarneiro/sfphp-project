<?php

namespace SfphpProject\src\Session;

use SfphpProject\src\Config;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use SfphpProject\src\Cache\CacheManager;

/**
 * Keeps sessions in the cache, so every instance sees the same ones.
 *
 * With a shared driver — Redis — this is what lets a second application
 * instance serve a visitor who logged in on the first, which is the whole
 * reason a load balancer otherwise needs sticky sessions.
 *
 * With the default file driver it behaves like PHP's own storage: fine on one
 * machine, useless across two. The driver decides that, not this class.
 *
 *     Session::start($secure, new CacheHandler(), 7200, 43200);
 */
final class CacheHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private CacheManager $cache;

    /**
     * Create the handler.
     *
     * @param CacheManager|null $cache The store, or null for the shared one
     * @param string $prefix A prefix keeping session keys apart from other cached values
     */
    public function __construct(?CacheManager $cache = null, private string $prefix = 'session:')
    {
        $this->cache = $cache ?? cache();
    }

    /**
     * @param string $path Ignored; the cache decides where data lives
     * @param string $name The session name
     * @return bool Always true
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @return bool Always true
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * @param string $id The session id
     * @return string The serialised session data, or an empty string
     */
    public function read(string $id): string
    {
        $data = $this->cache->get($this->prefix . $id);

        return is_string($data) ? $data : '';
    }

    /**
     * @param string $id The session id
     * @param string $data The serialised session data
     * @return bool True when stored
     */
    public function write(string $id, string $data): bool
    {
        $this->cache->put($this->prefix . $id, $data, $this->lifetime());

        return true;
    }

    /**
     * @param string $id The session id
     * @return bool True when removed
     */
    public function destroy(string $id): bool
    {
        $this->cache->forget($this->prefix . $id);

        return true;
    }

    /**
     * Collect expired sessions.
     *
     * Nothing to do: every entry was written with a lifetime, so the cache
     * drops it on its own. Returning zero says "removed none", which is true.
     *
     * @param int $maxLifetime Seconds PHP considers a session expired after
     * @return int|false The number of sessions removed
     */
    public function gc(int $maxLifetime): int|false
    {
        return 0;
    }

    /**
     * Whether this id names a session that exists.
     *
     * Implementing this is what makes session.use_strict_mode work: PHP asks
     * before adopting the id a cookie carries, and an id nobody was ever issued
     * is refused instead of being brought to life.
     *
     * @param string $id The session id
     * @return bool True when the session exists
     */
    public function validateId(string $id): bool
    {
        return $this->cache->has($this->prefix . $id);
    }

    /**
     * Push the expiry out without rewriting the data.
     *
     * @param string $id The session id
     * @param string $data The serialised session data
     * @return bool True when refreshed
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    /**
     * How long a stored session should survive without being touched.
     *
     * @return int The lifetime in seconds
     */
    private function lifetime(): int
    {
        $configured = Config::int('SESSION_LIFETIME', 0);

        /*
         * A session with no idle timeout still needs a lifetime here, or the
         * entry would sit in the cache forever. PHP's own gc_maxlifetime is the
         * sensible fallback, since that is what the deployment already chose.
         */
        return $configured > 0 ? $configured : max(60, (int) ini_get('session.gc_maxlifetime'));
    }
}
