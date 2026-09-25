<?php

namespace SfphpProject\src;

use LogicException;

/**
 * Outcome of a validate() call.
 *
 * validate() used to return either the original data or an array shaped like
 * ['errors' => [...]]. Both are plain arrays, so nothing stopped the caller
 * from feeding a failed validation straight into a model — which is exactly
 * what happened, writing rows full of NULLs.
 *
 * Returning a dedicated type makes that mistake impossible: the object is not
 * an array, so passing it where data is expected fails immediately and loudly.
 */
final class ValidationResult
{
    /**
     * @param array $data The input restricted to the fields that had rules
     * @param array $errors Errors found, keyed by field name
     */
    public function __construct(
        private array $data,
        private array $errors
    ) {}

    /**
     * Whether validation found at least one error.
     *
     * @return bool
     */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Whether the data satisfied every rule.
     *
     * @return bool
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * Errors found, keyed by field name.
     *
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The validated data: only the fields that had rules and were present in
     * the input. Anything else the client sent is left out, so the result is
     * safe to hand to a model without trusting $fillable alone.
     *
     * @return array
     * @throws LogicException If validation failed; check passes() first
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new LogicException(
                'Cannot read validated data from a failed validation. Check passes() or fails() first.'
            );
        }

        return $this->data;
    }
}
