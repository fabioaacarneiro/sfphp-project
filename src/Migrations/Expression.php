<?php

namespace SfphpProject\src\Migrations;

/**
 * Represents a raw SQL expression inside the schema builder.
 */
final class Expression
{
    /**
     * Create a raw expression.
     *
     * @param string $value The SQL fragment
     */
    public function __construct(private string $value) {}

    /**
     * Get the raw SQL fragment.
     *
     * @return string
     */
    public function value(): string
    {
        return $this->value;
    }
}
