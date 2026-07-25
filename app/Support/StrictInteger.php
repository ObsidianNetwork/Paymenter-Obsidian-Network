<?php

namespace App\Support;

final class StrictInteger
{
    /**
     * service_configs.slider_value is DECIMAL(12,4), leaving eight integer
     * digits. Every accepted selection must round-trip exactly.
     */
    public const MAX_STORED_SLIDER_VALUE = 99_999_999;

    private function __construct()
    {
    }

    /**
     * Parse a canonical base-10 integer without float coercion, exponent
     * notation, leading zero ambiguity, or platform-range saturation.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (
            ! is_string($value)
            || $value === '-0'
            || preg_match('/^-?(0|[1-9]\d*)$/D', $value) !== 1
        ) {
            return null;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE
        );
    }

    /**
     * Parse a whole number read from a DECIMAL database column. Database
     * drivers commonly return values such as "1024.00000000"; only a
     * canonical integer followed by zeroes is accepted.
     */
    public static function parseStoredDecimal(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (
            ! is_string($value)
            || preg_match('/^-?(0|[1-9]\d*)(?:\.0+)?$/D', $value) !== 1
        ) {
            return null;
        }

        $integer = strstr($value, '.', true);
        if ($integer === false) {
            $integer = $value;
        }

        $parsed = self::parse($integer);

        return $parsed !== null
            && abs($parsed) <= self::MAX_STORED_SLIDER_VALUE
                ? $parsed
                : null;
    }
}
