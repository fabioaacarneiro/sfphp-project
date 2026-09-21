<?php

namespace SfphpProject\app\controllers;

/**
 * Base controller for pages that render HTML.
 *
 * The accessors here return request values unchanged.
 *
 * An earlier version ran every value through FILTER_SANITIZE_SPECIAL_CHARS on
 * the way in, which looked like a security feature and was the opposite of
 * one. Escaping is a property of a destination, not of a value: "O'Brien"
 * became "O&#39;Brien" in the database, a password of "a<b" was hashed as
 * "a&lt;b" so the user could never log in again, and a value escaped for HTML
 * is still unsafe to drop into SQL, a shell command or a JSON document. It
 * also gave a false sense of safety, because the sanitised value was still
 * concatenated into whatever the controller did next.
 *
 * The rule the framework follows instead: validate on the way in, escape on
 * the way out.
 *
 *   - Validate with SfphpProject\src\Validator, which checks shape and length
 *     without modifying the value.
 *   - Bind, never concatenate, when talking to the database. QueryBuilder and
 *     RawQuery bind every value.
 *   - Escape at the point of output. SFHT escapes "{{ }}" automatically, and
 *     e() is available for raw PHP templates.
 */
class BaseController
{
    /**
     * Get a query string value.
     *
     * @param string $key The parameter name
     * @param string|null $default Returned when the parameter is absent
     * @return string|null The raw value, unmodified
     */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get a submitted form value.
     *
     * @param string $key The field name
     * @param string|null $default Returned when the field is absent
     * @return string|null The raw value, unmodified
     */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $_POST[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Get every submitted form value.
     *
     * @return array<string, mixed> The raw request body
     */
    public function all(): array
    {
        return $_POST;
    }

    /**
     * Check whether a form field was submitted with a value.
     *
     * @param string $key The field name
     * @return bool True when the field is present and not empty
     */
    public function filled(string $key): bool
    {
        $value = $_POST[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Send a redirect response and stop.
     *
     * @param string $url The destination path
     * @param int $status The HTTP status code
     * @return never
     */
    public function redirect(string $url, int $status = HTTP_FOUND): never
    {
        header('Location: ' . $url, true, $status);

        exit;
    }
}
