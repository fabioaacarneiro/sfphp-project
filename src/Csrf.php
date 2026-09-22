<?php

namespace SfphpProject\src;

use SfphpProject\src\Session\Session;

use InvalidArgumentException;

/**
 * Provides session-backed CSRF tokens and verification helpers.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    private const TOKEN_BYTES = 32;

    /**
     * Start the PHP session with secure defaults when needed.
     *
     * @param bool|null $secure Whether the connection is HTTPS, or null to detect
     * @return void
     */
    public static function startSession(?bool $secure = null): void
    {
        /*
         * Kept as the name the framework has always used, now delegating to
         * the class that owns sessions. Going through Session is what makes the
         * idle and absolute deadlines unavoidable: a caller that started the
         * session some other way would have skipped them.
         */
        Session::start(
            $secure ?? self::isHttps(),
            null,
            defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 0,
            defined('SESSION_ABSOLUTE_LIFETIME') ? (int) SESSION_ABSOLUTE_LIFETIME : 0
        );
    }

    /**
     * Get the current CSRF token, generating one if needed.
     *
     * @return string The active CSRF token
     */
    public static function token(): string
    {
        self::startSession();

        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token)) {
            $token = self::generateToken();
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Render a hidden input field containing the CSRF token.
     *
     * @param string $name The input name used by forms
     * @return string The HTML hidden field
     */
    public static function field(string $name = '_token'): string
    {
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('CSRF field name is invalid.');
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * Render a meta tag containing the CSRF token.
     *
     * @param string $name The meta tag name
     * @return string The HTML meta tag
     */
    public static function meta(string $name = 'csrf-token'): string
    {
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('CSRF meta name is invalid.');
        }

        return sprintf(
            '<meta name="%s" content="%s">',
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * Verify a submitted token against the current session token.
     *
     * @param string|null $token The token to validate
     * @return bool True when the token matches the session token
     */
    public static function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        self::startSession();
        $sessionToken = Session::get(self::SESSION_KEY);

        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Extract a token from the current request.
     *
     * Supports form posts and the standard X-CSRF-Token header.
     *
     * @return string|null The submitted token, or null when absent
     */
    public static function fromRequest(): ?string
    {
        $candidates = [
            $_POST['_token'] ?? null,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
            $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Validate the current request token.
     *
     * @return bool True when the request token matches the session token
     */
    public static function validateRequest(): bool
    {
        return self::validate(self::fromRequest());
    }

    /**
     * Generate a random CSRF token.
     *
     * @return string The generated token
     */
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Determine whether the current request is running over HTTPS.
     *
     * @return bool True when the request is secure
     */
    private static function isHttps(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }
}
