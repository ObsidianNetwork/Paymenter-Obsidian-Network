<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Exceptions\PermanentProvisioningException;
use App\Jobs\Server\UpgradeJob;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
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
        $this->markPaidCommitted($serviceUpgrade);
    }

    public function markPaidCommitted(ServiceUpgrade $serviceUpgrade): void
    {
        $upgradeId = $serviceUpgrade->id;

        $sourceMismatch = DB::transaction(function () use ($upgradeId): bool {
            $upgrade = $this->lockedUpgrade($upgradeId);

            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                ServiceUpgrade::STATUS_COMPLETED,
            ], true)) {
                return false;
            }

            if (! in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ], true)) {
                throw new DisplayException('This upgrade can no longer be committed.');
            }

            $this->ensureSnapshots($upgrade);
            if (! $upgrade->sourceStillMatches()) {
                // The invoice coordinator owns the durable failure transition.
                // Throwing here aborts only the tentative paid savepoint; its
                // locked preflight is then repeated outside that savepoint and
                // persists either cancellation or payment attention.
                return true;
            }

            $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
                'active_service_guard_id' => $upgrade->service_id,
                'paid_at' => $upgrade->paid_at ?? now(),
                'last_error' => null,
                'failed_at' => null,
            ])->save();

            DB::afterCommit(
                fn () => UpgradeJob::dispatch(
                    ServiceUpgrade::query()->findOrFail($upgradeId)
                )
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
        return DB::transaction(function () use (
            $serviceUpgrade,
            $invoice,
            $hasPaymentEvidence
        ): ?string {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            Service::query()
                ->whereKey($serviceUpgrade->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $upgrade = $this->lockedUpgrade((int) $serviceUpgrade->id);

            $reason = null;
            if (
                $lockedInvoice->status !== Invoice::STATUS_PENDING
                || (int) $upgrade->invoice_id
                    !== (int) $lockedInvoice->id
            ) {
                $reason = 'The upgrade invoice is no longer payable.';
            } elseif (! in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            ], true)) {
                $reason =
                    "Service upgrade {$upgrade->id} cannot be paid from its {$upgrade->status} lifecycle state.";
            } else {
                $this->ensureSnapshots($upgrade);
                if (! $upgrade->sourceStillMatches()) {
                    $reason =
                        'The service changed after the upgrade was quoted.';
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
                ! $hasPaymentEvidence
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
        $result = DB::transaction(function () use ($serviceUpgrade): array {
            $upgrade = $this->lockedUpgrade($serviceUpgrade->id);

            if (in_array($upgrade->status, [
                ServiceUpgrade::STATUS_COMPLETED,
                ServiceUpgrade::STATUS_CANCELLED,
            ], true)) {
                return ['upgrade' => null, 'source_mismatch' => false];
            }

            if (! in_array($upgrade->status, [
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
            ], true)) {
                throw new \RuntimeException(
                    "Upgrade {$upgrade->id} is not ready for provisioning."
                );
            }

            if (! $upgrade->sourceStillMatches()) {
                $upgrade->forceFill([
                    'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                    'last_error' => 'The service changed after the upgrade commitment.',
                    'failed_at' => now(),
                ])->save();

                return ['upgrade' => null, 'source_mismatch' => true];
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
            ];
        }, 5);

        if ($result['source_mismatch']) {
            throw new \App\Exceptions\PermanentProvisioningException(
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

            // The remote request runs outside this transaction. Repeat the
            // immutable source and billing-anchor proof while the service is
            // locked before consuming capacity or mutating local billing.
            $upgrade->setRelation('service', $service);
            if (! $upgrade->sourceStillMatches()) {
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

            if ((int) $service->product_id !== (int) $upgrade->product_id) {
                if ($service->product->stock !== null) {
                    $service->product->increment('stock', $service->quantity);
                }

                $targetProduct = $upgrade->product;
                if ($targetProduct->stock !== null) {
                    $targetProduct->decrement('stock', $service->quantity);
                }
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
                function () use ($upgrade, $service): void {
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
                            $value = $option->normalizeDynamicSliderValue(
                                $storedValue
                            );
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
            $service->price = number_format(
                $targetRecurring,
                2,
                '.',
                ''
            );
            FulfillmentStatusTransitionService::run(
                $service,
                fn () => $service->save()
            );
            $this->updatePendingRenewal($service);
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
    ): void
    {
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
                        'status' =>
                            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                        'active_service_guard_id' =>
                            $upgrade->service_id,
                        'last_error' => mb_substr(
                            $exception->getMessage()
                            .' Reservation failure reconciliation also failed: '
                            .$coordinatorException->getMessage(),
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
                if (! $ownsFailure) {
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
        $invoice = $serviceUpgrade->invoice()->first();
        if (
            $invoice?->status === \App\Models\Invoice::STATUS_PENDING
            && ! CancelInvoiceService::isCoordinating($invoice)
        ) {
            app(CancelInvoiceService::class)->handle($invoice, $reason);

            return;
        }

        DB::transaction(function () use ($serviceUpgrade, $reason): void {
            $upgrade = $this->lockedUpgrade($serviceUpgrade->id);
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
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
                'invoice',
            ])
            ->lockForUpdate()
            ->findOrFail($upgradeId);
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
            (float) $upgrade->calculatePrice()->price,
            2
        );
        $upgrade->currency_code ??= strtoupper(
            (string) $upgrade->service->currency_code
        );
        $upgrade->active_service_guard_id = $upgrade->service_id;
        $upgrade->save();
    }

    protected function usesDynamicCapacity(ServiceUpgrade $upgrade): bool
    {
        return app(CapacityUpgradeReservationIdentity::class)
            ->requiresCoordinator($upgrade);
    }

    protected function capacityService(): object
    {
        $class = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        if (! class_exists($class)) {
            throw new \RuntimeException(
                'Dynamic upgrade reservation support is unavailable.'
            );
        }

        return app($class);
    }

    private function updatePendingRenewal(Service $service): void
    {
        $pendingInvoice = $service->invoices()
            ->where('status', \App\Models\Invoice::STATUS_PENDING)
            ->first();
        if ($pendingInvoice === null) {
            return;
        }

        $pendingInvoice->items()
            ->where('reference_type', Service::class)
            ->where('reference_id', $service->id)
            ->update(['price' => $service->price]);
    }

    private function applyCreditOnce(ServiceUpgrade $upgrade, Service $service): void
    {
        $amount = (float) ($upgrade->credit_amount ?? 0);
        if ($amount <= 0 || $upgrade->credit_applied_at !== null) {
            return;
        }

        $credit = Credit::query()
            ->where('user_id', $service->user_id)
            ->where('currency_code', $upgrade->currency_code)
            ->lockForUpdate()
            ->first();

        if ($credit === null) {
            Credit::create([
                'user_id' => $service->user_id,
                'currency_code' => $upgrade->currency_code,
                'amount' => $amount,
            ]);
        } else {
            $credit->amount = (float) $credit->amount + $amount;
            $credit->save();
        }

        $upgrade->credit_applied_at = now();
    }
}
