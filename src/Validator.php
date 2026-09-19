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

            foreach ($rulesArray as $rule) {
                if (str_starts_with($rule, 'min:')) {
                    $min = explode(':', $rule)[1];
                    if (strlen($value) < $min) {
                        $errors[$field][] = $errorMessages[$field]['min'] ?? "$field must be at least $min characters long.";
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = explode(':', $rule)[1];
                    if (strlen($value) > $max) {
                        $errors[$field][] = $errorMessages[$field]['max'] ?? "$field must be at most $max characters long.";
                    }
                } elseif ($rule === 'required' && empty($value)) {
                    $errors[$field][] = $errorMessages[$field]['required'] ?? "$field is required.";
                } elseif ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = $errorMessages[$field]['email'] ?? "$field must be a valid email.";
                } elseif ($rule === 'alpha' && !ctype_alpha($value)) {
                    $errors[$field][] = $errorMessages[$field]['alpha'] ?? "$field must contain only letters.";
                } elseif ($rule === 'alphanum' && !ctype_alnum($value)) {
                    $errors[$field][] = $errorMessages[$field]['alphanum'] ?? "$field must contain only letters and numbers.";
                } elseif ($rule === 'number' && !ctype_digit($value)) {
                    $errors[$field][] = $errorMessages[$field]['number'] ?? "$field must contain only numbers.";
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
