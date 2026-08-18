<?php

namespace App\Services\Service;

use App\Models\Service;

/**
 * Narrow capability used by the durable fulfillment state machine when it
 * changes the status of a service backed by a capacity reservation.
 */
class FulfillmentStatusTransitionService
{
    /** @var array<int, int> */
    private static array $serviceIds = [];

    public static function isCoordinating(Service $service): bool
    {
        return isset(self::$serviceIds[(int) $service->getKey()]);
    }

    public static function run(Service $service, callable $transition): mixed
    {
        $serviceId = (int) $service->getKey();
        self::$serviceIds[$serviceId] = (self::$serviceIds[$serviceId] ?? 0) + 1;

        try {
            return $transition();
        } finally {
            self::$serviceIds[$serviceId]--;
            if (self::$serviceIds[$serviceId] === 0) {
                unset(self::$serviceIds[$serviceId]);
            }
        }
    }
}
