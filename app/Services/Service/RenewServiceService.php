<?php

namespace App\Services\Service;

use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonInterface;
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
                if (!in_array($lockedService->status, [
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

        $periodStart = $this->periodStart($service, $isRenewal);
        $this->advancePricingPeriod(
            $service,
            $invoice,
            $isRenewal,
            $periodStart
        );

        if ($service->status == Service::STATUS_PENDING) {
            $currentlyDynamic = $service->product?->usesDynamicResources() ?? false;

            if ($reservationBacked || $currentlyDynamic) {
                if ($invoice === null) {
                    throw new \RuntimeException(
                        'A reservation-backed pending service cannot be provisioned without its paid invoice.'
                    );
                }

                // Dynamic checkout commitment reloads the locked service by
                // ID and returns early. Persist the payment-backed ledger
                // first so that reload cannot discard verified evidence.
                ServiceBillingAnchorMutationCoordinator::run(
                    $service,
                    fn () => $service->save()
                );
                $fulfillment->commitPaidService($service, $invoice);
                app(ServiceJobDispatchService::class)
                    ->requestCreate($service);

                return;
            }
        }

        $dispatchAction = null;
        if ($service->product->server) {
            if ($service->status == Service::STATUS_SUSPENDED) {
                $dispatchAction =
                    ServiceJobDispatchService::ACTION_UNSUSPEND;
            } elseif ($service->status == Service::STATUS_PENDING) {
                $dispatchAction =
                    ServiceJobDispatchService::ACTION_CREATE;
            }
        }

        $service->expires_at =
            $service->calculateNextDueDate($periodStart);
        $service->status = Service::STATUS_ACTIVE;
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->save()
        );

        if ($dispatchAction !== null) {
            app(ServiceJobDispatchService::class)->request(
                $service,
                $dispatchAction
            );
        }
    }

    private function advancePricingPeriod(
        Service $service,
        ?Invoice $invoice,
        bool $isRenewal,
        CarbonInterface $periodStart
    ): void {
        if (!$isRenewal) {
            // Checkout already froze recurring-only value separately from its
            // setup fee. Payment starts that ledger without re-reading the
            // setup-inclusive initial invoice line.
            $base = $service->period_base_price
                ?? $service->current_period_price
                ?? $service->price
                ?? 0;
        } elseif ($invoice === null) {
            $base = (float) ($service->price ?? 0)
                * max(1, (int) $service->quantity);
        } else {
            $lines = $invoice->items()
                ->where('reference_type', Service::class)
                ->where('reference_id', $service->id)
                ->orderBy('id')
                ->get();
            $line = $lines->first();
            if (
                $lines->count() !== 1
                || (int) ($line?->quantity ?? 0)
                    !== (int) $service->quantity
            ) {
                throw new \RuntimeException(
                    'The renewal invoice does not contain one exact recurring service line.'
                );
            }
            $base = (float) $line->price
                * (int) $line->quantity;
        }

        $base = number_format(max(0, (float) $base), 2, '.', '');
        $service->period_base_price = $base;
        $service->current_period_price = $base;
        $service->pricing_ledger_started_at = $periodStart;
        $service->pricing_ledger_verified_at = now();
        $service->billing_cycles_completed = $isRenewal
            ? max(1, (int) $service->billing_cycles_completed) + 1
            : max(1, (int) $service->billing_cycles_completed);
    }

    private function periodStart(
        Service $service,
        bool $isRenewal
    ): CarbonInterface {
        if (
            $isRenewal
            && $service->status === Service::STATUS_ACTIVE
            && $service->expires_at !== null
            && $service->expires_at
                ->copy()
                ->startOfDay()
                ->greaterThanOrEqualTo(now()->startOfDay())
        ) {
            return $service->expires_at->copy()->startOfDay();
        }

        return now()->startOfDay();
    }
}
