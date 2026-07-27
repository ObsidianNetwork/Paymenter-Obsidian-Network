<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CreditInvoicePaymentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Support\StrictDecimal;
use App\Support\StrictInteger;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    /**
     * Backward-compatible entry point for legacy callers.
     */
    public function handle(ServiceUpgrade $serviceUpgrade): void
    {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn () => $this->handle($serviceUpgrade)
            );

            return;
        }

        $this->markPaidCommitted($serviceUpgrade);
    }

    public function markPaidCommitted(ServiceUpgrade $serviceUpgrade): void
    {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn () => $this->markPaidCommitted($serviceUpgrade)
            );

            return;
        }

        $upgradeId = (int) $serviceUpgrade->id;
        $serviceId = (int) ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->value('service_id');
        $invoiceId = ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->value('invoice_id');

        $sourceMismatch = DB::transaction(function () use (
            $upgradeId,
            $serviceId,
            $invoiceId
        ): bool {
            $invoice = $invoiceId === null
                ? null
                : Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
            $service = $this->lockedService($serviceId);
            $upgrade = $this->lockedUpgrade($upgradeId);
            $upgrade->setRelation('service', $service);
            $upgrade->setRelation('invoice', $invoice);
            if (
                (int) ($upgrade->invoice_id ?? 0)
                    !== (int) ($invoice?->id ?? 0)
            ) {
                throw new DisplayException(
                    'The upgrade invoice binding changed before payment acquired its locks.'
                );
            }

            $alreadyCommitted = in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                ServiceUpgrade::STATUS_COMPLETED,
            ], true);
            $legacyRefundTerminal =
                $upgrade->legacy_refund_only_at !== null
                && $upgrade->status
                    === ServiceUpgrade::STATUS_CANCELLED;
            if ($alreadyCommitted || $legacyRefundTerminal) {
                if (
                    $upgrade->legacy_refund_only_at === null
                    && !$upgrade->snapshotFingerprintsAreAuthentic()
                ) {
                    throw new DisplayException(
                        'The committed upgrade has no authentic signed source and target snapshots.'
                    );
                }
                $this->assertPaidInvoiceBinding(
                    $upgrade,
                    $service,
                    $invoice
                );

                return false;
            }

            if (!in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ], true)) {
                throw new DisplayException('This upgrade can no longer be committed.');
            }
            if ($upgrade->legacy_refund_only_at !== null) {
                throw new DisplayException(
                    'A legacy refund-only upgrade cannot be promoted into provisioning.'
                );
            }

            $this->ensureSnapshots($upgrade);
            $this->assertPaidInvoiceBinding(
                $upgrade,
                $service,
                $invoice
            );

            if (!$upgrade->sourceStillMatches()) {
                // The invoice coordinator owns the durable failure transition.
                // Throwing here aborts only the tentative paid savepoint; its
                // locked preflight is then repeated outside that savepoint and
                // persists either cancellation or payment attention.
                return true;
            }
            if (!$this->usesDynamicCapacity($upgrade)) {
                app(StaticUpgradeStockService::class)
                    ->reserve($upgrade, $service);
            }

            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
                'active_service_guard_id' => $upgrade->service_id,
                'paid_at' => $upgrade->paid_at ?? now(),
                'last_error' => null,
                'failed_at' => null,
            ])->save();

            DB::afterCommit(
                fn () => app(
                    ServiceUpgradeDispatchRecoveryService::class
                )->dispatchById($upgradeId)
            );

            return false;
        }, 5);

        if ($sourceMismatch) {
            throw new DisplayException(
                'The service changed before payment. The paid transition must be rejected.'
            );
        }
    }

    /**
     * Validate an ordinary upgrade while the invoice coordinator holds the
     * invoice-paid transaction open. The caller reports invalid state without
     * throwing until these lifecycle changes have committed.
     */
    public function preflightPaidNonCapacityUpgrade(
        ServiceUpgrade $serviceUpgrade,
        Invoice $invoice,
        bool $hasPaymentEvidence = false
    ): ?string {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            return ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn (): ?string => $this->preflightPaidNonCapacityUpgrade(
                    $serviceUpgrade,
                    $invoice,
                    $hasPaymentEvidence
                )
            );
        }

        return DB::transaction(function () use (
            $serviceUpgrade,
            $invoice,
            $hasPaymentEvidence
        ): ?string {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $service = $this->lockedService(
                (int) $serviceUpgrade->service_id
            );
            $upgrade = $this->lockedUpgrade((int) $serviceUpgrade->id);
            $upgrade->setRelation('service', $service);

            $reason = null;
            if (
                $lockedInvoice->status !== Invoice::STATUS_PENDING
                || (int) $upgrade->invoice_id
                    !== (int) $lockedInvoice->id
            ) {
                $reason = 'The upgrade invoice is no longer payable.';
            } elseif (!in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ], true)) {
                $reason =
                    "Service upgrade {$upgrade->id} cannot be paid from its {$upgrade->status} lifecycle state.";
            } else {
                $this->ensureSnapshots($upgrade);
                $reason = $this->invoiceBindingError(
                    $upgrade,
                    $service,
                    $lockedInvoice,
                    Invoice::STATUS_PENDING
                );
                if ($reason !== null) {
                    // Keep the exact billing obligation proof inside the
                    // invoice-first preflight. The paid transition repeats it
                    // after the invoice status changes to close the TOCTOU gap.
                } elseif (!$upgrade->sourceStillMatches()) {
                    $reason =
                        'The service changed after the upgrade was quoted.';
                } elseif (!$this->usesDynamicCapacity($upgrade)) {
                    try {
                        app(StaticUpgradeStockService::class)
                            ->reserve($upgrade, $service);
                    } catch (DisplayException $exception) {
                        $reason = $exception->getMessage();
                    }
                }
            }

            if ($reason === null) {
                return null;
            }

            $unsafeCommittedState = in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            ], true);
            if ($upgrade->status !== ServiceUpgrade::STATUS_COMPLETED) {
                $requiresAttention =
                    $hasPaymentEvidence || $unsafeCommittedState;
                if (
                    !$requiresAttention
                    && !$this->usesDynamicCapacity($upgrade)
                ) {
                    app(StaticUpgradeStockService::class)
                        ->release($upgrade, $service);
                }
                $upgrade->forceFill([
                    'status' => $requiresAttention
                        ? ServiceUpgrade::STATUS_NEEDS_ATTENTION
                        : ServiceUpgrade::STATUS_CANCELLED,
                    'active_service_guard_id' => $requiresAttention
                        ? $upgrade->service_id
                        : null,
                    'last_error' => $hasPaymentEvidence
                        ? "{$reason} External payment evidence exists; refund or account-credit review is required."
                        : $reason,
                    'failed_at' => now(),
                ])->save();
            }

            if (
                !$hasPaymentEvidence
                && $lockedInvoice->status === Invoice::STATUS_PENDING
            ) {
                app(CancelInvoiceService::class)
                    ->markCancelledAfterFulfillment($lockedInvoice);
            }

            return $reason;
        }, 5);
    }

    public function beginProvisioning(ServiceUpgrade $serviceUpgrade): ?ServiceUpgrade
    {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            return ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn (): ?ServiceUpgrade => $this->beginProvisioning($serviceUpgrade)
            );
        }

        $result = DB::transaction(function () use ($serviceUpgrade): array {
            $service = $this->lockedService(
                (int) $serviceUpgrade->service_id
            );
            $upgrade = $this->lockedUpgrade($serviceUpgrade->id);
            $upgrade->setRelation('service', $service);

            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_COMPLETED,
                ServiceUpgrade::STATUS_CANCELLED,
            ], true)) {
                return [
                    'upgrade' => null,
                    'source_mismatch' => false,
                    'stock_mismatch' => false,
                    'unsafe_remote_state' => false,
                ];
            }

            if ($upgrade->status === ServiceUpgrade::STATUS_PROVISIONING) {
                $mode = data_get(
                    $upgrade->target_snapshot,
                    'provisioner.mode'
                );
                $dynamic = $this->usesDynamicCapacity($upgrade);
                if (
                    !$upgrade->snapshotFingerprintsAreAuthentic()
                    || !in_array(
                        $mode,
                        ['external', 'serverless'],
                        true
                    )
                    || ($mode === 'external' && !$dynamic)
                ) {
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                        'active_service_guard_id' => $upgrade->service_id,
                        'last_error' => 'A previous worker stopped after an external upgrade may have been applied. Automatic retry is unsafe.',
                        'failed_at' => now(),
                    ])->save();

                    return [
                        'upgrade' => null,
                        'source_mismatch' => false,
                        'stock_mismatch' => false,
                        'unsafe_remote_state' => true,
                    ];
                }

                if (
                    $upgrade->provisioning_started_at !== null
                    && $upgrade->provisioning_started_at->gt(
                        now()->subMinutes(10)
                    )
                ) {
                    return [
                        'upgrade' => null,
                        'source_mismatch' => false,
                        'stock_mismatch' => false,
                        'unsafe_remote_state' => false,
                    ];
                }

                // Serverless upgrades have no remote side effect; dynamic
                // Pterodactyl upgrades own a separately expiring,
                // reconciliation-safe lease. Both can be redelivered once
                // the prior overlap lease is stale.
                $upgrade->forceFill([
                    'status' => ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                    'provisioning_started_at' => null,
                    'last_error' => 'Recovered a stale retry-safe provisioning attempt.',
                    'failed_at' => now(),
                ])->save();
            }

            if (!in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
            ], true)) {
                throw new \RuntimeException(
                    "Upgrade {$upgrade->id} is not ready for provisioning."
                );
            }

            if (!$upgrade->sourceStillMatches()) {
                $upgrade->forceFill([
                    'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                    'last_error' => 'The service changed after the upgrade commitment.',
                    'failed_at' => now(),
                ])->save();

                return [
                    'upgrade' => null,
                    'source_mismatch' => true,
                    'stock_mismatch' => false,
                    'unsafe_remote_state' => false,
                ];
            }
            if (!$this->usesDynamicCapacity($upgrade)) {
                try {
                    app(StaticUpgradeStockService::class)
                        ->assertReserved($upgrade, $service);
                } catch (\RuntimeException $exception) {
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                        'last_error' => $exception->getMessage(),
                        'failed_at' => now(),
                    ])->save();

                    return [
                        'upgrade' => null,
                        'source_mismatch' => false,
                        'stock_mismatch' => true,
                        'unsafe_remote_state' => false,
                    ];
                }
            }

            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_PROVISIONING,
                'provisioning_started_at' => now(),
                'provisioning_attempts' => (int) $upgrade->provisioning_attempts + 1,
                'last_error' => null,
            ])->save();

            return [
                'upgrade' => $upgrade->fresh([
                    'service.product.server.settings',
                    'service.plan',
                    'service.configs.configOption',
                    'service.configs.configValue',
                    'product.server.settings',
                    'plan',
                    'configs.configOption',
                    'configs.configValue',
                ]),
                'source_mismatch' => false,
                'stock_mismatch' => false,
                'unsafe_remote_state' => false,
            ];
        }, 5);

        if ($result['unsafe_remote_state']) {
            throw new PermanentProvisioningException(
                'An indeterminate external upgrade requires operator reconciliation.'
            );
        }
        if ($result['stock_mismatch']) {
            throw new PermanentProvisioningException(
                'The paid upgrade no longer owns its target product stock reservation.'
            );
        }
        if ($result['source_mismatch']) {
            throw new PermanentProvisioningException(
                'The service no longer matches the paid upgrade source snapshot.'
            );
        }

        return $result['upgrade'];
    }

    /**
     * Finalize local state only after the server extension accepted the target.
     */
    public function complete(
        ServiceUpgrade $serviceUpgrade,
        ?string $reservationLeaseId = null
    ): void {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn () => $this->complete(
                    $serviceUpgrade,
                    $reservationLeaseId
                )
            );

            return;
        }

        $upgradeId = (int) $serviceUpgrade->id;
        $serviceId = (int) ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->value('service_id');
        if ($serviceId <= 0) {
            throw new PermanentProvisioningException(
                "Upgrade {$upgradeId} has no service to complete."
            );
        }

        DB::transaction(function () use (
            $upgradeId,
            $serviceId,
            $reservationLeaseId
        ): void {
            // Keep the shared completion/cancellation/renewal order:
            // service -> upgrade -> reservation. Reading service_id before
            // entering this transaction is safe only as a lock target; the
            // association is revalidated after both rows are locked.
            $service = Service::query()
                ->with([
                    'product.server.settings',
                    'product.settings',
                    'plan.prices',
                    'configs.configOption',
                    'configs.configValue',
                    'user',
                    'coupon',
                ])
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->firstOrFail();
            $upgrade = $this->lockedUpgrade($upgradeId);
            if ((int) $upgrade->service_id !== (int) $service->id) {
                throw new PermanentProvisioningException(
                    'The paid upgrade no longer belongs to its locked service.'
                );
            }
            if ($upgrade->status === ServiceUpgrade::STATUS_COMPLETED) {
                return;
            }
            if ($upgrade->status !== ServiceUpgrade::STATUS_PROVISIONING) {
                throw new \RuntimeException(
                    "Upgrade {$upgrade->id} cannot be completed from {$upgrade->status}."
                );
            }

            $crossProduct =
                (int) $service->product_id
                !== (int) $upgrade->product_id;

            // The remote request runs outside this transaction. Repeat the
            // immutable source and billing-anchor proof while the service is
            // locked before consuming capacity or mutating local billing.
            $upgrade->setRelation('service', $service);
            if (!$upgrade->sourceStillMatches()) {
                throw new PermanentProvisioningException(
                    'The service changed after remote upgrade provisioning; operator reconciliation is required.'
                );
            }

            if ($this->usesDynamicCapacity($upgrade)) {
                $this->capacityService()->completeProvisioning(
                    $upgrade,
                    $reservationLeaseId
                );
            }

            if (
                (int) $service->product_id !== (int) $upgrade->product_id
                && !$this->usesDynamicCapacity($upgrade)
            ) {
                app(StaticUpgradeStockService::class)
                    ->consume($upgrade, $service);
            }

            FulfillmentStatusTransitionService::run(
                $service,
                fn () => $service->forceFill([
                    'product_id' => $upgrade->product_id,
                    'plan_id' => $upgrade->plan_id,
                    'quantity' => 1,
                ])->save()
            );

            FulfillmentStatusTransitionService::run(
                $service,
                function () use (
                    $crossProduct,
                    $upgrade,
                    $service
                ): void {
                    $sourceManagedKeys = collect((array) data_get(
                        $upgrade->source_snapshot,
                        'managed_property_keys',
                        []
                    ))->map(
                        fn ($key): string => strtolower(trim((string) $key))
                    );
                    $targetManagedKeys = collect((array) data_get(
                        $upgrade->target_snapshot,
                        'managed_property_keys',
                        []
                    ))->map(
                        fn ($key): string => strtolower(trim((string) $key))
                    );
                    $targetSettingKeys = collect(
                        ExtensionHelper::settingsToArray(
                            $upgrade->product->settings
                        )
                    )->keys()->map(
                        fn ($key): string => strtolower(trim((string) $key))
                    );
                    $targetConfigKeys = collect((array) data_get(
                        $upgrade->target_snapshot,
                        'configs',
                        []
                    ))->pluck('property_key')->map(
                        fn ($key): string => strtolower(trim((string) $key))
                    );
                    $managedPropertyKeys = ($crossProduct
                        ? $sourceManagedKeys->merge($targetManagedKeys)
                        : $targetConfigKeys)
                        ->merge($targetSettingKeys)
                        ->filter()
                        ->unique();
                    $service->properties()
                        ->get()
                        ->filter(
                            fn ($property): bool => $managedPropertyKeys->contains(
                                strtolower(trim(
                                    (string) $property->key
                                ))
                            )
                        )
                        ->each->delete();

                    if ($crossProduct) {
                        $targetOptionIds = $upgrade->configs
                            ->pluck('config_option_id')
                            ->map(fn ($id): int => (int) $id)
                            ->unique()
                            ->values();
                        $obsolete = $service->configs();
                        if ($targetOptionIds->isNotEmpty()) {
                            $obsolete->whereNotIn(
                                'config_option_id',
                                $targetOptionIds->all()
                            );
                        }
                        $obsolete->get()->each->delete();
                    }

                    foreach ($upgrade->configs as $config) {
                        $option = $config->configOption;
                        if ($option === null) {
                            continue;
                        }

                        if ($option->isDynamicSlider()) {
                            $storedValue = StrictInteger::parseStoredDecimal(
                                $config->slider_value
                            );
                            if ($storedValue === null) {
                                throw new \RuntimeException(
                                    "The stored target for {$option->name} must be a whole number."
                                );
                            }
                            // The quote already validated the value against the
                            // then-current policy. Materialize that signed
                            // integer without reinterpreting later min/step
                            // metadata changes.
                            $value = $storedValue;
                            $service->configs()->updateOrCreate(
                                ['config_option_id' => $option->id],
                                [
                                    'config_value_id' => null,
                                    'slider_value' => $value,
                                ]
                            );
                            $service->properties()->updateOrCreate(
                                ['key' => $option->env_variable ?: $option->name],
                                [
                                    'name' => $option->name,
                                    'value' => $value,
                                ]
                            );

                            continue;
                        }

                        $service->configs()->updateOrCreate(
                            ['config_option_id' => $option->id],
                            [
                                'config_value_id' => $config->config_value_id,
                                'slider_value' => null,
                            ]
                        );
                    }
                }
            );

            $service->refresh();
            $service->load(['plan.prices', 'configs.configOption', 'configs.configValue']);
            $targetRecurring = StrictDecimal::parseNonNegative(
                data_get($upgrade->target_snapshot, 'recurring_price')
            );
            if ($targetRecurring === null) {
                throw new \RuntimeException(
                    'The upgrade target is missing its immutable recurring price.'
                );
            }
            $targetCoupon = data_get(
                $upgrade->target_snapshot,
                'coupon_id'
            );
            if ($targetCoupon !== null) {
                $targetCoupon = StrictInteger::parse($targetCoupon);
                if (
                    $targetCoupon === null
                    || $targetCoupon <= 0
                    || $targetCoupon !== (int) $service->coupon_id
                ) {
                    throw new \RuntimeException(
                        'The upgrade target has an invalid signed coupon disposition.'
                    );
                }
            }
            $service->price = number_format(
                $targetRecurring,
                2,
                '.',
                ''
            );
            $service->current_period_price = $service->price;
            $service->coupon_id = $targetCoupon;
            FulfillmentStatusTransitionService::run(
                $service,
                fn () => $service->save()
            );
            $this->applyCreditOnce($upgrade, $service);

            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_COMPLETED,
                'active_service_guard_id' => null,
                'completed_at' => now(),
                'last_error' => null,
                'failed_at' => null,
            ])->save();
        }, 5);
    }

    public function recordFailure(
        ServiceUpgrade $serviceUpgrade,
        \Throwable $exception,
        bool $permanent = false,
        ?string $reservationLeaseId = null
    ): void {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn () => $this->recordFailure(
                    $serviceUpgrade,
                    $exception,
                    $permanent,
                    $reservationLeaseId
                )
            );

            return;
        }

        $shouldAlert = DB::transaction(function () use (
            $serviceUpgrade,
            $exception,
            $permanent,
            $reservationLeaseId
        ): bool {
            $upgrade = $this->lockedUpgrade($serviceUpgrade->id);
            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_COMPLETED,
                ServiceUpgrade::STATUS_CANCELLED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            ], true)) {
                return false;
            }

            if ($this->usesDynamicCapacity($upgrade)) {
                try {
                    // Isolate coordinator mutations behind a savepoint. If
                    // extension resolution or failProvisioning() throws, no
                    // partial reservation release may escape into the fallback
                    // core state transition below.
                    $ownsFailure = DB::transaction(
                        fn () => $this->capacityService()->failProvisioning(
                            $upgrade,
                            $exception,
                            $reservationLeaseId
                        )
                    );
                } catch (\Throwable $coordinatorException) {
                    $shouldAlert =
                        $upgrade->failure_alerted_at === null;
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                        'active_service_guard_id' => $upgrade->service_id,
                        'last_error' => mb_substr(
                            $exception->getMessage()
                            . ' Reservation failure reconciliation also failed: '
                            . $coordinatorException->getMessage(),
                            0,
                            65535
                        ),
                        'failed_at' => now(),
                        'failure_alerted_at' => $shouldAlert
                            ? now()
                            : $upgrade->failure_alerted_at,
                    ])->save();

                    return $shouldAlert;
                }
                if (!$ownsFailure) {
                    // A newer worker owns the reservation (or has already
                    // completed it). A stale failure must not overwrite the
                    // authoritative lifecycle state.
                    return false;
                }
            }

            $terminal = $permanent
                || (int) $upgrade->provisioning_attempts >= 5;
            $shouldAlert = $terminal
                && $upgrade->failure_alerted_at === null;
            $upgrade->forceFill([
                'status' => $terminal
                    ? ServiceUpgrade::STATUS_NEEDS_ATTENTION
                    : ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 65535),
                'failed_at' => now(),
                'failure_alerted_at' => $shouldAlert
                    ? now()
                    : $upgrade->failure_alerted_at,
            ])->save();

            return $shouldAlert;
        }, 5);

        if ($shouldAlert) {
            app(UpgradeFailureAlertService::class)->notify(
                (int) $serviceUpgrade->id
            );
        }
    }

    public function cancel(ServiceUpgrade $serviceUpgrade, string $reason): void
    {
        if (
            !ServiceUpgradeMutationCoordinator::isCoordinating(
                $serviceUpgrade
            )
        ) {
            ServiceUpgradeMutationCoordinator::run(
                $serviceUpgrade,
                fn () => $this->cancel($serviceUpgrade, $reason)
            );

            return;
        }

        $invoice = $serviceUpgrade->invoice()->first();
        if (
            $invoice?->status === Invoice::STATUS_PENDING
            && !CancelInvoiceService::isCoordinating($invoice)
        ) {
            app(CancelInvoiceService::class)->handle($invoice, $reason);
            $serviceUpgrade->refresh();
            if (in_array($serviceUpgrade->status, [
                ServiceUpgrade::STATUS_COMPLETED,
                ServiceUpgrade::STATUS_CANCELLED,
            ], true)) {
                return;
            }
        }

        DB::transaction(function () use ($serviceUpgrade, $reason): void {
            $service = $this->lockedService(
                (int) $serviceUpgrade->service_id
            );
            $upgrade = $this->lockedUpgrade($serviceUpgrade->id);
            $upgrade->setRelation('service', $service);
            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_COMPLETED,
                ServiceUpgrade::STATUS_CANCELLED,
            ], true)) {
                return;
            }
            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            ], true)) {
                throw new \RuntimeException(
                    'A paid committed upgrade requires operator reconciliation before cancellation.'
                );
            }

            if ($this->usesDynamicCapacity($upgrade)) {
                $this->capacityService()->cancelUpgrade($upgrade, $reason);
            } else {
                app(StaticUpgradeStockService::class)
                    ->release($upgrade, $service);
            }

            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_CANCELLED,
                'active_service_guard_id' => null,
                'last_error' => $reason,
                'failed_at' => now(),
            ])->save();

        }, 5);
    }

    private function lockedUpgrade(int $upgradeId): ServiceUpgrade
    {
        return ServiceUpgrade::query()
            ->with([
                'service.product.server.settings',
                'service.plan.prices',
                'service.configs.configOption',
                'service.configs.configValue',
                'service.user',
                'product.server.settings',
                'product.settings',
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
                'invoice',
            ])
            ->lockForUpdate()
            ->findOrFail($upgradeId);
    }

    private function lockedService(int $serviceId): Service
    {
        return Service::query()
            ->with([
                'product.server.settings',
                'product.settings',
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
                'user',
                'coupon',
            ])
            ->whereKey($serviceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureSnapshots(ServiceUpgrade $upgrade): void
    {
        if (
            $upgrade->source_fingerprint !== null
            && $upgrade->target_fingerprint !== null
        ) {
            return;
        }

        $upgrade->captureSnapshots();
        $upgrade->quoted_amount ??= round(
            (float) $upgrade->signedUpgradePrice()->price,
            2
        );
        $upgrade->credit_amount = $upgrade->signedCreditAmount();
        $upgrade->currency_code ??= strtoupper(
            (string) $upgrade->service->currency_code
        );
        $upgrade->active_service_guard_id = $upgrade->service_id;
        $upgrade->save();
    }

    private function assertPaidInvoiceBinding(
        ServiceUpgrade $upgrade,
        Service $service,
        ?Invoice $invoice
    ): void {
        $reason = $this->invoiceBindingError(
            $upgrade,
            $service,
            $invoice,
            Invoice::STATUS_PAID
        );
        if ($reason !== null) {
            throw new DisplayException($reason);
        }
    }

    private function invoiceBindingError(
        ServiceUpgrade $upgrade,
        Service $service,
        ?Invoice $invoice,
        string $expectedStatus
    ): ?string {
        $quotedCents = (int) round(
            (float) ($upgrade->quoted_amount ?? 0) * 100
        );
        $signedQuote = data_get(
            $upgrade->target_snapshot,
            'upgrade_price'
        );
        if (
            !is_string($signedQuote)
            || preg_match(
                '/^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D',
                $signedQuote
            ) !== 1
            || (int) round((float) $signedQuote * 100)
                !== $quotedCents
        ) {
            return 'The upgrade amount does not match its signed pricing snapshot.';
        }
        $signedCredit = data_get(
            $upgrade->target_snapshot,
            'credit_amount'
        );
        if (
            !is_string($signedCredit)
            || preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D',
                $signedCredit
            ) !== 1
            || (int) round((float) $signedCredit * 100)
                !== (int) round(
                    (float) ($upgrade->credit_amount ?? 0) * 100
                )
            || (float) $signedCredit
                > max(0, -(float) $signedQuote)
        ) {
            return 'The upgrade credit does not match its signed pricing snapshot.';
        }
        if ($quotedCents <= 0) {
            if ($invoice === null) {
                return null;
            }

            return 'A zero-value or downgrade commitment must not have a paid invoice.';
        }
        if ($invoice === null) {
            return 'A positive upgrade cannot be committed without a paid invoice.';
        }

        $lines = $invoice->items()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $line = $lines->first();
        $lineCents = $line === null
            ? null
            : (int) round(
                (float) $line->price
                * (int) $line->quantity
                * 100
            );
        if (
            $invoice->status !== $expectedStatus
            || (int) $invoice->user_id !== (int) $service->user_id
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $service->currency_code)
            || strtoupper((string) $upgrade->currency_code)
                !== strtoupper((string) $service->currency_code)
            || $lines->count() !== 1
            || $line->reference_type !== ServiceUpgrade::class
            || (int) $line->reference_id !== (int) $upgrade->id
            || (int) $line->quantity !== 1
            || $lineCents !== $quotedCents
        ) {
            return 'The upgrade invoice does not match its immutable obligation.';
        }

        return null;
    }

    protected function usesDynamicCapacity(ServiceUpgrade $upgrade): bool
    {
        return app(CapacityUpgradeReservationIdentity::class)
            ->requiresCoordinator($upgrade);
    }

    protected function capacityService(): object
    {
        $class = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        if (!class_exists($class)) {
            throw new \RuntimeException(
                'Dynamic upgrade reservation support is unavailable.'
            );
        }

        return app($class);
    }

    private function applyCreditOnce(ServiceUpgrade $upgrade, Service $service): void
    {
        if (
            (float) ($upgrade->credit_amount ?? 0) <= 0
            || $upgrade->credit_applied_at !== null
        ) {
            return;
        }

        app(CreditInvoicePaymentService::class)->addBalance(
            (int) $service->user_id,
            (string) $upgrade->currency_code,
            $upgrade->credit_amount
        );

        $upgrade->credit_applied_at = now();
    }
}
