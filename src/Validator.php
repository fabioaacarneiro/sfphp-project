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
     * Rules are pipe separated, and a rule that takes an argument uses a
     * colon: `required|min:3|alpha`. Where a pattern needs a pipe of its own,
     * pass the rules as an array instead — `['required', 'pattern:^(a|b)$']` —
     * because the separator cannot tell one from the other.
     *
     * **`min` and `max` follow the value.** On a number they compare the
     * number, on anything else they count characters:
     *
     *     'age'  => 'required|number|min:18'   at least eighteen years old
     *     'name' => 'required|min:3'           at least three characters
     *
     * That is what people mean when they write it, and the alternative was
     * worse than useless: `min:18` on an age used to demand eighteen
     * *characters*, so it passed for 7 and failed for 21 without a word.
     * When the distinction matters — a postcode is a number that is really a
     * string — `minLength` and `maxLength` always count characters.
     *
     * Length is counted in characters and the alphabetic rules accept every
     * script, so "日本語" is 3 and `alpha` accepts "José".
     *
     * @param array<string, mixed> $data The data to validate
     * @param array<string, string|list<string>> $rules The rules, keyed by field
     * @param array<string, array<string, string>> $errorMessages Messages to use instead of the defaults
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
            $rulesArray = is_array($ruleSet) ? $ruleSet : explode('|', (string) $ruleSet);
            $value = $data[$field] ?? null;
            $stringValue = is_scalar($value) ? (string) $value : '';

            foreach ($rulesArray as $rule) {
                $rule = trim((string) $rule);

                if ($rule === '') {
                    continue;
                }

                $failure = self::check($rule, $field, $value, $stringValue, $errorMessages);

                if ($failure !== null) {
                    $errors[$field][] = $failure;
                }
            }
        }

        /*
         * Only the fields that had rules are handed back as validated. The
         * whole input used to be returned, so a form that validated "name"
         * and "email" still delivered a smuggled "is_admin" to whatever the
         * caller passed validated() to, and $fillable was the only thing
         * standing in the way. A field that has rules but was not sent stays
         * out as well, so an optional field is never invented as null.
         * Rules apply to a top-level key, so an array value under a
         * validated key comes back whole.
         */
        $validated = array_intersect_key($data, $rules);

        return new ValidationResult($validated, $errors);
    }

    /**
     * Apply one rule, and say what went wrong.
     *
     * @param string $rule The rule
     * @param string $field The field name
     * @param mixed $value The value as it arrived
     * @param string $stringValue The value as a string
     * @param array<string, array<string, string>> $custom Messages to use instead of the defaults
     * @return string|null The failure message, or null when it passed
     * @throws InvalidArgumentException If the rule name is not recognised
     */
    private static function check(
        string $rule,
        string $field,
        mixed $value,
        string $stringValue,
        array $custom
    ): ?string {
        if (preg_match('/^(min|max|minLength|maxLength):(-?\d+(?:\.\d+)?)$/i', $rule, $matches) === 1) {
            return self::checkBound(strtolower($matches[1]), $matches[2], $field, $value, $stringValue, $custom);
        }

        if (preg_match('/^pattern:(.+)$/s', $rule, $matches) === 1) {
            /*
             * Delimited here rather than by the caller, so a rule cannot reach
             * into PCRE modifiers — /e is gone from PHP, but a pattern that
             * chooses its own delimiters is still a pattern that can choose
             * its own flags.
             */
            $pattern = '/' . str_replace('/', '\/', $matches[1]) . '/u';

            return @preg_match($pattern, $stringValue) === 1
                ? null
                : self::message($custom, $field, 'pattern');
        }

        switch ($rule) {
            case 'required':
                return $value === null || $value === '' || $value === []
                    ? self::message($custom, $field, 'required')
                    : null;

            case 'email':
                return filter_var($stringValue, FILTER_VALIDATE_EMAIL) === false
                    ? self::message($custom, $field, 'email')
                    : null;

            case 'url':
                return filter_var($stringValue, FILTER_VALIDATE_URL) === false
                    ? self::message($custom, $field, 'url')
                    : null;

            case 'alpha':
                return Str::isAlpha($stringValue) ? null : self::message($custom, $field, 'alpha');

            case 'alphanum':
                return Str::isAlphanumeric($stringValue) ? null : self::message($custom, $field, 'alphanum');

            case 'number':
                return Str::isNumeric($stringValue) ? null : self::message($custom, $field, 'number');
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown validation rule "%s" for field "%s". The rules are: %s.',
            $rule,
            $field,
            'required, email, url, number, alpha, alphanum, min:N, max:N, minLength:N, maxLength:N, pattern:REGEX'
        ));
    }

    /**
     * Apply min, max, minLength or maxLength.
     *
     * @param string $name The rule name in lower case
     * @param string $limit The argument
     * @param string $field The field name
     * @param mixed $value The value as it arrived
     * @param string $stringValue The value as a string
     * @param array<string, array<string, string>> $custom Messages to use instead of the defaults
     * @return string|null The failure message, or null when it passed
     */
    private static function checkBound(
        string $name,
        string $limit,
        string $field,
        mixed $value,
        string $stringValue,
        array $custom
    ): ?string {
        $bound = (float) $limit;
        $isMin = $name === 'min' || $name === 'minlength';

        /*
         * A number is compared as a number; everything else is counted. A
         * field declared with minLength is counted whatever it holds, which is
         * how a postcode — a number that is really a string — says so.
         */
        $byValue = ($name === 'min' || $name === 'max') && is_numeric($value);

        if ($byValue) {
            $number = (float) $stringValue;
            $failed = $isMin ? $number < $bound : $number > $bound;

            return $failed
                ? self::message($custom, $field, $isMin ? 'minValue' : 'maxValue', [$isMin ? 'min' : 'max' => $limit])
                : null;
        }

        $length = Str::length($stringValue);
        $limitAsInt = (int) $bound;
        $failed = $isMin ? $length < $limitAsInt : $length > $limitAsInt;

        return $failed
            ? self::message(
                $custom,
                $field,
                $isMin ? 'min' : 'max',
                [$isMin ? 'min' : 'max' => $limitAsInt],
                $limitAsInt
            )
            : null;
    }

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
