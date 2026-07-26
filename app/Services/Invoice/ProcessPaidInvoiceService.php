<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            [
                'items' => $items,
                'services' => $services,
                'upgrades' => $upgrades,
            ] = $this->lockFulfillmentObligations($invoice);

            $items->each(function ($item) use (
                $invoice,
                $services,
                $upgrades
            ) {
                if ($item->reference_type == Service::class) {
                    $service = $services->firstWhere(
                        'id',
                        (int) $item->reference_id
                    );
                    if (!$service) {
                        throw new \RuntimeException(
                            "Paid invoice item {$item->id} references missing service {$item->reference_id}."
                        );
                    }
                    (new RenewServiceService)->handle($service, $invoice);
                } elseif ($item->reference_type == ServiceUpgrade::class) {
                    $serviceUpgrade = $upgrades->firstWhere(
                        'id',
                        (int) $item->reference_id
                    );
                    if (!$serviceUpgrade || !($serviceUpgrade instanceof ServiceUpgrade)) {
                        throw new \RuntimeException(
                            "Paid invoice item {$item->id} references missing service upgrade {$item->reference_id}."
                        );
                    }

                    $upgradeReservationClass =
                        'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
                    $coordinatorAvailable = class_exists(
                        $upgradeReservationClass
                    ) && method_exists(
                        $upgradeReservationClass,
                        'commitPaidUpgrade'
                    );
                    $capacityBacked = app(
                        CapacityUpgradeReservationIdentity::class
                    )->requiresCoordinator($serviceUpgrade);

                    if ($capacityBacked && $coordinatorAvailable) {
                        $handled = app($upgradeReservationClass)
                            ->commitPaidUpgrade(
                                $serviceUpgrade,
                                $invoice
                            );
                        if ($handled !== true) {
                            throw new \RuntimeException(
                                "Capacity-backed service upgrade {$serviceUpgrade->id} was not committed by its reservation coordinator."
                            );
                        }
                    } elseif ($capacityBacked) {
                        throw new \RuntimeException(
                            "Capacity-backed service upgrade {$serviceUpgrade->id} cannot be paid because its reservation coordinator is unavailable."
                        );
                    } elseif (in_array(
                        $serviceUpgrade->status,
                        [
                            ServiceUpgrade::STATUS_PENDING,
                            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                        ],
                        true
                    )) {
                        // Preserve legacy non-dynamic upgrade behavior.
                        app(ServiceUpgradeService::class)
                            ->handle($serviceUpgrade);
                    }
                } elseif ($item->reference_type == Credit::class) {
                    $user = $invoice->user;
                    $credit = $user->credits()->where('currency_code', $invoice->currency_code)
                        ->lockForUpdate()
                        ->first();

                    if ($credit) {
                        $credit->amount += $item->price;
                        $credit->save();
                    } else {
                        $user->credits()->create([
                            'currency_code' => $invoice->currency_code,
                            'amount' => $item->price,
                        ]);
                    }
                }
            });
        }, 5);
    }

    /**
     * Acquire every fulfillment row in the one global order shared by payment
     * preflight, paid processing, and cancellation:
     *
     * invoice -> services -> upgrades -> reservations -> invoice items.
     *
     * The invoice itself must already be locked by the caller.
     *
     * @return array{
     *     items: \Illuminate\Database\Eloquent\Collection<int, mixed>,
     *     services: \Illuminate\Database\Eloquent\Collection<int, Service>,
     *     upgrades: \Illuminate\Database\Eloquent\Collection<int, ServiceUpgrade>
     * }
     */
    public function lockFulfillmentObligations(Invoice $invoice): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Fulfillment obligations require a locked invoice transaction.'
            );
        }

        $itemSnapshot = $invoice->items()
            ->orderBy('id')
            ->get();
        $upgradeIds = $itemSnapshot
            ->where('reference_type', ServiceUpgrade::class)
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $serviceIds = $itemSnapshot
            ->where('reference_type', Service::class)
            ->pluck('reference_id')
            ->merge(
                ServiceUpgrade::query()
                    ->whereKey($upgradeIds->all())
                    ->pluck('service_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $services = Service::query()
            ->whereKey($serviceIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $upgrades = ServiceUpgrade::query()
            ->whereKey($upgradeIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $unlockedUpgradeServiceId = $upgrades
            ->pluck('service_id')
            ->map(fn ($id) => (int) $id)
            ->first(
                fn (int $id): bool => ! $services->contains(
                    fn (Service $service): bool =>
                        (int) $service->id === $id
                )
            );
        if ($unlockedUpgradeServiceId !== null) {
            throw new \RuntimeException(
                'An invoice upgrade changed services while payment was acquiring its locks.'
            );
        }
        if (
            Schema::hasTable('ptero_resource_reservations')
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'invoice_id'
            )
        ) {
            DB::table('ptero_resource_reservations')
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
        $items = $invoice->items()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if (
            $items->map(fn ($item): array => [
                (int) $item->id,
                (string) $item->reference_type,
                $item->reference_id === null
                    ? null
                    : (int) $item->reference_id,
            ])->all()
                !== $itemSnapshot->map(fn ($item): array => [
                    (int) $item->id,
                    (string) $item->reference_type,
                    $item->reference_id === null
                        ? null
                        : (int) $item->reference_id,
                ])->all()
        ) {
            throw new \RuntimeException(
                'The invoice obligations changed while payment was acquiring its locks.'
            );
        }

        return compact('items', 'services', 'upgrades');
    }
}
