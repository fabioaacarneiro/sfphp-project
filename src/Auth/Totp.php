<?php

namespace SfphpProject\src\Auth;

use InvalidArgumentException;
use SfphpProject\src\Time;

/**
 * Time-based one-time passwords (RFC 6238), the codes an authenticator app
 * shows: what two-factor sign-in is built from.
 *
 *     $secret = Totp::secret();                                  // store it with the user, encrypted if you can
 *     $uri = Totp::uri($secret, 'ana@example.com', 'My App');    // show it as a QR code
 *
 *     $step = Totp::verify($secret, $request->input('code'), $user->totp_last_step);
 *     if ($step !== null) { $user->totp_last_step = $step; ... signed in ... }
 *
 * SHA-1, six digits and thirty seconds, because that is what every
 * authenticator app reads without asking. A code from the step before or the
 * one after is accepted too, for a phone whose clock drifts; the step that
 * matched comes back so it can be stored, and a code whose step is not later
 * than the stored one is refused — without that, a code seen over someone's
 * shoulder works for a minute and a half.
 *
 * Drawing the QR code is not here: it needs an image library, and the URI is
 * all an authenticator needs. Show the secret as text beside it for people who
 * type it in.
 */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new secret, base32 as authenticator apps expect it.
     *
     * @param int $bytes Random bytes before encoding; 20 is what RFC 4226 recommends
     * @return string
     */
    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    /**
     * The code for a moment — now, unless one is given.
     *
     * @param string $secret The base32 secret
     * @param int|null $time A Unix time, or null for now
     * @return string Six digits, leading zeros kept
     */
    public static function code(string $secret, ?int $time = null): string
    {
        return self::codeAt(self::base32Decode($secret), intdiv($time ?? Time::now()->getTimestamp(), self::PERIOD));
    }

    /**
     * Whether a code is right, and for which time step.
     *
     * @param string $secret The base32 secret
     * @param string $code What the user typed; spaces and dashes are ignored
     * @param int|null $lastStep The step returned by the last successful verify(), so a code is not accepted twice
     * @param int $window How many steps either side of now are accepted
     * @param int|null $time A Unix time, or null for now
     * @return int|null The time step the code belongs to — store it — or null when it is wrong or already used
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, int $window = 1, ?int $time = null): ?int
    {
        $code = str_replace([' ', '-'], '', $code);

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $key = self::base32Decode($secret);
        $now = intdiv($time ?? Time::now()->getTimestamp(), self::PERIOD);
        $matched = null;

        // Every candidate is computed, so how long this takes does not say which one matched.
        for ($step = $now - max(0, $window); $step <= $now + max(0, $window); $step++) {
            if (hash_equals(self::codeAt($key, $step), $code) && $matched === null) {
                $matched = $step;
            }
        }

        if ($matched === null || ($lastStep !== null && $matched <= $lastStep)) {
            return null;
        }

        return $matched;
    }

    /**
     * The otpauth:// URI an authenticator app reads, usually from a QR code.
     *
     * @param string $secret The base32 secret
     * @param string $account Who it is for, as the app shows it: an e-mail address, a username
     * @param string $issuer The application's name, as the app shows it
     * @return string
     * @throws InvalidArgumentException If the issuer or the account contains ":"
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        if (str_contains($issuer, ':') || str_contains($account, ':')) {
            throw new InvalidArgumentException('Neither the issuer nor the account may contain ":", which separates them in the URI.');
        }

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Single-use codes for a user who has lost the device.
     *
     * Show them once and store them hashed, as passwords are — Hash::make() —
     * and delete each one when it is used. They are ten characters from an
     * alphabet without 0, 1, O or I, so they can be read aloud and typed.
     *
     * @param int $count How many
     * @return list<string> Like "k7pq2-xm4ra"
     */
    public static function recoveryCodes(int $count = 8): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];

        while (count($codes) < max(1, $count)) {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $codes[substr($code, 0, 5) . '-' . substr($code, 5)] = true;
        }

        return array_keys($codes);
    }

    private static function codeAt(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $text): string
    {
        $text = strtoupper(str_replace([' ', '-', '='], '', $text));

        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
            throw new InvalidArgumentException('A TOTP secret is base32: the letters A to Z and the digits 2 to 7.');
        }

        $bits = '';

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
