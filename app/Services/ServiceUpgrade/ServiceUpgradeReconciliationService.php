<?php

namespace App\Services\ServiceUpgrade;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\ServiceUpgradeReconciliation;
use App\Services\Invoice\CancelInvoiceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audited operator recovery for a static upgrade whose remote outcome cannot
 * be inferred safely by an automatic retry.
 */
class ServiceUpgradeReconciliationService
{
    public const ACTION_RETRY = 'retry';

    public const ACTION_ATTEST_COMPLETED = 'attest_completed';

    public const ACTION_REFUNDED_NOT_APPLIED =
        'refunded_not_applied';

    public function reconcile(
        int $upgradeId,
        string $action,
        string $reason,
        string $operator
    ): ServiceUpgrade {
        $reason = trim($reason);
        $operator = trim($operator);
        $this->validateRequest($action, $reason, $operator);

        $identity = ServiceUpgrade::query()
            ->whereKey($upgradeId)
            ->first(['id', 'service_id', 'invoice_id']);
        if ($identity === null || (int) $identity->service_id <= 0) {
            throw new \RuntimeException(
                "Upgrade {$upgradeId} has no service to reconcile."
            );
        }

        $idempotencyKey = hash('sha256', json_encode([
            'action' => $action,
            'operator' => $operator,
            'reason' => $reason,
            'service_upgrade_id' => $upgradeId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $result = DB::transaction(function () use (
            $identity,
            $upgradeId,
            $action,
            $reason,
            $operator,
            $idempotencyKey
        ): ServiceUpgrade {
            // Match payment and cancellation lock order:
            // invoice -> service -> upgrade -> invoice lines/evidence.
            $invoice = $identity->invoice_id === null
                ? null
                : Invoice::query()
                    ->whereKey($identity->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();
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
                ->whereKey($identity->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $upgrade = ServiceUpgrade::query()
                ->with([
                    'product.server.settings',
                    'product.settings',
                    'plan.prices',
                    'configs.configOption',
                    'configs.configValue',
                ])
                ->whereKey($upgradeId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                (int) $upgrade->service_id !== (int) $service->id
                || (int) ($upgrade->invoice_id ?? 0)
                    !== (int) ($invoice?->id ?? 0)
            ) {
                throw new \RuntimeException(
                    'The upgrade billing identity changed before reconciliation acquired its locks.'
                );
            }
            $upgrade->setRelation('service', $service);
            $upgrade->setRelation('invoice', $invoice);

            $lines = $invoice === null
                ? collect()
                : $invoice->items()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            $transactions = $invoice === null
                ? collect()
                : $invoice->transactions()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            $this->assertBillingIdentity(
                $upgrade,
                $service,
                $invoice,
                $lines
            );

            $existing = ServiceUpgradeReconciliation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $upgrade->fresh();
            }

            if ($upgrade->status !== ServiceUpgrade::STATUS_NEEDS_ATTENTION) {
                throw new \RuntimeException(
                    "Upgrade {$upgrade->id} cannot be reconciled from {$upgrade->status}."
                );
            }
            $legacyRefundOnly =
                $upgrade->legacy_refund_only_at !== null;
            if ($legacyRefundOnly) {
                $this->assertLegacyRefundOnlyEvidence($upgrade);
                if (
                    $action
                    !== self::ACTION_REFUNDED_NOT_APPLIED
                ) {
                    throw new \RuntimeException(
                        'This legacy paid upgrade has no signed source or target proof. Only refunded_not_applied reconciliation is safe.'
                    );
                }
            } elseif (
                $upgrade->capacity_mode
                    !== ServiceUpgrade::CAPACITY_MODE_STATIC
            ) {
                throw new \RuntimeException(
                    'This recovery command is limited to static upgrade stock, except for refund-only legacy commitments.'
                );
            }

            $beforeStatus = $upgrade->status;
            $stock = app(StaticUpgradeStockService::class);
            if ($action === self::ACTION_REFUNDED_NOT_APPLIED) {
                $this->assertPaymentEvidenceOrFree(
                    $upgrade,
                    $invoice,
                    $transactions
                );
                $crossProduct =
                    (int) $service->product_id
                    !== (int) $upgrade->product_id;
                if ($crossProduct && !$legacyRefundOnly) {
                    $stock->assertActiveOwnership($upgrade, $service);
                }
                $released = $legacyRefundOnly
                    ? false
                    : $stock->release($upgrade, $service);
                if (
                    $crossProduct
                    && !$legacyRefundOnly
                    && !$released
                ) {
                    throw new \RuntimeException(
                        'The target-stock hold was not released.'
                    );
                }

                $upgrade->forceFill([
                    'status' => ServiceUpgrade::STATUS_CANCELLED,
                    'active_service_guard_id' => null,
                    'last_error' => $reason,
                    'failed_at' => now(),
                ]);
                ServiceUpgradeMutationCoordinator::save($upgrade);
                if ($invoice?->status === Invoice::STATUS_PENDING) {
                    app(CancelInvoiceService::class)
                        ->markCancelledAfterFulfillment($invoice);
                }
            } else {
                $this->assertPaymentSettledOrFree($upgrade, $invoice);
                $stock->assertReserved($upgrade, $service);
                if (!$upgrade->sourceStillMatches()) {
                    throw new \RuntimeException(
                        'The service still differs from the signed upgrade source. Correct it or attest refund/non-application instead.'
                    );
                }

                if ($action === self::ACTION_RETRY) {
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
                        'active_service_guard_id' => $upgrade->service_id,
                        'last_error' => null,
                        'failed_at' => null,
                    ]);
                    ServiceUpgradeMutationCoordinator::save($upgrade);
                    DB::afterCommit(
                        fn () => app(
                            ServiceUpgradeDispatchRecoveryService::class
                        )->dispatchById((int) $upgrade->id)
                    );
                } else {
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_PROVISIONING,
                        'active_service_guard_id' => $upgrade->service_id,
                        'last_error' => null,
                        'failed_at' => null,
                    ]);
                    ServiceUpgradeMutationCoordinator::save($upgrade);
                    app(ServiceUpgradeService::class)
                        ->complete($upgrade);
                    $upgrade = ServiceUpgrade::query()
                        ->whereKey($upgradeId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            ServiceUpgradeReconciliation::query()->create([
                'service_upgrade_id' => $upgrade->id,
                'service_id' => $service->id,
                'invoice_id' => $invoice?->id,
                'action' => $action,
                'reason' => $reason,
                'operator' => $operator,
                'before_status' => $beforeStatus,
                'after_status' => $upgrade->status,
                'payment_evidence' => $this->paymentEvidence(
                    $invoice,
                    $transactions
                ),
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);

            return $upgrade->fresh();
        }, 5);

        Log::notice('Static service upgrade reconciled by an operator.', [
            'service_upgrade_id' => $result->id,
            'service_id' => $result->service_id,
            'action' => $action,
            'operator' => $operator,
            'reason' => $reason,
        ]);

        return $result;
    }

    private function validateRequest(
        string $action,
        string $reason,
        string $operator
    ): void {
        if ($reason === '' || mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException(
                'A reconciliation reason of at least 10 characters is required.'
            );
        }
        if ($operator === '') {
            throw new \InvalidArgumentException(
                'An operator identity is required.'
            );
        }
        if (!in_array($action, [
            self::ACTION_RETRY,
            self::ACTION_ATTEST_COMPLETED,
            self::ACTION_REFUNDED_NOT_APPLIED,
        ], true)) {
            throw new \InvalidArgumentException(
                "Unsupported upgrade reconciliation action {$action}."
            );
        }
    }

    private function assertBillingIdentity(
        ServiceUpgrade $upgrade,
        Service $service,
        ?Invoice $invoice,
        Collection $lines
    ): void {
        if ($invoice === null) {
            if ((float) ($upgrade->quoted_amount ?? 0) <= 0) {
                return;
            }

            throw new \RuntimeException(
                'A positive upgrade has no invoice payment anchor.'
            );
        }

        $line = $lines->first();
        $quotedCents = (int) round(
            (float) $upgrade->quoted_amount * 100
        );
        $lineCents = $line === null
            ? null
            : (int) round(
                (float) $line->price * (int) $line->quantity * 100
            );
        if (
            (int) $invoice->user_id !== (int) $service->user_id
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
            throw new \RuntimeException(
                'The locked upgrade invoice no longer matches its immutable obligation.'
            );
        }
    }

    private function assertPaymentSettledOrFree(
        ServiceUpgrade $upgrade,
        ?Invoice $invoice
    ): void {
        if ($invoice === null) {
            if ((float) ($upgrade->quoted_amount ?? 0) <= 0) {
                return;
            }

            throw new \RuntimeException(
                'A positive upgrade has no invoice payment anchor.'
            );
        }
        if ($invoice->status !== Invoice::STATUS_PAID) {
            throw new \RuntimeException(
                'The upgrade invoice is not durably paid. Reconcile its payment evidence before stock recovery.'
            );
        }
    }

    private function assertPaymentEvidenceOrFree(
        ServiceUpgrade $upgrade,
        ?Invoice $invoice,
        Collection $transactions
    ): void {
        if ($invoice === null) {
            if ((float) ($upgrade->quoted_amount ?? 0) <= 0) {
                return;
            }

            throw new \RuntimeException(
                'A positive upgrade has no invoice payment anchor.'
            );
        }
        if (
            $invoice->status === Invoice::STATUS_PAID
            || $transactions->contains(
                fn ($transaction): bool => in_array(
                    $transaction->status,
                    [
                        InvoiceTransactionStatus::Processing,
                        InvoiceTransactionStatus::Succeeded,
                    ],
                    true
                )
            )
        ) {
            return;
        }

        throw new \RuntimeException(
            'No durable payment evidence exists to attest as refunded.'
        );
    }

    private function assertLegacyRefundOnlyEvidence(
        ServiceUpgrade $upgrade
    ): void {
        if (
            $upgrade->target_stock_reserved_quantity !== null
            || $upgrade->target_stock_reserved_at !== null
            || $upgrade->target_stock_released_at !== null
            || $upgrade->target_stock_consumed_at !== null
            || $upgrade->target_stock_fingerprint !== null
        ) {
            throw new \RuntimeException(
                'Legacy refund-only evidence conflicts with a target-stock ownership record.'
            );
        }
        if (
            !Schema::hasTable('ptero_resource_reservations')
            || !Schema::hasColumns(
                'ptero_resource_reservations',
                ['purpose', 'service_upgrade_id', 'status']
            )
        ) {
            return;
        }

        $reservation = DB::table('ptero_resource_reservations')
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $upgrade->id)
            ->whereIn('status', [
                'pending',
                'paid_committed',
                'confirmed',
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id', 'status']);
        if ($reservation !== null) {
            throw new \RuntimeException(
                "Legacy refund-only upgrade {$upgrade->id} still owns dynamic capacity reservation {$reservation->id} ({$reservation->status}). Reconcile it through the extension capacity coordinator first."
            );
        }
    }

    private function paymentEvidence(
        ?Invoice $invoice,
        Collection $transactions
    ): array {
        return [
            'invoice' => $invoice === null ? null : [
                'id' => (int) $invoice->id,
                'status' => (string) $invoice->status,
                'user_id' => (int) $invoice->user_id,
                'currency_code' => strtoupper((string) $invoice->currency_code),
                'attention_required_at' => $invoice->payment_attention_required_at
                    ?->toIso8601String(),
            ],
            'transactions' => $transactions
                ->map(fn ($transaction): array => [
                    'id' => (int) $transaction->id,
                    'gateway_id' => $transaction->gateway_id === null
                        ? null
                        : (int) $transaction->gateway_id,
                    'transaction_id' => (string) $transaction->transaction_id,
                    'amount' => (string) $transaction->amount,
                    'status' => $transaction->status->value,
                ])
                ->values()
                ->all(),
        ];
    }
}
