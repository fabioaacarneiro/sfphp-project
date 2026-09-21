<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use SfphpProject\src\I18n\Translator;

/**
 * Validates data against a set of field rules.
 */
final class Validator
{
    /**
     * Validate data against the given rules.
     *
     * Rules are pipe separated, and rules that take an argument use a colon:
     * "required|min:3|alpha".
     *
     * Length is counted in characters and the alphabetic rules accept every
     * script, so "min:3" measures "日本語" as 3 and "alpha" accepts
     * "José". The byte-based strlen() and ASCII-only ctype_* functions used
     * before rejected or truncated perfectly valid input outside ASCII.
     *
     * @param array $data The data to validate
     * @param array $rules The validation rules, keyed by field name
     * @param array $errorMessages Custom error messages for validation failures
     * @return ValidationResult The result of the validation
     * @throws InvalidArgumentException If a rule name is not recognised
     */
    public static function validate(
        array $data,
        array $rules,
        array $errorMessages = []
    ): ValidationResult {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $rulesArray = explode('|', $ruleSet);
            $value = $data[$field] ?? null;
            $stringValue = is_scalar($value) ? (string) $value : '';

            foreach ($rulesArray as $rule) {
                if (preg_match('/^min:(\d+)$/', $rule, $matches)) {
                    $min = (int) $matches[1];
                    if (Str::length($stringValue) < $min) {
                        $errors[$field][] = self::message(
                            $errorMessages, $field, 'min', ['min' => $min], $min
                        );
                    }
                } elseif (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    $max = (int) $matches[1];
                    if (Str::length($stringValue) > $max) {
                        $errors[$field][] = self::message(
                            $errorMessages, $field, 'max', ['max' => $max], $max
                        );
                    }
                } elseif ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = self::message($errorMessages, $field, 'required');
                } elseif ($rule === 'email' && !filter_var($stringValue, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = self::message($errorMessages, $field, 'email');
                } elseif ($rule === 'alpha' && !Str::isAlpha($stringValue)) {
                    $errors[$field][] = self::message($errorMessages, $field, 'alpha');
                } elseif ($rule === 'alphanum' && !Str::isAlphanumeric($stringValue)) {
                    $errors[$field][] = self::message($errorMessages, $field, 'alphanum');
                } elseif ($rule === 'number' && !Str::isNumeric($stringValue)) {
                    $errors[$field][] = self::message($errorMessages, $field, 'number');
                } elseif (!in_array($rule, ['required', 'email', 'alpha', 'alphanum', 'number'], true)) {
                    throw new InvalidArgumentException(
                        "Unknown validation rule \"$rule\" for field \"$field\"."
                    );
                }
            }
        }

        return new ValidationResult($data, $errors);
    }

    /**
     * Build the message for a failed rule.
     *
     * A message passed in by the caller wins untouched — it is already the
     * exact wording that caller wanted, and running it through the translator
     * would look up a key that does not exist and hand the string back anyway.
     * Otherwise the rule's key is translated in the active locale.
     *
     * The length rules pass a count so the catalog can inflect: "at least one
     * character" reads badly as "at least 1 characters".
     *
     * @param array<string, array<string, string>> $custom Messages from the caller
     * @param string $field The field that failed
     * @param string $rule The rule that failed
     * @param array<string, string|int> $replace Extra placeholder values
     * @param int|null $count The number deciding the plural form, when there is one
     * @return string The message
     */
    private static function message(
        array $custom,
        string $field,
        string $rule,
        array $replace = [],
        ?int $count = null
    ): string {
        if (isset($custom[$field][$rule])) {
            return $custom[$field][$rule];
        }

        $replace += ['field' => $field];
        $key = 'validation.' . $rule;

        return $count === null
            ? Translator::get($key, $replace)
            : Translator::choice($key, $count, $replace);
    }
}
