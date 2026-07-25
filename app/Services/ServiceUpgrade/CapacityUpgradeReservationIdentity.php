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
            || ! Schema::hasTable('ptero_resource_reservations')
            || ! Schema::hasColumns('ptero_resource_reservations', [
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
        if ($this->exists((int) $upgrade->id)) {
            return true;
        }

        $upgrade->loadMissing(['service.product', 'product']);

        return (bool) $upgrade->service?->product?->usesDynamicResources()
            || (bool) $upgrade->product?->usesDynamicResources();
    }
}
