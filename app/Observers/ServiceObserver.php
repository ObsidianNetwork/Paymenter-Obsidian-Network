<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\FulfillmentStatusTransitionService;
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
            && ! CapacityServiceCreationCoordinator::isCoordinating()
        ) {
            throw new \RuntimeException(
                'Dynamic resource services cannot be created directly. Use customer checkout or an explicit capacity-aware import coordinator.'
            );
        }
    }

    public function updating(Service $service): void
    {
        $currentProduct = Product::query()->find($service->getOriginal('product_id'));
        $targetProduct = Product::query()->find($service->product_id);
        $touchesDynamicProduct = (bool) (
            $currentProduct?->usesDynamicResources()
            || $targetProduct?->usesDynamicResources()
        );
        $reservationBacked = $this->hasCheckoutReservation($service);
        if (
            (
                $service->isDirty('product_id')
                || $service->isDirty('plan_id')
                || $service->isDirty('quantity')
                || (
                    $reservationBacked
                    && (
                        $service->isDirty('user_id')
                        || $service->isDirty('currency_code')
                    )
                )
            )
            && ($touchesDynamicProduct || $reservationBacked)
            && ! FulfillmentStatusTransitionService::isCoordinating($service)
        ) {
            throw new \RuntimeException(
                'Reservation-backed service identity changes require the capacity-aware fulfillment coordinator.'
            );
        }

        if (
            $service->isDirty('status')
            && ($touchesDynamicProduct || $reservationBacked)
            && ! FulfillmentStatusTransitionService::isCoordinating($service)
        ) {
            throw new \RuntimeException(
                'Dynamic service status is controlled by the fulfillment state machine.'
            );
        }
    }

    public function deleting(Service $service): void
    {
        if ($this->hasCheckoutReservation($service)) {
            throw new \RuntimeException(
                'Reservation-backed services must be cancelled through the fulfillment state machine and retained as fulfillment records.'
            );
        }
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
        if (! Schema::hasTable('ptero_resource_reservations')) {
            return false;
        }

        $query = DB::table('ptero_resource_reservations')
            ->where('service_id', $service->id);
        if (Schema::hasColumn('ptero_resource_reservations', 'purpose')) {
            $query->where('purpose', 'checkout');
        }

        return $query->exists();
    }
}
