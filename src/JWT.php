<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Class JWT
 * @package SfphpProject\src
 */
class JWT
{
    /**
     * How long a token is valid for, in seconds.
     *
     * Fixed at an hour. A token cannot be withdrawn once issued — that is what
     * being stateless costs — so the lifetime is the only thing limiting how
     * long a stolen one is useful. See TokenDenylist for the way out.
     */
    public const LIFETIME = 3600;

    /**
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * @param string $data
     * @return string|null
     */
    private static function base64UrlDecode(string $data): ?string
    {
        $padding = strlen($data) % 4;

        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Minimum length, in bytes, accepted for the signing key.
     */
    private const MIN_KEY_LENGTH = 32;

    /**
     * Get the signing key from the environment.
     *
     * @return string
     * @throws RuntimeException If the key is missing or too short
     */
    private static function getSecretKey(): string
    {
        $key = Env::get('JWT_KEY') ?? '';

        if ($key === '' || $key === 'your_secret_token_here') {
            throw new RuntimeException(
                'JWT_KEY is not set. Define it in your .env file before issuing or validating tokens.'
            );
        }

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException(
                'JWT_KEY must be at least ' . self::MIN_KEY_LENGTH . ' bytes long.'
            );
        }

        return $key;
    }

    /**
     * @param string $headerBase64 The Base64 URL-encoded header
     * @param string $payloadBase64 The Base64 URL-encoded payload
     * @return string
     */
    private static function generateSignature(
        string $headerBase64, 
        string $payloadBase64
    ): string {
        return self::base64UrlEncode(
            hash_hmac('sha256', 
                $headerBase64 . '.' . $payloadBase64, 
                self::getSecretKey(), 
                true
            )
        );
    }

    /**
     * Sign a token for a user.
     *
     *     JWT::generate(['id' => $user->id]);
     *     JWT::generate(['id' => $user->id, 'role' => 'editor']);
     *
     * "id" is required, because it is what TokenGuard looks the user up by.
     * Every other claim is optional and travels as given. An e-mail used to
     * be required, which put personal data into every token — a token is
     * readable by anyone who holds it, signed but not encrypted.
     *
     * "iat" and "exp" are the framework's and cannot be overridden.
     *
     * @param array<string, mixed> $user The claims, "id" among them
     * @return string The signed token
     * @throws InvalidArgumentException If the id claim is absent
     * @throws JsonException If a claim cannot be JSON encoded
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function generate(array $user): string
    {
        if (!array_key_exists('id', $user)) {
            throw new InvalidArgumentException('JWT generation requires an "id" claim.');
        }

        $header = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR);

        $issuedAt = time();

        unset($user['iat'], $user['exp']);

        $payload = json_encode([
            ...$user,
            /*
             * "iat" is what makes "log out everywhere" possible. Revoking a
             * user's tokens records a moment rather than a list — the tokens
             * were never stored anywhere to list — and a token is refused when
             * it was issued before that moment. Without it, a fresh login could
             * not be told apart from the tokens being revoked.
             */
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::LIFETIME,
        ], JSON_THROW_ON_ERROR);

        $headerBase64 = self::base64UrlEncode($header);
        $payloadBase64 = self::base64UrlEncode($payload);

        $signature = self::generateSignature(
            $headerBase64,
            $payloadBase64
        );

        return $headerBase64 . '.' . $payloadBase64 . '.' . $signature;
    }

    /**
     * @param string $token The token to validate
     * @return bool True when the token is structurally valid, signed, and unexpired
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function validate(string $token): bool
    {
        $tokenParts = explode('.', $token);
        
        if (count($tokenParts) !== 3) {
            return false;
        }

        [$headerBase64, $payloadBase64, $signature] = $tokenParts;

        $expectedSignature = self::generateSignature(
            $headerBase64,
            $payloadBase64
        );
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }

        $header = self::decodeJsonSegment($headerBase64);
        $payload = self::decodeJsonSegment($payloadBase64);

        if (
            $header === null
            || ($header['alg'] ?? null) !== 'HS256'
            || ($header['typ'] ?? null) !== 'JWT'
            || $payload === null
            || !isset($payload['exp'])
            || !is_int($payload['exp'])
            || time() >= $payload['exp']
        ) {
            return false;
        }

        return true;
    }

    /**
     * Validate a token and return what it carries.
     *
     * validate() answers whether a token is trustworthy; this answers who it
     * is about. A guard needs both, and doing it in one pass avoids verifying
     * the signature twice — or worse, reading the payload of a token whose
     * signature was never checked.
     *
     * @param string $token The token to read
     * @return array<string, mixed>|null The claims, or null when the token is not valid
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function claims(string $token): ?array
    {
        if (!self::validate($token)) {
            return null;
        }

        $parts = explode('.', $token);

        return self::decodeJsonSegment($parts[1]);
    }

    /**
     * Decode a Base64 URL-encoded JSON object.
     *
     * @param string $segment The encoded JWT segment
     * @return array|null The decoded object, or null when invalid
     */
    private static function decodeJsonSegment(string $segment): ?array
    {
        $decoded = self::base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * How long a token is valid for.
     *
     * @return int The lifetime in seconds
     */
    public static function lifetime(): int
    {
        return self::LIFETIME;
    }
}
