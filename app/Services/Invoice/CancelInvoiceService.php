<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancel an invoice and every still-unpaid fulfillment obligation atomically.
 */
class CancelInvoiceService
{
    /** @var array<int, int> */
    private static array $coordinatedInvoiceIds = [];

    public static function isCoordinating(Invoice $invoice): bool
    {
        return isset(self::$coordinatedInvoiceIds[(int) $invoice->getKey()]);
    }

    public function assertCanDelete(Invoice $invoice): void
    {
        if (app(CapacityInvoicePaymentService::class)->isCapacityBacked($invoice)) {
            throw new \RuntimeException(
                'Capacity-backed invoices are durable fulfillment records and cannot be deleted. Cancel the invoice through the fulfillment coordinator instead.'
            );
        }
    }

    public function handle(
        Invoice|int $invoice,
        string $reason = 'Invoice cancelled by an operator.'
    ): Invoice {
        $invoiceId = $invoice instanceof Invoice ? (int) $invoice->id : $invoice;

        return $this->coordinated($invoiceId, function () use ($invoiceId, $reason): Invoice {
            return DB::transaction(function () use ($invoiceId, $reason): Invoice {
                $invoice = Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($invoice->status === Invoice::STATUS_CANCELLED) {
                    return $invoice;
                }
                if ($invoice->status !== Invoice::STATUS_PENDING) {
                    throw new \RuntimeException(
                        "A {$invoice->status} invoice cannot be cancelled."
                    );
                }

                $payments = app(CapacityInvoicePaymentService::class);
                if (! $payments->isCapacityBacked($invoice)) {
                    $invoice->status = Invoice::STATUS_CANCELLED;
                    $invoice->save();

                    return $invoice->fresh();
                }
                if ($payments->hasInFlightOrSucceededPayment($invoice)) {
                    throw new \RuntimeException(
                        'This capacity-backed invoice has a partial, processing, or succeeded payment and requires refund or credit reconciliation before cancellation.'
                    );
                }

                // Discover identifiers without taking subordinate locks, then
                // acquire every capacity-invoice row in the global order:
                // invoice -> services -> upgrades -> reservations -> items.
                $itemSnapshot = $invoice->items()
                    ->orderBy('id')
                    ->get();
                $reservationSnapshot = DB::table(
                    'ptero_resource_reservations'
                )
                    ->where('invoice_id', $invoice->id)
                    ->orderBy('id')
                    ->get();
                $upgradeIds = $itemSnapshot
                    ->where('reference_type', ServiceUpgrade::class)
                    ->pluck('reference_id')
                    ->merge(
                        Schema::hasTable('service_upgrades')
                            ? ServiceUpgrade::query()
                                ->where('invoice_id', $invoice->id)
                                ->pluck('id')
                            : collect()
                    )
                    ->merge(
                        $reservationSnapshot->pluck(
                            'service_upgrade_id'
                        )
                    )
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values();
                $upgradeServiceIds = $upgradeIds->isEmpty()
                    ? collect()
                    : ServiceUpgrade::query()
                        ->whereKey($upgradeIds->all())
                        ->pluck('service_id');
                $serviceIds = $itemSnapshot
                    ->where('reference_type', Service::class)
                    ->pluck('reference_id')
                    ->merge(
                        $reservationSnapshot
                            ->filter(
                                fn ($reservation): bool => (
                                    $reservation->purpose ?? 'checkout'
                                ) === 'checkout'
                            )
                            ->pluck('service_id')
                    )
                    ->merge($upgradeServiceIds)
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values();

                $services = Service::query()
                    ->whereKey($serviceIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($services->count() !== $serviceIds->count()) {
                    $missing = $serviceIds->diff($services->modelKeys())
                        ->first();
                    throw new \RuntimeException(
                        "Capacity invoice references missing service {$missing}."
                    );
                }

                $upgrades = ServiceUpgrade::query()
                    ->whereKey($upgradeIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($upgrades->count() !== $upgradeIds->count()) {
                    $missing = $upgradeIds->diff($upgrades->modelKeys())
                        ->first();
                    throw new \RuntimeException(
                        "Capacity invoice references missing service upgrade {$missing}."
                    );
                }

                $reservations = DB::table(
                    'ptero_resource_reservations'
                )
                    ->where('invoice_id', $invoice->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $items = $invoice->items()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if (
                    $items->pluck('id')->all()
                        !== $itemSnapshot->pluck('id')->all()
                    || $reservations->pluck('id')->all()
                        !== $reservationSnapshot->pluck('id')->all()
                ) {
                    throw new \RuntimeException(
                        'The capacity invoice obligations changed while cancellation was acquiring its locks.'
                    );
                }

                $unsafe = $reservations->first(
                    fn ($reservation): bool => ! in_array(
                        $reservation->status,
                        ['pending', 'expired', 'cancelled'],
                        true
                    )
                );
                if ($unsafe !== null) {
                    throw new \RuntimeException(
                        "Reservation {$unsafe->id} is {$unsafe->status}; paid or provisioned capacity requires operator reconciliation."
                    );
                }

                foreach ($upgrades as $upgrade) {
                    if (in_array($upgrade->status, [
                        ServiceUpgrade::STATUS_COMPLETED,
                        ServiceUpgrade::STATUS_CANCELLED,
                    ], true)) {
                        continue;
                    }

                    app(ServiceUpgradeService::class)->cancel(
                        $upgrade,
                        $reason
                    );
                }

                foreach ($serviceIds as $serviceId) {
                    $service = $services->firstWhere('id', $serviceId);

                    $dynamic = app(DurableFulfillmentService::class)
                        ->requestCancellation($service, false);
                    if ($dynamic) {
                        $service->refresh();
                        if ($service->status !== Service::STATUS_CANCELLED) {
                            throw new \RuntimeException(
                                "Unpaid capacity service {$service->id} did not reach its cancelled terminal state."
                            );
                        }
                    } else {
                        if ($service->status !== Service::STATUS_CANCELLED) {
                            if ($service->status !== Service::STATUS_PENDING) {
                                throw new \RuntimeException(
                                    "Unpaid invoice service {$service->id} is unexpectedly {$service->status}."
                                );
                            }
                            FulfillmentStatusTransitionService::run(
                                $service,
                                function () use ($service): void {
                                    $service->status = Service::STATUS_CANCELLED;
                                    $service->save();
                                }
                            );
                        }
                        app(ProductStockService::class)->release($service);
                    }
                }

                $invoice->status = Invoice::STATUS_CANCELLED;
                $invoice->save();

                return $invoice->fresh();
            }, 5);
        });
    }

    /**
     * Used only by a fulfillment state machine that already cancelled every
     * linked obligation under the same transaction.
     */
    public function markCancelledAfterFulfillment(Invoice $invoice): Invoice
    {
        return $this->coordinated(
            (int) $invoice->id,
            function () use ($invoice): Invoice {
                if ($invoice->status === Invoice::STATUS_PAID) {
                    throw new \RuntimeException(
                        'A paid invoice cannot be cancelled after releasing fulfillment.'
                    );
                }
                if ($invoice->status === Invoice::STATUS_PENDING) {
                    $invoice->status = Invoice::STATUS_CANCELLED;
                    $invoice->save();
                }

                return $invoice->fresh();
            }
        );
    }

    private function coordinated(int $invoiceId, callable $callback): mixed
    {
        self::$coordinatedInvoiceIds[$invoiceId]
            = (self::$coordinatedInvoiceIds[$invoiceId] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            self::$coordinatedInvoiceIds[$invoiceId]--;
            if (self::$coordinatedInvoiceIds[$invoiceId] === 0) {
                unset(self::$coordinatedInvoiceIds[$invoiceId]);
            }
        }
    }
}
