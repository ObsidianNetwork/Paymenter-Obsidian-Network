<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\Service;

class RenewServiceService
{
    /**
     * Handle the service renewal.
     *
     * @return void
     */
    public function handle(Service $service, ?Invoice $invoice = null)
    {
        if ($service->status == Service::STATUS_PENDING) {
            $fulfillment = app(DurableFulfillmentService::class);
            $reservationBacked = $fulfillment->isReservationBacked($service);
            $currentlyDynamic = $service->product?->usesDynamicResources() ?? false;

            if ($reservationBacked || $currentlyDynamic) {
                if ($invoice === null) {
                    throw new \RuntimeException(
                        'A reservation-backed pending service cannot be provisioned without its paid invoice.'
                    );
                }

                $fulfillment->commitPaidService($service, $invoice);
                CreateJob::dispatch($service)->afterCommit();

                return;
            }
        }

        if ($service->product->server) {
            if ($service->status == Service::STATUS_SUSPENDED) {
                UnsuspendJob::dispatch($service);
            } elseif ($service->status == Service::STATUS_PENDING) {
                CreateJob::dispatch($service)->afterCommit();
            }
        }

        $service->expires_at = $service->calculateNextDueDate();
        $service->status = Service::STATUS_ACTIVE;
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->save()
        );
    }
}
