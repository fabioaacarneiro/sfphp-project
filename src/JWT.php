<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * Class JWT
 * @package SfphpProject\src
 */
class JWT
{
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
     * @return string
     */
    private static function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;

        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
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
        $key = $_ENV['JWT_KEY'] ?? '';

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
     * @param $headerBase64
     * @param $payloadBase64
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
     * @param array $user
     * @return string
     * @throws RuntimeException If JWT_KEY is missing or too short
     */
    public static function generate(array $user): string
    {
        $header = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT'
        ]);

        $payload = json_encode([
           'id' => $user['id'],
           'email' => $user['email'],
           'exp' => time() + 3600
        ]);

        $headerBase64 = self::base64UrlEncode($header);
        $payloadBase64 = self::base64UrlEncode($payload);

        $signature = self::generateSignature(
            $headerBase64,
            $payloadBase64
        );

        return $headerBase64 . '.' . $payloadBase64 . '.' . $signature;
    }

    /**
     * @param string $token
     * @return bool
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

        $payload = json_decode(self::base64UrlDecode($payloadBase64), true);

        if (!isset($payload['exp']) || time() >= $payload['exp']) {
            return false;
        }

        return true;
    }
}
