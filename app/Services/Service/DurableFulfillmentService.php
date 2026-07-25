<?php

namespace App\Services\Service;

use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional bridge between Paymenter-owned entry points and the dynamic
 * extension's durable reservation state machine.
 */
class DurableFulfillmentService
{
    private const RESERVATION_SERVICE = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';

    public function isReservationBacked(Service $service): bool
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

    /**
     * Commit a paid checkout service through the durable reservation state
     * machine. A reservation row is authoritative even when the extension
     * code or the product's current configuration has disappeared.
     */
    public function commitPaidService(Service $service, Invoice $invoice): bool
    {
        $reservationBacked = $this->isReservationBacked($service);
        $currentlyDynamic = $service->product?->usesDynamicResources() ?? false;
        if (! $reservationBacked && ! $currentlyDynamic) {
            return false;
        }

        $reservationService = $this->reservationService();
        if ($reservationService === null) {
            throw new \RuntimeException(
                'The durable fulfillment extension is unavailable for a reservation-backed service.'
            );
        }

        $committed = $reservationService->commitPaidService($service, $invoice);
        if (! $committed) {
            throw new \RuntimeException(
                'The durable fulfillment extension did not commit the reservation-backed service.'
            );
        }

        return true;
    }

    public function preflightPaidService(
        Service $service,
        Invoice $invoice
    ): ?string {
        $reservationBacked = $this->isReservationBacked($service);
        $currentlyDynamic = $service->product?->usesDynamicResources() ?? false;
        if (! $reservationBacked && ! $currentlyDynamic) {
            return null;
        }

        $reservationService = $this->reservationService();
        if (
            $reservationService === null
            || ! method_exists($reservationService, 'preflightPaidService')
        ) {
            return "Capacity-backed service {$service->id} cannot be paid because its durable fulfillment runtime is unavailable.";
        }

        $failure = $reservationService->preflightPaidService(
            $service,
            $invoice
        );
        if ($failure === null || is_string($failure)) {
            return $failure;
        }

        return "Capacity-backed service {$service->id} returned an invalid payment preflight result.";
    }

    public function assertRuntimeAvailable(Service $service): void
    {
        if (
            $this->isReservationBacked($service)
            && $this->reservationService() === null
        ) {
            throw new \RuntimeException(
                'The durable fulfillment extension is unavailable for a reservation-backed service.'
            );
        }
    }

    /**
     * A stale termination job may no-op only after the durable local record
     * proves that external absence was already reconciled and product stock was
     * released. Service status alone is never sufficient: static services are
     * marked cancelled before their external delete runs, and an unsafe direct
     * status mutation must not bypass deletion.
     */
    public function cancellationIsDurablyComplete(Service $service): bool
    {
        if (
            ! Schema::hasTable('ptero_resource_reservations')
            || ! Schema::hasColumn(
                'ptero_resource_reservations',
                'purpose'
            )
            || ! Schema::hasColumn(
                'ptero_resource_reservations',
                'product_stock_released_at'
            )
            || ! Schema::hasColumn('services', 'product_stock_released_at')
        ) {
            return false;
        }

        $serviceState = DB::table('services')
            ->where('id', $service->id)
            ->first(['status', 'product_stock_released_at']);
        if (
            $serviceState === null
            || $serviceState->status !== Service::STATUS_CANCELLED
            || $serviceState->product_stock_released_at === null
        ) {
            return false;
        }

        $reservation = DB::table('ptero_resource_reservations')
            ->where('service_id', $service->id)
            ->where('purpose', 'checkout')
            ->orderByDesc('id')
            ->first([
                'status',
                'cancellation_requested_at',
                'product_stock_released_at',
            ]);
        if (
            $reservation === null
            || $reservation->product_stock_released_at === null
        ) {
            return false;
        }

        if (in_array($reservation->status, ['cancelled', 'expired'], true)) {
            return true;
        }

        return $reservation->status === 'confirmed'
            && $reservation->cancellation_requested_at !== null;
    }

    /**
     * Complete the durable cancellation after the provisioner has proved the
     * external server is absent.
     */
    public function completeCancellation(Service $service): bool
    {
        if (! $this->isReservationBacked($service)) {
            return false;
        }

        $reservationService = $this->reservationService();
        if ($reservationService === null) {
            throw new \RuntimeException(
                'The durable fulfillment extension became unavailable before cancellation completed.'
            );
        }
        if (! $reservationService->completeServiceCancellation($service)) {
            throw new \RuntimeException(
                'The reservation-backed cancellation did not reach its durable terminal state.'
            );
        }

        return true;
    }

    public function reservedServerExtensionId(Service|int $service): ?int
    {
        if (
            ! Schema::hasTable('ptero_resource_reservations')
            || ! Schema::hasColumn(
                'ptero_resource_reservations',
                'server_extension_id'
            )
        ) {
            return null;
        }

        $serviceId = $service instanceof Service ? (int) $service->id : $service;
        $query = DB::table('ptero_resource_reservations')
            ->where('service_id', $serviceId);
        if (Schema::hasColumn('ptero_resource_reservations', 'purpose')) {
            $query->where('purpose', 'checkout');
        }
        $serverId = $query->orderByDesc('id')->value('server_extension_id');

        return $serverId !== null ? (int) $serverId : null;
    }

    public function assertServerHostMutable(int $serverId): void
    {
        if (
            ! Schema::hasTable('ptero_resource_reservations')
            || ! Schema::hasColumn(
                'ptero_resource_reservations',
                'server_extension_id'
            )
        ) {
            return;
        }

        $active = DB::table('ptero_resource_reservations as reservation')
            ->leftJoin('services as service', 'service.id', '=', 'reservation.service_id')
            ->where('reservation.server_extension_id', $serverId)
            ->whereIn('reservation.status', [
                'pending',
                'paid_committed',
                'confirmed',
            ])
            ->where(function ($query): void {
                $query->whereNull('service.status')
                    ->orWhere('service.status', '!=', Service::STATUS_CANCELLED);
            })
            ->exists();
        if ($active) {
            throw new \RuntimeException(
                'This Pterodactyl panel host is pinned by active capacity commitments. Drain or migrate those services before changing or removing it.'
            );
        }
    }

    /**
     * Request cancellation without deleting the durable local fulfillment
     * record. Returns false only when the service is not reservation-backed.
     */
    public function requestCancellation(
        Service $service,
        bool $sendNotification = true
    ): bool {
        if (! $this->isReservationBacked($service)) {
            return false;
        }
        $this->assertRuntimeAvailable($service);

        // Preserve the global invoice -> service -> upgrade lock order. An
        // unpaid upgrade invoice owns its cancellation transaction and will
        // release the upgrade reservation before we lock the service.
        ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->whereIn('status', [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ])
            ->whereNotNull('invoice_id')
            ->with('invoice')
            ->get()
            ->sortBy('invoice_id')
            ->each(function (ServiceUpgrade $upgrade): void {
                if ($upgrade->invoice?->status === \App\Models\Invoice::STATUS_PENDING) {
                    app(CancelInvoiceService::class)->handle(
                        $upgrade->invoice,
                        'Service cancellation superseded this unpaid upgrade.'
                    );
                }
            });

        return DB::transaction(function () use ($service, $sendNotification): bool {
            $lockedService = Service::query()
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reservationService = $this->reservationService();
            if ($reservationService === null) {
                throw new \RuntimeException(
                    'The durable fulfillment extension became unavailable.'
                );
            }

            $activeUpgrades = ServiceUpgrade::query()
                ->where('service_id', $lockedService->id)
                ->whereIn('status', ServiceUpgrade::activeStatuses())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($activeUpgrades as $upgrade) {
                if (in_array($upgrade->status, [
                    ServiceUpgrade::STATUS_PAID_COMMITTED,
                    ServiceUpgrade::STATUS_PROVISIONING,
                    ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                    ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                ], true)) {
                    throw new \RuntimeException(
                        'Service cancellation is blocked until its paid resource upgrade is completed or reconciled by an operator.'
                    );
                }

                app(ServiceUpgradeService::class)->cancel(
                    $upgrade,
                    'Service cancellation superseded this unpaid upgrade.'
                );
            }

            $reservationService->requestServiceCancellation($lockedService);
            $lockedService->refresh();

            if ($lockedService->status === Service::STATUS_CANCELLATION_PENDING) {
                DB::afterCommit(
                    fn () => TerminateJob::dispatch(
                        $lockedService,
                        $sendNotification
                    )
                );
            }

            return true;
        }, 5);
    }

    /**
     * Protected so the fail-closed missing-runtime contract can be exercised
     * without physically deleting extension files during a test.
     */
    protected function reservationService(): ?object
    {
        if (! class_exists(self::RESERVATION_SERVICE)) {
            return null;
        }

        return app(self::RESERVATION_SERVICE);
    }
}
