<?php

namespace SfphpProject\src;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * The application's clock, and the one place a time zone is decided.
 *
 * Everything the framework stores, computes and logs is **UTC**. That is not a
 * preference, it is what keeps a stored timestamp meaning one thing: a naive
 * "2026-09-21 23:00:00" in a database column is only an instant if something
 * says which zone it was written in, and if that answer is "whatever the server
 * was set to" then moving the server, or adding a second one, silently changes
 * what every existing row means.
 *
 * The damage from getting this wrong is **retroactive**, which is why it is
 * worth being strict about. A missing feature can be added later; a year of
 * timestamps written in an unknown zone cannot be repaired later, because the
 * information needed to repair them was never written down.
 *
 * A zone still matters for showing a time to a person, and that is a separate
 * decision made at the edge:
 *
 *     Time::now();                              // the instant, in UTC
 *     Time::display($order->created_at);        // rendered in APP_TIMEZONE
 *     Time::in($order->created_at, 'Asia/Tokyo');
 */
final class Time
{
    /**
     * The instant Time::now() returns while the clock is frozen.
     *
     * Time-dependent tests are flaky by construction: a test that asserts on
     * "now" races the clock, and a test that builds an expectation from a
     * second call to now() can straddle a second boundary. Freezing removes
     * that, and it only exists for tests — see freeze().
     */
    private static ?DateTimeImmutable $frozen = null;

    /**
     * The current instant, in UTC.
     *
     * @return DateTimeImmutable The current instant
     */
    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::utc());
    }

    /**
     * The UTC zone.
     *
     * @return DateTimeZone The UTC zone
     */
    public static function utc(): DateTimeZone
    {
        static $utc = null;

        return $utc ??= new DateTimeZone('UTC');
    }

    /**
     * Turn a value into an instant in UTC.
     *
     * A string carrying an offset or a zone keeps its meaning and is converted;
     * a naive string — which is what a database hands back — is read in $zone,
     * defaulting to UTC because that is what the framework wrote.
     *
     * @param mixed $value A date, a timestamp, or a string
     * @param string|DateTimeZone|null $zone The zone a naive string is written in
     * @return DateTimeImmutable|null The instant in UTC, or null when unparsable
     */
    public static function parse(mixed $value, string|DateTimeZone|null $zone = null): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }

        /*
         * A Unix timestamp is already an instant — it has no zone to guess, and
         * setTimestamp() on a UTC object keeps it that way.
         */
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{9,}$/', $value) === 1)) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(self::utc());
        }

        $zone = self::zone($zone);
        $text = trim((string) $value);

        /*
         * A date and, optionally, a time and an offset — the shapes a database,
         * a form's date input and ISO 8601 produce. PHP's parser accepts far
         * more: "next monday" and "1 week ago" were dates, and 2026-02-30
         * silently became the 2nd of March. A value this reads as a date is
         * the one it was given, or it is null.
         */
        $shape = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?)?\s*(Z|[+-]\d{2}(?::?\d{2})?|[A-Za-z_]+\/[A-Za-z_\/]+)?$/';

        if (preg_match($shape, $text, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if (isset($parts[4]) && $parts[4] !== '' && ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59)) {
            return null;
        }

        try {
            /*
             * The zone passed to the constructor applies only when the string
             * carries none of its own, which is exactly the rule wanted here.
             */
            return (new DateTimeImmutable($text, $zone))->setTimezone(self::utc());
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The same instant, seen from another zone.
     *
     * For rendering only. The value returned still points at the same moment;
     * storing it would store the same instant. Nothing about this changes what
     * is in the database.
     *
     * @param DateTimeInterface $at The instant
     * @param string|DateTimeZone $zone The zone to read it in
     * @return DateTimeImmutable The same instant, in that zone
     */
    public static function in(DateTimeInterface $at, string|DateTimeZone $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(self::zone($zone));
    }

    /**
     * Render an instant in the application's display zone.
     *
     * @param DateTimeInterface $at The instant
     * @param string $format A date() format
     * @return string The formatted time
     */
    public static function display(DateTimeInterface $at, string $format = 'Y-m-d H:i:s'): string
    {
        return self::in($at, self::displayZone())->format($format);
    }

    /**
     * The zone times are shown in, from APP_TIMEZONE.
     *
     * Only display reads this. The runtime zone is UTC and is not configurable,
     * because a setting that changes how stored timestamps are interpreted is a
     * setting that can silently rewrite the meaning of existing data.
     *
     * @return DateTimeZone The display zone
     */
    public static function displayZone(): DateTimeZone
    {
        return self::zone(Config::get('APP_TIMEZONE', 'UTC'));
    }

    /**
     * Render an instant the way the visitor's language writes dates.
     *
     * "21 de septiembre de 2026" rather than "2026-09-21", without the calling
     * code knowing which language it is in.
     *
     * This is the one place the framework uses ext-intl, and it is optional in
     * the same way ext-mbstring is for Str: when the extension is missing the
     * output degrades to an ISO-like format rather than being wrong. A date
     * that reads as 2026-09-21 in a Spanish page is worse than a localised one
     * and better than one that says September when it means Setembro.
     *
     * @param DateTimeInterface $at The instant
     * @param string $dateStyle "none", "short", "medium", "long" or "full"
     * @param string $timeStyle The same, for the time part
     * @param string|null $locale The language, or null for the active one
     * @return string The formatted time, in the display zone
     */
    public static function localised(
        DateTimeInterface $at,
        string $dateStyle = 'medium',
        string $timeStyle = 'short',
        ?string $locale = null
    ): string {
        $instant = self::in($at, self::displayZone());
        $locale = str_replace('_', '-', $locale ?? (function_exists('locale') ? locale() : 'en'));

        if (!class_exists(\IntlDateFormatter::class)) {
            return self::withoutIntl($instant, $dateStyle, $timeStyle);
        }

        $styles = [
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
        ];

        $formatter = new \IntlDateFormatter(
            $locale,
            $styles[$dateStyle] ?? \IntlDateFormatter::MEDIUM,
            $styles[$timeStyle] ?? \IntlDateFormatter::SHORT,
            self::displayZone()
        );

        $formatted = $formatter->format($instant);

        return $formatted === false ? self::withoutIntl($instant, $dateStyle, $timeStyle) : $formatted;
    }

    /**
     * Render a number the way the visitor's language writes numbers.
     *
     * 1.234,56 in Portuguese and 1,234.56 in English — the separators swap, so
     * printing one for the other is not a cosmetic difference.
     *
     * @param int|float $value The number
     * @param int $decimals How many decimal places
     * @param string|null $locale The language, or null for the active one
     * @return string The formatted number
     */
    public static function number(int|float $value, int $decimals = 0, ?string $locale = null): string
    {
        $locale = str_replace('_', '-', $locale ?? (function_exists('locale') ? locale() : 'en'));

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($value);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        /*
         * Without ext-intl the separators are guessed from the language rather
         * than looked up, which covers the split that actually matters — a
         * comma decimal separator or a full stop — and gets the long tail
         * wrong. Documented as such: a wrong guess here is a readable number in
         * the wrong convention, not a wrong number.
         */
        $comma = !str_starts_with($locale, 'en');

        return number_format($value, $decimals, $comma ? ',' : '.', $comma ? '.' : ',');
    }

    /**
     * Format a date without ext-intl.
     *
     * @param DateTimeImmutable $at The instant, already in the display zone
     * @param string $dateStyle The requested date style
     * @param string $timeStyle The requested time style
     * @return string The formatted time
     */
    private static function withoutIntl(DateTimeImmutable $at, string $dateStyle, string $timeStyle): string
    {
        $date = $dateStyle === 'none' ? '' : $at->format('Y-m-d');
        $time = $timeStyle === 'none' ? '' : $at->format('H:i');

        return trim($date . ' ' . $time);
    }

    /**
     * Format an instant the way it is stored in a datetime column.
     *
     * @param DateTimeInterface $at The instant
     * @return string The UTC value, as the database holds it
     */
    public static function toDatabase(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)
            ->setTimezone(self::utc())
            ->format('Y-m-d H:i:s');
    }

    /**
     * Hold the clock still.
     *
     * **For tests.** Nothing in a request should call this: the frozen value is
     * static, so under a persistent runtime it would outlive the request that
     * set it and every later request would be told the wrong time.
     *
     * @param DateTimeImmutable|string $at The instant to report, in UTC
     * @return DateTimeImmutable The frozen instant
     * @throws InvalidArgumentException When the value cannot be read as a time
     */
    public static function freeze(DateTimeImmutable|string $at = 'now'): DateTimeImmutable
    {
        $instant = self::parse($at);

        if ($instant === null) {
            throw new InvalidArgumentException('Cannot freeze the clock at "' . $at . '".');
        }

        return self::$frozen = $instant;
    }

    /**
     * Let the clock run again.
     *
     * @return void
     */
    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    /**
     * Whether the clock is currently held still.
     *
     * @return bool True while frozen
     */
    public static function frozen(): bool
    {
        return self::$frozen !== null;
    }

    /**
     * Coerce a zone given as a name or an object.
     *
     * @param string|DateTimeZone|null $zone The zone, or null for UTC
     * @return DateTimeZone The zone
     * @throws InvalidArgumentException When the name is not a known zone
     */
    private static function zone(string|DateTimeZone|null $zone): DateTimeZone
    {
        if ($zone instanceof DateTimeZone) {
            return $zone;
        }

        if ($zone === null || $zone === '' || $zone === 'UTC') {
            return self::utc();
        }

        try {
            return new DateTimeZone($zone);
        } catch (Exception) {
            throw new InvalidArgumentException('Unknown time zone "' . $zone . '".');
        }
    }
}
