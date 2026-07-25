<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\DurableFulfillmentService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use Illuminate\Support\Facades\DB;

class MarkInvoicePaidService
{
    /**
     * Observer guard keyed by invoice ID. This prevents an ordinary model save
     * from committing "paid" before fulfillment obligations are locked.
     *
     * @var array<int, int>
     */
    private static array $coordinatedInvoiceIds = [];

    public static function isCoordinating(Invoice $invoice): bool
    {
        return isset(self::$coordinatedInvoiceIds[(int) $invoice->getKey()]);
    }

    /**
     * Marking an invoice paid and committing every fulfillment obligation must
     * be one transaction. InvoiceObserver invokes ProcessPaidInvoiceService
     * inside this transaction.
     */
    public function handle(Invoice|int $invoice): Invoice
    {
        $invoiceId = $invoice instanceof Invoice ? (int) $invoice->id : $invoice;
        $deadline = app(CapacityInvoicePaymentService::class);
        $deadlineInvoice = Invoice::query()->findOrFail($invoiceId);
        $recoveryReason = $deadline->paymentEvidenceRecoveryReason(
            $invoiceId
        );
        if ($recoveryReason !== null) {
            return $deadline->requireAttention(
                $deadlineInvoice,
                $recoveryReason
            );
        }
        $deadline->assertPaymentAttemptAllowed($deadlineInvoice);
        if ($deadline->deadlineExpired($deadlineInvoice)) {
            if ($deadline->hasInFlightOrSucceededPayment($deadlineInvoice)) {
                return $deadline->requireAttention(
                    $deadlineInvoice,
                    'Payment was recorded at or after the capacity guarantee deadline. Do not provision; refund or credit review is required.'
                );
            }

            throw new \RuntimeException(
                'The capacity guarantee deadline expired before payment was initiated.'
            );
        }

        $preflightFailure = collect([
            $this->preflightPaidServiceItems($invoiceId),
            $this->preflightPaidUpgradeItems($invoiceId),
        ])->filter()->implode(' ');
        if ($preflightFailure !== '') {
            $failedInvoice = Invoice::query()->findOrFail($invoiceId);
            if ($deadline->hasInFlightOrSucceededPayment($failedInvoice)) {
                return $deadline->requireAttention(
                    $failedInvoice,
                    "{$preflightFailure} Do not fulfill this paid invoice; refund or account-credit review is required."
                );
            }

            throw new \RuntimeException($preflightFailure);
        }

        self::$coordinatedInvoiceIds[$invoiceId] = (self::$coordinatedInvoiceIds[$invoiceId] ?? 0) + 1;

        try {
            return DB::transaction(function () use ($invoiceId, $deadline) {
                $lockedInvoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                if ($deadline->deadlineExpired($lockedInvoice)) {
                    if ($deadline->hasInFlightOrSucceededPayment($lockedInvoice)) {
                        return $deadline->requireAttention(
                            $lockedInvoice,
                            'Payment was recorded at or after the capacity guarantee deadline. Do not provision; refund or credit review is required.'
                        );
                    }

                    throw new \RuntimeException(
                        'The capacity guarantee deadline expired before payment was initiated.'
                    );
                }
                if ($lockedInvoice->status === Invoice::STATUS_PAID) {
                    return $lockedInvoice;
                }
                if ($lockedInvoice->status !== Invoice::STATUS_PENDING) {
                    throw new \RuntimeException(
                        "A {$lockedInvoice->status} invoice cannot be marked paid."
                    );
                }

                $lockedInvoice->status = Invoice::STATUS_PAID;
                $lockedInvoice->save();

                return $lockedInvoice->fresh();
            }, 5);
        } finally {
            self::$coordinatedInvoiceIds[$invoiceId]--;
            if (self::$coordinatedInvoiceIds[$invoiceId] === 0) {
                unset(self::$coordinatedInvoiceIds[$invoiceId]);
            }
        }
    }

    private function preflightPaidServiceItems(int $invoiceId): ?string
    {
        $invoice = Invoice::query()->with('items')->findOrFail($invoiceId);
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return null;
        }

        $fulfillment = app(DurableFulfillmentService::class);
        $failures = [];
        foreach (
            $invoice->items->where('reference_type', Service::class)
                ->sortBy(fn ($item): int => (int) $item->reference_id)
                as $item
        ) {
            $service = Service::query()
                ->with('product.configOptions')
                ->find($item->reference_id);
            if ($service === null) {
                $failures[] =
                    "Paid invoice item {$item->id} references missing service {$item->reference_id}.";

                continue;
            }

            $failure = $fulfillment->preflightPaidService(
                $service,
                $invoice
            );
            if (is_string($failure) && $failure !== '') {
                $failures[] = $failure;
            }
        }

        return $failures === []
            ? null
            : implode(' ', array_values(array_unique($failures)));
    }

    private function preflightPaidUpgradeItems(int $invoiceId): ?string
    {
        $invoice = Invoice::query()->with('items')->findOrFail($invoiceId);
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return null;
        }

        $upgradeReservationClass =
            'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        $coordinatorAvailable = class_exists($upgradeReservationClass)
            && method_exists(
                $upgradeReservationClass,
                'preflightPaidUpgrade'
            );
        $identity = app(CapacityUpgradeReservationIdentity::class);
        $failures = [];
        $hasPaymentEvidence = app(
            CapacityInvoicePaymentService::class
        )->hasInFlightOrSucceededPayment($invoice);

        foreach (
            $invoice->items->where(
                'reference_type',
                ServiceUpgrade::class
            )->sortBy(fn ($item): int => (int) $item->reference_id)
            as $item
        ) {
            $upgrade = ServiceUpgrade::query()->find($item->reference_id);
            if ($upgrade === null) {
                $failures[] =
                    "Paid invoice item {$item->id} references missing service upgrade {$item->reference_id}.";

                continue;
            }

            if (! $identity->requiresCoordinator($upgrade)) {
                // A genuine legacy/non-dynamic upgrade retains Paymenter's
                // existing invoice processing path.
                continue;
            }

            if (! $coordinatorAvailable) {
                $upgrade->forceFill([
                    'status' => $hasPaymentEvidence
                        ? ServiceUpgrade::STATUS_NEEDS_ATTENTION
                        : ServiceUpgrade::STATUS_CANCELLED,
                    'active_service_guard_id' => $hasPaymentEvidence
                        ? $upgrade->service_id
                        : null,
                    'last_error' => $hasPaymentEvidence
                        ? 'The reservation coordinator was unavailable when payment was recorded.'
                        : 'The reservation coordinator was unavailable before payment.',
                    'failed_at' => now(),
                ])->save();
                $failures[] =
                    "Capacity-backed service upgrade {$upgrade->id} cannot be paid because its reservation coordinator is unavailable.";

                continue;
            }

            $failure = app($upgradeReservationClass)->preflightPaidUpgrade(
                $upgrade,
                $invoice
            );
            if (is_string($failure) && $failure !== '') {
                $failures[] = $failure;
            }
        }

        return $failures === []
            ? null
            : implode(' ', array_values(array_unique($failures)));
    }
}
