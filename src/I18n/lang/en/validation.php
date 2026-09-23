<?php

/**
 * Validation failures, which are read by the person filling in the form.
 *
 * ":field" is the field name and ":min"/":max" the rule's argument. The
 * length rules are written as plural forms so a language that inflects the
 * noun after the number can say so.
 */

return [
    'required' => ':field is required.',
    'email' => ':field must be a valid email.',
    'min' => '{1} :field must be at least one character long.|[2,*] :field must be at least :min characters long.',
    'max' => '{1} :field must be at most one character long.|[2,*] :field must be at most :max characters long.',
    'alpha' => ':field must contain only letters.',
    'alphanum' => ':field must contain only letters and numbers.',
    'url' => ':field must be a valid URL.',
    'pattern' => ':field is not in the expected format.',
    'minValue' => ':field must be at least :min.',
    'maxValue' => ':field must be at most :max.',
    'number' => ':field must contain only numbers.',
];
