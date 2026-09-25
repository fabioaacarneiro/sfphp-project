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
     * **A field without `required` is optional.** Absent or empty — null, "",
     * whitespace only, or an empty array — it passes, and none of its rules run: `min:3` on an
     * optional nickname judges a nickname, not the absence of one. With
     * `required`, an absent or empty field gets that one message and nothing
     * else, wherever `required` sits in the list: there is no length to check
     * in a value that is not there. Once the field has a value, every other
     * rule applies.
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
            $rulesArray = array_values(array_filter(
                array_map(static fn (mixed $rule): string => self::canonical(trim((string) $rule)), $rulesArray),
                static fn (string $rule): bool => $rule !== ''
            ));

            // A misspelt rule is an error whether or not this field was sent,
            // so the check does not depend on which rules end up running.
            foreach ($rulesArray as $rule) {
                self::assertKnownRule($rule, $field);
            }

            $value = $data[$field] ?? null;
            $stringValue = is_scalar($value) ? (string) $value : '';

            if (self::isEmpty($value)) {
                if (in_array('required', $rulesArray, true)) {
                    $errors[$field][] = self::message($errorMessages, $field, 'required');
                }

                continue;
            }

            foreach ($rulesArray as $rule) {
                if ($rule === 'required') {
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

        /*
         * Only a bound counts an array — by its items. Every other rule is
         * about a single value, and an array fails it: a text rule used to
         * read an array as "", so ['a', 'b'] passed maxLength:1 and came back
         * in validated().
         */
        if (is_array($value)) {
            return self::message($custom, $field, str_starts_with($rule, 'pattern:') ? 'pattern' : $rule);
        }

        if (preg_match('/^pattern:(.+)$/s', $rule, $matches) === 1) {
            /*
             * Delimited here rather than by the caller, so a rule cannot reach
             * into PCRE modifiers — /e is gone from PHP, but a pattern that
             * chooses its own delimiters is still a pattern that can choose
             * its own flags. The delimiter is a control character no pattern
             * is written with, so nothing inside the pattern needs escaping;
             * escaping "/" turned an already-escaped "\/" into "\\/", which
             * ends the pattern early.
             */
            $pattern = "\x01" . $matches[1] . "\x01u";
            $result = @preg_match($pattern, $stringValue);

            if ($result === false) {
                throw new InvalidArgumentException(sprintf(
                    'The pattern for field "%s" is not a valid regular expression: %s',
                    $field,
                    $matches[1]
                ));
            }

            return $result === 1 ? null : self::message($custom, $field, 'pattern');
        }

        switch ($rule) {
            case 'email':
                return self::isEmail($stringValue) ? null : self::message($custom, $field, 'email');

            case 'url':
                return self::isUrl($stringValue) ? null : self::message($custom, $field, 'url');

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
     * Whether a string is an e-mail address, in any script.
     *
     * josé@exemplo.com.br and user@münchen.de are addresses people have, and
     * FILTER_VALIDATE_EMAIL refused both. The local part is checked with the
     * Unicode flag, and an internationalised domain is converted to its ASCII
     * form first when ext-intl is there to do it.
     *
     * @param string $value The value
     * @return bool
     */
    public static function isEmail(string $value): bool
    {
        $at = strrpos($value, '@');

        if ($at === false || $at === 0 || $at === strlen($value) - 1) {
            return false;
        }

        $local = substr($value, 0, $at);
        $domain = self::asciiHost(substr($value, $at + 1));

        return $domain !== null
            && filter_var($local . '@' . $domain, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    /**
     * Whether a string is a web address: http or https, with a host.
     *
     * "javascript:alert(1)" and "foo:bar" are URLs to PHP's filter in the
     * loosest sense and never what a form asking for a website means. A host
     * or a path in another script is accepted — https://例え.jp/café — by
     * converting the host and percent-encoding the rest before checking.
     *
     * @param string $value The value
     * @return bool
     */
    public static function isUrl(string $value): bool
    {
        if (preg_match('#^(https?)://([^/?\#:]+)(:\d+)?(.*)$#iu', $value, $parts) !== 1) {
            return false;
        }

        $host = self::asciiHost($parts[2]);

        if ($host === null) {
            return false;
        }

        $rest = (string) preg_replace_callback('/[^\x21-\x7E]/u', static fn (array $m): string => rawurlencode($m[0]), $parts[4]);

        return filter_var($parts[1] . '://' . $host . $parts[3] . $rest, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * A host name in ASCII, converting an internationalised one.
     *
     * @param string $host The host
     * @return string|null The ASCII host, or null when it cannot be converted
     */
    private static function asciiHost(string $host): ?string
    {
        if (preg_match('/^[\x00-\x7F]*$/', $host) === 1) {
            return $host;
        }

        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : $ascii;
    }

    /**
     * Whether a value counts as not given: absent, null, an empty array, or a
     * string with nothing but whitespace.
     *
     * An HTML form sends "" for a field left blank, so blank is treated the
     * same as absent, and a name of three spaces is no more a name than an
     * empty one. SFJS decides the same way in the browser. "0" and 0 are
     * values.
     */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return true;
        }

        return is_string($value) && preg_match('/^\s*$/u', $value) === 1;
    }

    /**
     * A rule with its name spelled the way the validator knows it.
     *
     * Names are read regardless of case, as SFJS reads them, so "Required"
     * and "minlength:3" mean what they look like on both sides. The argument
     * is left exactly as written — a pattern is case-sensitive.
     *
     * @param string $rule The rule as written
     * @return string The rule with its canonical name
     */
    private static function canonical(string $rule): string
    {
        $at = strpos($rule, ':');
        $name = $at === false ? $rule : substr($rule, 0, $at);
        $known = ['required', 'email', 'url', 'alpha', 'alphanum', 'number', 'min', 'max', 'minLength', 'maxLength', 'pattern'];

        foreach ($known as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return $candidate . ($at === false ? '' : substr($rule, $at));
            }
        }

        return $rule;
    }

    /**
     * Refuse a rule name the validator does not know.
     *
     * @throws InvalidArgumentException If the rule name is not recognised
     */
    private static function assertKnownRule(string $rule, string $field): void
    {
        $known = preg_match('/^(min|max|minLength|maxLength):-?\d+(?:\.\d+)?$/i', $rule) === 1
            || preg_match('/^pattern:.+$/s', $rule) === 1
            || in_array($rule, ['required', 'email', 'url', 'alpha', 'alphanum', 'number'], true);

        if (!$known) {
            throw new InvalidArgumentException(sprintf(
                'Unknown validation rule "%s" for field "%s". The rules are: %s.',
                $rule,
                $field,
                'required, email, url, number, alpha, alphanum, min:N, max:N, minLength:N, maxLength:N, pattern:REGEX'
            ));
        }
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

        // An array is measured by its items, whichever of the four rules.
        if (is_array($value)) {
            $count = count($value);
            $limitAsInt = (int) $bound;
            $failed = $isMin ? $count < $limitAsInt : $count > $limitAsInt;

            return $failed
                ? self::message($custom, $field, $isMin ? 'minItems' : 'maxItems', [$isMin ? 'min' : 'max' => $limitAsInt], $limitAsInt)
                : null;
        }

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

        /*
         * The field's own name in the visitor's language, when the catalog
         * has one: "validation.attributes.email" => "e-mail". Without it the
         * key was printed as it is, and a Portuguese page read
         * "name é obrigatório".
         */
        $attribute = 'validation.attributes.' . $field;
        $replace += ['field' => Translator::has($attribute) ? Translator::get($attribute) : $field];
        $key = 'validation.' . $rule;

        return $count === null
            ? Translator::get($key, $replace)
            : Translator::choice($key, $count, $replace);
    }
}
