<?php

namespace App\Observers;

use App\Events\ServiceUpgrade as ServiceUpgradeEvent;
use App\Models\ServiceUpgrade;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;

class ServiceUpgradeObserver
{
    public function updating(ServiceUpgrade $serviceUpgrade): void
    {
        if (
            ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            return;
        }

        $hasImmutableQuote =
            $serviceUpgrade->getRawOriginal('source_fingerprint') !== null
            || $serviceUpgrade->getRawOriginal('target_fingerprint') !== null;
        if (
            $hasImmutableQuote
            && $serviceUpgrade->isDirty([
                'service_id',
                'product_id',
                'plan_id',
                'type',
                'source_snapshot',
                'target_snapshot',
                'source_fingerprint',
                'target_fingerprint',
                'quoted_amount',
                'currency_code',
                'credit_amount',
                'capacity_mode',
            ])
        ) {
            throw new \RuntimeException(
                'The signed upgrade quote identity is immutable after creation.'
            );
        }

        if (
            $serviceUpgrade->isDirty([
                'target_stock_reserved_quantity',
                'target_stock_reserved_at',
                'target_stock_released_at',
                'target_stock_consumed_at',
                'target_stock_fingerprint',
            ])
        ) {
            throw new \RuntimeException(
                'Static upgrade stock ownership can only be changed by its stock coordinator.'
            );
        }

        if (
            $serviceUpgrade->isDirty([
                'status',
                'active_service_guard_id',
                'invoice_id',
                'legacy_refund_only_at',
            ])
        ) {
            throw new \RuntimeException(
                'Upgrade billing and lifecycle state can only be changed by its fulfillment coordinator.'
            );
        }
    }

    /**
     * Handle the ServiceUpgrade "created" event.
     */
    public function created(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Created($serviceUpgrade));
    }

    /**
     * Handle the ServiceUpgrade "updated" event.
     */
    public function updated(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Updated($serviceUpgrade));
    }

    /**
     * Handle the ServiceUpgrade "deleted" event.
     */
    public function deleted(ServiceUpgrade $serviceUpgrade): void
    {
        event(new ServiceUpgradeEvent\Deleted($serviceUpgrade));
    }

    public function deleting(ServiceUpgrade $serviceUpgrade): void
    {
        throw new \RuntimeException(
            'Upgrade fulfillment history cannot be deleted. Cancel unpaid upgrades through the invoice coordinator and retain terminal records.'
        );
    }
}
