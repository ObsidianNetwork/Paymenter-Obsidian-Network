<?php

namespace App\Services\Service;

use Closure;

/**
 * Narrow capability for checkout or a future import workflow to create the
 * local half of a capacity-backed service before its reservation is bound.
 */
class CapacityServiceCreationCoordinator
{
    private static int $depth = 0;

    public static function isCoordinating(): bool
    {
        return self::$depth > 0;
    }

    public static function run(Closure $creation): mixed
    {
        self::$depth++;

        try {
            return $creation();
        } finally {
            self::$depth--;
        }
    }
}
