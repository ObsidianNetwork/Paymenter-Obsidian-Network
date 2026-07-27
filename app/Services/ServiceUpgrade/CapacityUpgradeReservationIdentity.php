<?php

namespace App\Services\ServiceUpgrade;

use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CapacityUpgradeReservationIdentity
{
    /**
     * Identify a dynamic upgrade from durable database state even when its
     * extension PHP files are unavailable.
     */
    public function exists(int $serviceUpgradeId): bool
    {
        if (
            $serviceUpgradeId <= 0
            || !Schema::hasTable('ptero_resource_reservations')
            || !Schema::hasColumns('ptero_resource_reservations', [
                'purpose',
                'service_upgrade_id',
            ])
        ) {
            return false;
        }

        return DB::table('ptero_resource_reservations')
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $serviceUpgradeId)
            ->exists();
    }

    public function requiresCoordinator(ServiceUpgrade $upgrade): bool
    {
        $hasDynamicReservation = $this->exists((int) $upgrade->id);
        if (
            $upgrade->capacity_mode
                === ServiceUpgrade::CAPACITY_MODE_DYNAMIC
        ) {
            if ($upgrade->target_stock_reserved_at !== null) {
                throw new \RuntimeException(
                    'The upgrade has conflicting dynamic and static capacity ownership.'
                );
            }

            return true;
        }
        if (
            $upgrade->capacity_mode
                === ServiceUpgrade::CAPACITY_MODE_STATIC
        ) {
            if ($hasDynamicReservation) {
                throw new \RuntimeException(
                    'The upgrade has conflicting static and dynamic capacity ownership.'
                );
            }

            return false;
        }
        if ($upgrade->capacity_mode !== null) {
            throw new \RuntimeException(
                'The upgrade has an invalid durable capacity mode.'
            );
        }
        if ($hasDynamicReservation) {
            return true;
        }
        if ($upgrade->target_stock_reserved_at !== null) {
            return false;
        }

        $upgrade->loadMissing(['service.product', 'product']);

        return (bool) $upgrade->service?->product?->usesDynamicResources()
            || (bool) $upgrade->product?->usesDynamicResources();
    }
}
