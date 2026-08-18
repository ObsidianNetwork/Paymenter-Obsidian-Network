<?php

namespace App\Support;

final class StrictDecimal
{
    /**
     * Pricing metadata is stored as JSON but ultimately feeds DECIMAL(17,2)
     * invoice columns. Keep metadata canonical, finite, and precise enough for
     * resource rates without accepting exponent notation or float sentinels.
     */
    public const MAX_SCALE = 8;

    public const MAX_VALUE = 99_999_999_999_999.99;

    private function __construct() {}

    public static function parseNonNegative(
        mixed $value,
        float $maximum = self::MAX_VALUE
    ): ?float {
        if (is_int($value)) {
            return $value >= 0 && $value <= $maximum
                ? (float) $value
                : null;
        }

        if (is_float($value)) {
            if (
                !is_finite($value)
                || $value < 0
                || $value > $maximum
                || abs($value - round($value, self::MAX_SCALE)) > 1e-10
            ) {
                return null;
            }

            return $value;
        }

        if (
            !is_string($value)
            || preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,' . self::MAX_SCALE . '})?$/D',
                $value
            ) !== 1
        ) {
            return null;
        }

        $parsed = (float) $value;

        return is_finite($parsed) && $parsed <= $maximum
            ? $parsed
            : null;
    }
}
