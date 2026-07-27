<?php

namespace App\Services\ServiceUpgrade;

use App\Models\ServiceUpgrade;

/**
 * Narrow capability for changing the immutable ownership proof of an upgrade.
 */
class ServiceUpgradeMutationCoordinator
{
    /** @var array<int, int> */
    private static array $upgradeIds = [];

    public static function isCoordinating(
        ServiceUpgrade $upgrade
    ): bool {
        return isset(self::$upgradeIds[(int) $upgrade->getKey()]);
    }

    public static function run(
        ServiceUpgrade $upgrade,
        callable $mutation
    ): mixed {
        $upgradeId = (int) $upgrade->getKey();
        self::$upgradeIds[$upgradeId] =
            (self::$upgradeIds[$upgradeId] ?? 0) + 1;

        try {
            return $mutation();
        } finally {
            self::$upgradeIds[$upgradeId]--;
            if (self::$upgradeIds[$upgradeId] === 0) {
                unset(self::$upgradeIds[$upgradeId]);
            }
        }
    }

    public static function save(ServiceUpgrade $upgrade): bool
    {
        return (bool) self::run(
            $upgrade,
            fn (): bool => $upgrade->save()
        );
    }
}
