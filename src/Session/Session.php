<?php

namespace SfphpProject\src\Session;

use SessionHandlerInterface;
use SfphpProject\src\Time;

/**
 * The session, and the two deadlines it lives under.
 *
 * Two things were missing before this existed, and both of them are the kind
 * auditors ask about.
 *
 * A session had **no deadline of its own**: it lasted whatever `php.ini` said,
 * which on a shared host is a number nobody in the application chose. An idle
 * timeout closes a session left open on a machine someone walked away from; an
 * absolute one closes a session that has been alive too long however busy it
 * has been, which is what limits the value of a stolen cookie.
 *
 * And the store was **always local files**, so two application instances could
 * not see each other's sessions. That is what forces sticky sessions on a load
 * balancer, and it is why a deploy that adds a second machine logs everybody
 * out. A handler puts the session somewhere both instances can reach.
 *
 *     Session::put('cart_id', 42);
 *     Session::get('cart_id');
 *     Session::invalidate();
 */
final class Session
{
    /** When the session was first created, as a Unix timestamp. */
    private const STARTED_AT = '_sfphp_started_at';

    /** When the session was last used, as a Unix timestamp. */
    private const LAST_ACTIVITY = '_sfphp_last_activity';

    /** Why the session ended, kept only long enough for the next request to say so. */
    private const EXPIRED = '_sfphp_expired';

    /**
     * Start the session, enforcing both deadlines.
     *
     * @param bool|null $secure Whether the cookie may only travel over HTTPS
     * @param SessionHandlerInterface|null $handler Where sessions are stored, or null for PHP's own
     * @param int $idleSeconds Seconds of inactivity before the session ends, 0 to disable
     * @param int $absoluteSeconds Seconds since creation before the session ends, 0 to disable
     * @return void
     */
    public static function start(
        ?bool $secure = null,
        ?SessionHandlerInterface $handler = null,
        int $idleSeconds = 0,
        int $absoluteSeconds = 0
    ): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::enforceDeadlines($idleSeconds, $absoluteSeconds);

            return;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        /*
         * "Headers already sent" means a cookie can no longer be set, so on the
         * web there is no point starting a session the visitor will never be
         * able to send back. On the command line it means only that something
         * has been printed, and there is no cookie in the first place — a queue
         * worker, a console command and the test suite all print long before
         * they touch a session.
         *
         * Conflating the two made this fail in a way that was very hard to see:
         * one deprecation notice printed at startup was enough to make every
         * later session silently not exist, taking the idle and absolute
         * deadlines with it.
         */
        if (PHP_SAPI !== 'cli' && headers_sent()) {
            return;
        }

        /*
         * Without strict mode PHP adopts whatever session id the cookie
         * carries, including one that was never issued. That is session
         * fixation in its simplest form: plant an id in the victim's browser,
         * wait for them to log in, then use the same id. Regenerating on login
         * already closes it; this closes the door the attacker knocks on.
         */
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secure ?? false,
            'samesite' => 'Lax',
        ]);

        if ($handler !== null) {
            session_set_save_handler($handler, true);
        }

        /*
         * PHP's garbage collector deletes a session file once it is older
         * than session.gc_maxlifetime — 1440 seconds by default — so a
         * SESSION_LIFETIME of two hours ended after 24 idle minutes. The
         * collector is told to keep sessions at least as long as the longer
         * of the two deadlines.
         *
         * Debian and Ubuntu also clean sessions from a cron job that reads
         * php.ini, not this setting; there, raise session.gc_maxlifetime in
         * php.ini as well, or keep sessions in the cache or the database.
         */
        $keep = max($idleSeconds, $absoluteSeconds);

        if ($keep > (int) ini_get('session.gc_maxlifetime')) {
            ini_set('session.gc_maxlifetime', (string) $keep);
        }

        session_start();

        self::enforceDeadlines($idleSeconds, $absoluteSeconds);
    }

    /**
     * End the session when either deadline has passed, and stamp it otherwise.
     *
     * @param int $idleSeconds Seconds of inactivity allowed, 0 to disable
     * @param int $absoluteSeconds Seconds since creation allowed, 0 to disable
     * @return void
     */
    private static function enforceDeadlines(int $idleSeconds, int $absoluteSeconds): void
    {
        $now = Time::now()->getTimestamp();

        $startedAt = $_SESSION[self::STARTED_AT] ?? null;
        $lastActivity = $_SESSION[self::LAST_ACTIVITY] ?? null;

        if ($startedAt === null) {
            $_SESSION[self::STARTED_AT] = $now;
            $_SESSION[self::LAST_ACTIVITY] = $now;

            return;
        }

        $expired = null;

        if ($absoluteSeconds > 0 && $now - (int) $startedAt >= $absoluteSeconds) {
            $expired = 'absolute';
        } elseif ($idleSeconds > 0 && $lastActivity !== null && $now - (int) $lastActivity >= $idleSeconds) {
            $expired = 'idle';
        }

        if ($expired !== null) {
            /*
             * Everything goes, and the id changes with it. Emptying the data
             * while keeping the id would leave the visitor holding a cookie
             * that still names a live session, which is most of what expiring
             * one was meant to prevent.
             */
            self::invalidate();
            $_SESSION[self::EXPIRED] = $expired;

            return;
        }

        $_SESSION[self::LAST_ACTIVITY] = $now;
    }

    /**
     * Why the session ended, when the previous request's session expired.
     *
     * Reads once and forgets, so a "your session timed out" notice is shown on
     * the request after the expiry and not on every request afterwards.
     *
     * @return string|null "idle", "absolute", or null when nothing expired
     */
    public static function expiredReason(): ?string
    {
        $reason = $_SESSION[self::EXPIRED] ?? null;
        unset($_SESSION[self::EXPIRED]);

        return is_string($reason) ? $reason : null;
    }

    /**
     * Read a value.
     *
     * @param string $key The key
     * @param mixed $default What to return when the key is absent
     * @return mixed The value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Write a value.
     *
     * @param string $key The key
     * @param mixed $value The value
     * @return void
     */
    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Whether a key is present and not null.
     *
     * @param string $key The key
     * @return bool True when present
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a value.
     *
     * @param string $key The key
     * @return void
     */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Every value the application put in the session.
     *
     * The framework's own bookkeeping is left out: the timestamps are not the
     * application's data and showing them would invite writing to them.
     *
     * @return array<string, mixed> The session data
     */
    public static function all(): array
    {
        $data = $_SESSION ?? [];

        unset($data[self::STARTED_AT], $data[self::LAST_ACTIVITY], $data[self::EXPIRED]);

        return $data;
    }

    /**
     * Empty the session, keeping the id.
     *
     * @return void
     */
    public static function flush(): void
    {
        $_SESSION = [];
    }

    /**
     * Give the session a new id, keeping its data.
     *
     * @return void
     */
    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Same distinction as in start(): the cookie is a web concern only.
        if (PHP_SAPI === 'cli' || !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    /**
     * Empty the session and give it a new id.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::flush();
        self::regenerate();

        $now = Time::now()->getTimestamp();
        $_SESSION[self::STARTED_AT] = $now;
        $_SESSION[self::LAST_ACTIVITY] = $now;
    }

    /**
     * The current session id, or null when no session is active.
     *
     * @return string|null The session id
     */
    public static function id(): ?string
    {
        $id = session_status() === PHP_SESSION_ACTIVE ? session_id() : false;

        return $id === false || $id === '' ? null : $id;
    }

    /**
     * When the session was created.
     *
     * @return int|null The Unix timestamp, or null when no session is active
     */
    public static function startedAt(): ?int
    {
        $value = $_SESSION[self::STARTED_AT] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * When the session was last used.
     *
     * @return int|null The Unix timestamp, or null when no session is active
     */
    public static function lastActivityAt(): ?int
    {
        $value = $_SESSION[self::LAST_ACTIVITY] ?? null;

        return $value === null ? null : (int) $value;
    }
}
