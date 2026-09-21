<?php

namespace SfphpProject\src;

/**
 * UTF-8 aware string helpers.
 *
 * Every function PHP ships in its default string library counts bytes, not
 * characters: strlen("日本") is 6, substr() happily cuts a multi-byte sequence
 * in half, and ctype_alpha("José") is false. For a framework meant to run
 * worldwide those are not edge cases, they are the common case.
 *
 * The obvious fix is mbstring, but mbstring is an optional extension that is
 * not compiled into every PHP build, and requiring it would put a hard
 * dependency in front of every install. PCRE, on the other hand, is always
 * present and its /u modifier gives full UTF-8 and Unicode property support.
 * So the character-level operations here are built on preg_* rather than mb_*,
 * and the framework keeps working on a bare PHP build.
 *
 * Case conversion is the one exception: correct Unicode case folding needs
 * per-locale tables that PCRE does not expose, so upper() and lower() use
 * mbstring when it is available and fall back to ASCII folding when it is not.
 * That degrades a display detail instead of corrupting stored data.
 */
final class Str
{
    /**
     * Count the characters in a UTF-8 string.
     *
     * @param string $value The string to measure
     * @return int The number of characters, not bytes
     */
    public static function length(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        $count = preg_match_all('/./us', $value);

        /*
         * preg_match_all returns false on malformed UTF-8. Falling back to the
         * byte length keeps callers from having to handle a failure case, and
         * a validator comparing byte length against a limit still rejects
         * oversized input rather than letting it through.
         */
        return $count === false ? strlen($value) : $count;
    }

    /**
     * Take a slice of a UTF-8 string without splitting a character.
     *
     * @param string $value The string to slice
     * @param int $start The character offset to start at
     * @param int|null $length The number of characters to take, or null for the rest
     * @return string The extracted substring
     */
    public static function substr(string $value, int $start, ?int $length = null): string
    {
        $characters = self::characters($value);
        $slice = $length === null
            ? array_slice($characters, $start)
            : array_slice($characters, $start, $length);

        return implode('', $slice);
    }

    /**
     * Shorten a string to a maximum number of characters.
     *
     * The suffix counts towards the limit, so the result never exceeds it.
     *
     * @param string $value The string to shorten
     * @param int $limit The maximum length of the result, in characters
     * @param string $suffix Appended when the string is shortened
     * @return string The shortened string
     */
    public static function truncate(string $value, int $limit, string $suffix = '...'): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (self::length($value) <= $limit) {
            return $value;
        }

        $suffixLength = self::length($suffix);
        if ($suffixLength >= $limit) {
            return self::substr($suffix, 0, $limit);
        }

        return self::substr($value, 0, $limit - $suffixLength) . $suffix;
    }

    /**
     * Reverse a UTF-8 string.
     *
     * @param string $value The string to reverse
     * @return string The reversed string
     */
    public static function reverse(string $value): string
    {
        return implode('', array_reverse(self::characters($value)));
    }

    /**
     * Check whether a string contains only letters, in any script.
     *
     * @param string $value The string to check
     * @return bool True when every character is a Unicode letter
     */
    public static function isAlpha(string $value): bool
    {
        return $value !== '' && preg_match('/^\p{L}+$/u', $value) === 1;
    }

    /**
     * Check whether a string contains only letters and digits, in any script.
     *
     * @param string $value The string to check
     * @return bool True when every character is a Unicode letter or number
     */
    public static function isAlphanumeric(string $value): bool
    {
        return $value !== '' && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1;
    }

    /**
     * Check whether a string contains only ASCII digits.
     *
     * Deliberately ASCII-only: the result is meant to be safe to cast to int,
     * and PHP's integer cast does not understand Eastern Arabic or Devanagari
     * digits. Accepting them here would produce silent zeroes downstream.
     *
     * @param string $value The string to check
     * @return bool True when every character is an ASCII digit
     */
    public static function isNumeric(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    /**
     * Check whether a string is well-formed UTF-8.
     *
     * @param string $value The string to check
     * @return bool True when the string decodes as UTF-8
     */
    public static function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * Convert a string to upper case.
     *
     * @param string $value The string to convert
     * @return string The upper-cased string
     */
    public static function upper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    /**
     * Convert a string to lower case.
     *
     * @param string $value The string to convert
     * @return string The lower-cased string
     */
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Upper-case the first character of a string.
     *
     * @param string $value The string to convert
     * @return string The string with its first character upper-cased
     */
    public static function ucfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return self::upper(self::substr($value, 0, 1)) . self::substr($value, 1);
    }

    /**
     * Split a UTF-8 string into its characters.
     *
     * @param string $value The string to split
     * @return array<int, string> The characters, in order
     */
    private static function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? str_split($value) : $characters;
    }
}
