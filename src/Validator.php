<?php

namespace SfphpProject\src;

use InvalidArgumentException;

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
                        $errors[$field][] = $errorMessages[$field]['min']
                            ?? "$field must be at least $min characters long.";
                    }
                } elseif (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    $max = (int) $matches[1];
                    if (Str::length($stringValue) > $max) {
                        $errors[$field][] = $errorMessages[$field]['max']
                            ?? "$field must be at most $max characters long.";
                    }
                } elseif ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = $errorMessages[$field]['required']
                        ?? "$field is required.";
                } elseif ($rule === 'email' && !filter_var($stringValue, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = $errorMessages[$field]['email']
                        ?? "$field must be a valid email.";
                } elseif ($rule === 'alpha' && !Str::isAlpha($stringValue)) {
                    $errors[$field][] = $errorMessages[$field]['alpha']
                        ?? "$field must contain only letters.";
                } elseif ($rule === 'alphanum' && !Str::isAlphanumeric($stringValue)) {
                    $errors[$field][] = $errorMessages[$field]['alphanum']
                        ?? "$field must contain only letters and numbers.";
                } elseif ($rule === 'number' && !Str::isNumeric($stringValue)) {
                    $errors[$field][] = $errorMessages[$field]['number']
                        ?? "$field must contain only numbers.";
                } elseif (!in_array($rule, ['required', 'email', 'alpha', 'alphanum', 'number'], true)) {
                    throw new InvalidArgumentException(
                        "Unknown validation rule \"$rule\" for field \"$field\"."
                    );
                }
            }
        }

        return new ValidationResult($data, $errors);
    }
}
