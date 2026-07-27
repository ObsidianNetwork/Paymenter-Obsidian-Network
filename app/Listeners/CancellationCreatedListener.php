<?php

namespace App\Listeners;

use App\Events\ServiceCancellation\Created;
use App\Models\Service;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Support\Facades\DB;

class CancellationCreatedListener
{
    /**
     * Handle the event.
     */
    public function handle(Created $event): void
    {
        if ($event->cancellation->type == 'immediate') {
            DB::transaction(function () use ($event) {
                $service = Service::query()
                    ->whereKey($event->cancellation->service_id)
                    ->firstOrFail();
                $service->invoices()
                    ->where('status', 'pending')
                    ->orderBy('invoices.id')
                    ->get()
                    ->each(
                        fn ($invoice) => app(CancelInvoiceService::class)
                            ->handle(
                                $invoice,
                                'Service cancellation cancelled this unpaid invoice.'
                            )
                    );

                $service = Service::query()
                    ->whereKey($event->cancellation->service_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $dynamicCancellation = app(DurableFulfillmentService::class)
                    ->requestCancellation($service);
                $externalTerminationQueued = false;

                if ($dynamicCancellation) {
                    $service->refresh();
                } else {
                    if (in_array($service->status, [
                        Service::STATUS_ACTIVE,
                        Service::STATUS_SUSPENDED,
                    ], true)) {
                        $externalTerminationQueued = true;
                    }
                    FulfillmentStatusTransitionService::run(
                        $service,
                        function () use ($service): void {
                            $service->status =
                                Service::STATUS_CANCELLED;
                            $service->save();
                        }
                    );
                    if ($externalTerminationQueued) {
                        app(ServiceJobDispatchService::class)
                            ->requestTerminate($service);
                    }
                }

                if (
                    !$dynamicCancellation
                    && !$externalTerminationQueued
                    && $service->product->stock !== null
                ) {
                    app(ProductStockService::class)->release($service);
                }
            }, 5);
        }
        // If the cancellation is scheduled, we don't need to do anything as it will be handled by the cron job
    }
}
