<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class RenewServiceService
{
    /**
     * Handle the service renewal.
     *
     * @return void
     */
    public function handle(Service $service, ?Invoice $invoice = null)
    {
        $fulfillment = app(DurableFulfillmentService::class);
        $reservationBacked = $fulfillment->isReservationBacked($service);
        $isRenewal = in_array($service->status, [
            Service::STATUS_ACTIVE,
            Service::STATUS_SUSPENDED,
        ], true);

        if (
            $reservationBacked
            && $isRenewal
            && DB::transactionLevel() === 0
        ) {
            DB::transaction(function () use ($service, $invoice): void {
                // Preserve invoice -> service before the renewal validator
                // acquires upgrade and reservation locks.
                $lockedInvoice = $invoice === null
                    ? null
                    : Invoice::query()
                        ->whereKey($invoice->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                $lockedService = Service::query()
                    ->with(['product.server', 'plan'])
                    ->whereKey($service->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! in_array($lockedService->status, [
                    Service::STATUS_ACTIVE,
                    Service::STATUS_SUSPENDED,
                ], true)) {
                    throw new \RuntimeException(
                        'The capacity-backed service changed before renewal acquired its lock.'
                    );
                }

                $this->handle($lockedService, $lockedInvoice);
            }, 5);

            return;
        }

        if ($reservationBacked && $isRenewal) {
            $service = $fulfillment->assertRenewalMutationAllowed(
                $service,
                $invoice
            );
        }

        if ($service->status == Service::STATUS_PENDING) {
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
                UnsuspendJob::dispatch($service)->afterCommit();
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
