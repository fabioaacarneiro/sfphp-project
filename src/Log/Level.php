<?php

namespace SfphpProject\src\Log;

/**
 * The severity of a log record.
 *
 * These are the eight levels of RFC 5424, which is what PSR-3 also uses. The
 * names are worth matching even without depending on the package: every log
 * collector already understands them, so a record lands in the right bucket in
 * whatever the deployment sends its output to.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * How severe this level is, with a higher number meaning more severe.
     *
     * RFC 5424 numbers these the other way round — emergency is 0 and debug is
     * 7 — which reads backwards everywhere it is used for filtering. This
     * returns the order a human expects, so "at least warning" is a comparison
     * rather than a puzzle.
     *
     * @return int The severity, 0 for debug through 7 for emergency
     */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    /**
     * Whether a record at this level should be kept when filtering.
     *
     * @param self $minimum The lowest level being kept
     * @return bool True when this level is at least as severe as the minimum
     */
    public function atLeast(self $minimum): bool
    {
        return $this->severity() >= $minimum->severity();
    }

    /**
     * Build a level from its name, falling back rather than throwing.
     *
     * Configuration is a string typed by a person, and a typo in LOG_LEVEL
     * should not stop the application from starting — it should not silently
     * discard records either, so the fallback is the caller's choice.
     *
     * @param string|null $name The level's name, in any case
     * @param self $fallback The level to use when the name is not one of these
     * @return self The matching level, or the fallback
     */
    public static function fromName(?string $name, self $fallback = self::Debug): self
    {
        return self::tryFrom(strtolower(trim((string) $name))) ?? $fallback;
    }
}
