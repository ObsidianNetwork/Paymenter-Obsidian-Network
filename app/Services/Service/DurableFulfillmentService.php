<?php

namespace App\Services\Service;

use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Support\StrictDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Optional bridge between Paymenter-owned entry points and the dynamic
 * extension's durable reservation state machine.
 */
class DurableFulfillmentService
{
    private const RESERVATION_SERVICE = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';

    private const RENEWAL_BLOCKING_UPGRADE_STATUSES = [
        ServiceUpgrade::STATUS_PAID_COMMITTED,
        ServiceUpgrade::STATUS_PROVISIONING,
        ServiceUpgrade::STATUS_RETRYABLE_FAILED,
        ServiceUpgrade::STATUS_NEEDS_ATTENTION,
    ];

    private const CONFIRMED_COMMITMENT_COLUMNS = [
        'purpose',
        'service_guard_id',
        'invoice_id',
        'user_id',
        'product_id',
        'plan_id',
        'quantity',
        'currency_code',
        'configuration_fingerprint',
        'configuration_payload',
        'server_extension_id',
        'panel_identity',
        'node_id',
        'location_id',
        'memory',
        'cpu',
        'disk',
        'paid_committed_at',
        'provisioning_lease_id',
        'consumed_at',
        'cancellation_requested_at',
        'external_server_id',
        'external_user_id',
        'external_server_uuid',
        'external_server_identifier',
        'product_stock_released_at',
    ];

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

        if (
            $reservationBacked
            && in_array($service->status, [
                Service::STATUS_ACTIVE,
                Service::STATUS_SUSPENDED,
            ], true)
        ) {
            return $this->confirmedRenewalValidation(
                $service,
                $invoice,
                Invoice::STATUS_PENDING
            )['failure'];
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

    /**
     * Repeat the renewal proof at the mutation boundary while the paid invoice
     * transaction remains open. The returned service is the locked row that
     * must be mutated by the caller.
     */
    public function assertRenewalMutationAllowed(
        Service $service,
        ?Invoice $invoice
    ): Service {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Reservation-backed renewal mutation requires a database transaction.'
            );
        }

        $result = $this->confirmedRenewalValidation(
            $service,
            $invoice,
            $invoice === null ? null : Invoice::STATUS_PAID
        );
        if ($result['failure'] !== null) {
            throw new \RuntimeException($result['failure']);
        }

        return $result['service'];
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

    /**
     * A renewal is the only paid Service invoice that may bypass the original
     * checkout reservation's invoice ownership. Classify it from immutable
     * local evidence, then hold invoice -> service -> upgrade -> reservation
     * -> item locks through the caller's paid transition.
     *
     * A null invoice is reserved for the cron's zero-price renewal path.
     *
     * @return array{service: Service, failure: string|null}
     */
    private function confirmedRenewalValidation(
        Service $service,
        ?Invoice $invoice,
        ?string $expectedInvoiceStatus
    ): array {
        return DB::transaction(function () use (
            $service,
            $invoice,
            $expectedInvoiceStatus
        ): array {
            $lockedInvoice = $invoice === null
                ? null
                : Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            $lockedService = Service::query()
                ->with(['product', 'plan'])
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedService->status, [
                Service::STATUS_ACTIVE,
                Service::STATUS_SUSPENDED,
            ], true)) {
                return $this->renewalFailure(
                    $lockedService,
                    "Capacity-backed service {$lockedService->id} is not in a renewable lifecycle state."
                );
            }

            $blockingUpgrades = ServiceUpgrade::query()
                ->where('service_id', $lockedService->id)
                ->whereIn(
                    'status',
                    self::RENEWAL_BLOCKING_UPGRADE_STATUSES
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $blockingUpgrade = $blockingUpgrades->first();
            if ($blockingUpgrade !== null) {
                return $this->renewalFailure(
                    $lockedService,
                    "Capacity-backed service {$lockedService->id} cannot renew while upgrade {$blockingUpgrade->id} is {$blockingUpgrade->status}."
                );
            }

            foreach (self::CONFIRMED_COMMITMENT_COLUMNS as $column) {
                if (! Schema::hasColumn(
                    'ptero_resource_reservations',
                    $column
                )) {
                    return $this->renewalFailure(
                        $lockedService,
                        "Capacity-backed service {$lockedService->id} cannot renew because its durable fulfillment schema is incomplete."
                    );
                }
            }

            $commitments = DB::table('ptero_resource_reservations')
                ->where('purpose', 'checkout')
                ->where('service_id', $lockedService->id)
                ->whereIn('status', [
                    'pending',
                    'paid_committed',
                    'confirmed',
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if (
                $commitments->count() !== 1
                || $commitments->first()?->status !== 'confirmed'
            ) {
                return $this->renewalFailure(
                    $lockedService,
                    "Capacity-backed service {$lockedService->id} does not have exactly one confirmed checkout commitment."
                );
            }

            $commitment = $commitments->first();
            $commitmentFailure = $this->confirmedCommitmentFailure(
                $lockedService,
                $commitment
            );
            if ($commitmentFailure !== null) {
                return $this->renewalFailure(
                    $lockedService,
                    $commitmentFailure
                );
            }

            if ($lockedInvoice === null) {
                $price = StrictDecimal::parseNonNegative(
                    $lockedService->price
                );
                if (
                    $expectedInvoiceStatus !== null
                    || $price === null
                    || number_format($price, 2, '.', '') !== '0.00'
                ) {
                    return $this->renewalFailure(
                        $lockedService,
                        "Capacity-backed service {$lockedService->id} requires its exact renewal invoice."
                    );
                }

                return $this->renewalFailure($lockedService, null);
            }

            if (
                $expectedInvoiceStatus === null
                || $lockedInvoice->status !== $expectedInvoiceStatus
                || (int) $lockedInvoice->user_id
                    !== (int) $lockedService->user_id
                || strtoupper((string) $lockedInvoice->currency_code)
                    !== strtoupper((string) $lockedService->currency_code)
                || $lockedService->expires_at === null
                || $lockedInvoice->due_at === null
                || $lockedInvoice->due_at->toDateString()
                    !== $lockedService->expires_at->toDateString()
                || (int) $lockedService->quantity !== 1
            ) {
                return $this->renewalFailure(
                    $lockedService,
                    "Invoice {$lockedInvoice->id} is not the exact renewal obligation for capacity-backed service {$lockedService->id}."
                );
            }

            $items = $lockedInvoice->items()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $item = $items->first();
            $itemPrice = StrictDecimal::parseNonNegative($item?->price);
            $servicePrice = StrictDecimal::parseNonNegative(
                $lockedService->price
            );
            if (
                $items->count() !== 1
                || $item === null
                || $item->reference_type !== Service::class
                || (int) $item->reference_id
                    !== (int) $lockedService->id
                || (int) $item->quantity
                    !== (int) $lockedService->quantity
                || $itemPrice === null
                || $servicePrice === null
                || number_format($itemPrice, 2, '.', '')
                    !== number_format($servicePrice, 2, '.', '')
            ) {
                return $this->renewalFailure(
                    $lockedService,
                    "Invoice {$lockedInvoice->id} is not the exact renewal obligation for capacity-backed service {$lockedService->id}."
                );
            }

            return $this->renewalFailure($lockedService, null);
        }, 5);
    }

    private function confirmedCommitmentFailure(
        Service $service,
        object $commitment
    ): ?string {
        if (
            (int) $commitment->service_guard_id !== (int) $service->id
            || (int) $commitment->user_id !== (int) $service->user_id
            || (int) $commitment->quantity !== 1
            || strtoupper((string) $commitment->currency_code)
                !== strtoupper((string) $service->currency_code)
            || $commitment->invoice_id === null
            || $commitment->paid_committed_at === null
            || $commitment->consumed_at === null
            || $commitment->provisioning_lease_id !== null
            || $commitment->cancellation_requested_at !== null
            || $commitment->product_stock_released_at !== null
            || (int) $commitment->external_server_id <= 0
            || (int) $commitment->external_user_id <= 0
            || ! is_string($commitment->external_server_uuid)
            || ! Str::isUuid($commitment->external_server_uuid)
            || ! is_string($commitment->external_server_identifier)
            || trim($commitment->external_server_identifier) === ''
            || (int) $commitment->server_extension_id <= 0
            || ! is_string($commitment->panel_identity)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $commitment->panel_identity
            ) !== 1
        ) {
            return "Capacity-backed service {$service->id} has an incomplete confirmed checkout commitment.";
        }

        try {
            $payload = is_array($commitment->configuration_payload)
                ? $commitment->configuration_payload
                : json_decode(
                    (string) $commitment->configuration_payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            if (! is_array($payload)) {
                throw new \JsonException(
                    'The reservation payload is not an object.'
                );
            }
            $fingerprint = hash(
                'sha256',
                json_encode(
                    $this->canonicalizeReservationPayload($payload),
                    JSON_THROW_ON_ERROR
                        | JSON_PRESERVE_ZERO_FRACTION
                        | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (\JsonException) {
            return "Capacity-backed service {$service->id} has an unreadable confirmed checkout commitment.";
        }

        $resources = (array) ($payload['resources'] ?? []);
        if (
            ! is_string($commitment->configuration_fingerprint)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $commitment->configuration_fingerprint
            ) !== 1
            || ! hash_equals(
                $commitment->configuration_fingerprint,
                $fingerprint
            )
            || (int) ($payload['customer_id'] ?? 0)
                !== (int) $commitment->user_id
            || (int) ($payload['product_id'] ?? 0)
                !== (int) $commitment->product_id
            || (int) ($payload['plan_id'] ?? 0)
                !== (int) $commitment->plan_id
            || (int) ($payload['quantity'] ?? 0)
                !== (int) $commitment->quantity
            || strtoupper((string) ($payload['currency_code'] ?? ''))
                !== strtoupper((string) $commitment->currency_code)
            || (int) ($payload['server_extension_id'] ?? 0)
                !== (int) $commitment->server_extension_id
            || ! hash_equals(
                (string) $commitment->panel_identity,
                (string) ($payload['panel_identity'] ?? '')
            )
            || (int) ($payload['node_id'] ?? 0)
                !== (int) $commitment->node_id
            || (int) ($payload['location_id'] ?? 0)
                !== (int) $commitment->location_id
            || (int) ($resources['memory'] ?? 0)
                !== (int) $commitment->memory
            || (int) ($resources['cpu'] ?? 0)
                !== (int) $commitment->cpu
            || (int) ($resources['disk'] ?? 0)
                !== (int) $commitment->disk
        ) {
            return "Capacity-backed service {$service->id} has a corrupted confirmed checkout commitment.";
        }

        return null;
    }

    /**
     * Match the extension's canonical JSON rules without requiring its runtime
     * for a routine renewal.
     *
     * @return array<string|int, mixed>
     */
    private function canonicalizeReservationPayload(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeReservationPayload($item);
            } elseif (
                is_float($item)
                && is_finite($item)
                && floor($item) === $item
                && $item >= PHP_INT_MIN
                && $item <= PHP_INT_MAX
            ) {
                $value[$key] = (int) $item;
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array{service: Service, failure: string|null}
     */
    private function renewalFailure(
        Service $service,
        ?string $failure
    ): array {
        return [
            'service' => $service,
            'failure' => $failure,
        ];
    }
}
