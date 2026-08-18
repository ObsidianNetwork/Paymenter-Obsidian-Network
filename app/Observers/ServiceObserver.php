<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServiceObserver
{
    public function creating(Service $service): void
    {
        if (
            Product::query()
                ->find($service->product_id)
                ?->usesDynamicResources()
            && !CapacityServiceCreationCoordinator::isCoordinating()
        ) {
            throw new \RuntimeException(
                'Dynamic resource services cannot be created directly. Use customer checkout or an explicit capacity-aware import coordinator.'
            );
        }
    }

    public function updating(Service $service): void
    {
        if (
            $service->isDirty('status')
            && $service->status === Service::STATUS_CANCELLED
        ) {
            app(BillingChargeAttemptService::class)
                ->assertServiceTerminationAllowed($service);
        }
        $reservationBacked = $this->hasCheckoutReservation($service);
        $activeUpgrade = $this->hasActiveUpgrade($service);
        $identityChanged = $service->isDirty([
            'product_id',
            'plan_id',
            'quantity',
        ]);
        $billingAnchorChanged = $service->isDirty([
            'expires_at',
            'price',
            'period_base_price',
            'current_period_price',
            'pricing_ledger_started_at',
            'pricing_ledger_verified_at',
            'billing_cycles_completed',
            'coupon_id',
        ]);
        if (
            $identityChanged
            && !FulfillmentStatusTransitionService::isCoordinating(
                $service
            )
        ) {
            throw new \RuntimeException(
                'Existing service product, plan, and quantity changes require the capacity-aware fulfillment coordinator and upgrade flow.'
            );
        }
        if (
            ($reservationBacked || $activeUpgrade)
            && (
                $service->isDirty('user_id')
                || $service->isDirty('currency_code')
            )
            && !FulfillmentStatusTransitionService::isCoordinating($service)
        ) {
            throw new \RuntimeException(
                'A service with durable fulfillment or an active upgrade cannot change owner or currency outside the capacity-aware fulfillment coordinator.'
            );
        }

        if (
            $billingAnchorChanged
            && !FulfillmentStatusTransitionService::isCoordinating($service)
            && !ServiceBillingAnchorMutationCoordinator::isCoordinating(
                $service
            )
        ) {
            throw new \RuntimeException(
                'Service expiration, price, and coupon changes must be serialized with the fulfillment state machine.'
            );
        }

        if (
            $service->isDirty('status')
            && !FulfillmentStatusTransitionService::isCoordinating($service)
        ) {
            throw new \RuntimeException(
                'Existing service status is controlled by the fulfillment state machine.'
            );
        }
    }

    public function deleting(Service $service): void
    {
        app(BillingChargeAttemptService::class)
            ->assertServiceTerminationAllowed($service);
        throw new \RuntimeException(
            'Service fulfillment records cannot be hard deleted. Cancel and verify termination through the fulfillment state machine, then retain the record for stock and payment history.'
        );
    }

    /**
     * Handle the Service "created" event.
     */
    public function created(Service $service): void
    {
        event(new ServiceEvent\Created($service));
    }

    /**
     * Handle the Service "updated" event.
     */
    public function updated(Service $service): void
    {
        event(new ServiceEvent\Updated($service));
    }

    /**
     * Handle the Service "deleted" event.
     */
    public function deleted(Service $service): void
    {
        event(new ServiceEvent\Deleted($service));
    }

    private function hasCheckoutReservation(Service $service): bool
    {
        if (!Schema::hasTable('ptero_resource_reservations')) {
            return false;
        }

        $query = DB::table('ptero_resource_reservations')
            ->where('service_id', $service->id);
        if (Schema::hasColumn('ptero_resource_reservations', 'purpose')) {
            $query->where('purpose', 'checkout');
        }

        return $query->exists();
    }

    private function hasActiveUpgrade(Service $service): bool
    {
        if (!Schema::hasTable('service_upgrades')) {
            return false;
        }

        return DB::table('service_upgrades')
            ->where('service_id', $service->id)
            ->whereIn(
                'status',
                ServiceUpgrade::activeStatuses()
            )
            ->exists();
    }
}
