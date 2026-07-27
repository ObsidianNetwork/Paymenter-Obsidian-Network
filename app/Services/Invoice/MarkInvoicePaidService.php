<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\DurableFulfillmentService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
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
        $entryTransactionLevel = DB::transactionLevel();

        self::$coordinatedInvoiceIds[$invoiceId] = (self::$coordinatedInvoiceIds[$invoiceId] ?? 0) + 1;

        try {
            $result = DB::transaction(function () use (
                $invoiceId,
                $deadline
            ): array {
                $lockedInvoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $recoveryReason = $deadline->paymentEvidenceRecoveryReason(
                    $invoiceId
                );
                if ($recoveryReason !== null) {
                    return [
                        'invoice' => $deadline->requireAttention(
                            $lockedInvoice,
                            $recoveryReason
                        ),
                        'failure' => null,
                    ];
                }
                $deadline->assertPaymentAttemptAllowed($lockedInvoice);
                if ($deadline->deadlineExpired($lockedInvoice)) {
                    if ($deadline->hasInFlightOrSucceededPayment($lockedInvoice)) {
                        return [
                            'invoice' => $deadline->requireAttention(
                                $lockedInvoice,
                                'Payment was recorded at or after the capacity guarantee deadline. Do not provision; refund or credit review is required.'
                            ),
                            'failure' => null,
                        ];
                    }

                    throw new \RuntimeException(
                        'The capacity guarantee deadline expired before payment was initiated.'
                    );
                }
                if ($lockedInvoice->status === Invoice::STATUS_PAID) {
                    return [
                        'invoice' => $lockedInvoice,
                        'failure' => null,
                    ];
                }
                if ($lockedInvoice->status !== Invoice::STATUS_PENDING) {
                    throw new \RuntimeException(
                        "A {$lockedInvoice->status} invoice cannot be marked paid."
                    );
                }

                $processor = app(ProcessPaidInvoiceService::class);
                $scope = $processor->lockFulfillmentObligations(
                    $lockedInvoice
                );

                // These proofs run only after the global fulfillment lock set
                // is held. Their locks and reads remain valid through the paid
                // transition and the synchronous fulfillment observer.
                $preflightFailure = $this->preflightLockedScope(
                    $lockedInvoice,
                    $scope
                );
                if ($preflightFailure !== '') {
                    return $this->failedPreflightResult(
                        $lockedInvoice,
                        $preflightFailure,
                        $deadline
                    );
                }

                $this->beforePaidTransition($lockedInvoice);

                try {
                    // Keep the observer-driven fulfillment commit behind a
                    // savepoint. If a final immutable proof detects drift, roll
                    // back the tentative paid transition and every partial
                    // fulfillment mutation, then persist the failure outside
                    // this savepoint while the global locks remain held.
                    DB::transaction(function () use ($lockedInvoice): void {
                        $lockedInvoice->status = Invoice::STATUS_PAID;
                        $lockedInvoice->save();
                    });
                } catch (\Throwable $commitException) {
                    $lockedInvoice->refresh();
                    if ($lockedInvoice->status === Invoice::STATUS_PENDING) {
                        $scope = $processor->lockFulfillmentObligations(
                            $lockedInvoice
                        );
                        $commitFailure = $this->preflightLockedScope(
                            $lockedInvoice,
                            $scope
                        );
                        if ($commitFailure !== '') {
                            return $this->failedPreflightResult(
                                $lockedInvoice,
                                $commitFailure,
                                $deadline
                            );
                        }
                    }

                    throw $commitException;
                }

                return [
                    'invoice' => $lockedInvoice->fresh(),
                    'failure' => null,
                ];
            }, 5);
        } finally {
            self::$coordinatedInvoiceIds[$invoiceId]--;
            if (self::$coordinatedInvoiceIds[$invoiceId] === 0) {
                unset(self::$coordinatedInvoiceIds[$invoiceId]);
            }
        }

        if (
            is_string($result['failure'])
            && $entryTransactionLevel === 0
        ) {
            // The transaction above has committed the cancelled/failed
            // lifecycle before a true top-level caller sees the error. A caller
            // that already owns a transaction receives the terminal invoice
            // instead; throwing there would roll the durable failure back.
            throw new \RuntimeException($result['failure']);
        }

        return $result['invoice'];
    }

    /**
     * @param  array{items: mixed, services: mixed, upgrades: mixed}  $scope
     */
    private function preflightLockedScope(
        Invoice $invoice,
        array $scope
    ): string {
        return collect([
            $this->preflightPaidServiceItems(
                $invoice,
                $scope['items'],
                $scope['services']
            ),
            $this->preflightPaidUpgradeItems(
                $invoice,
                $scope['items'],
                $scope['upgrades']
            ),
        ])->filter()->implode(' ');
    }

    private function failedPreflightResult(
        Invoice $invoice,
        string $failure,
        CapacityInvoicePaymentService $payments
    ): array {
        $hasPaymentEvidence = $payments->hasInFlightOrSucceededPayment(
            $invoice
        );
        if ($hasPaymentEvidence) {
            $invoice = $payments->requireAttention(
                $invoice,
                "{$failure} Do not fulfill this paid invoice; refund or account-credit review is required."
            );
        }

        // Never throw while a caller-owned transaction is open. Returning lets
        // external evidence and needs-attention state commit atomically.
        return [
            'invoice' => $invoice->fresh(),
            'failure' => $hasPaymentEvidence ? null : $failure,
        ];
    }

    private function preflightPaidServiceItems(
        Invoice $invoice,
        $items,
        $services
    ): ?string {
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return null;
        }

        $fulfillment = app(DurableFulfillmentService::class);
        $failures = [];
        foreach (
            $items->where('reference_type', Service::class)
                ->sortBy(fn ($item): int => (int) $item->reference_id) as $item
        ) {
            $service = $services->firstWhere(
                'id',
                (int) $item->reference_id
            );
            if ($service === null) {
                $failures[] =
                    "Paid invoice item {$item->id} references missing service {$item->reference_id}.";

                continue;
            }
            $service->loadMissing('product.configOptions');

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

    private function preflightPaidUpgradeItems(
        Invoice $invoice,
        $items,
        $upgrades
    ): ?string {
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
            $items->where(
                'reference_type',
                ServiceUpgrade::class
            )->sortBy(fn ($item): int => (int) $item->reference_id) as $item
        ) {
            $upgrade = $upgrades->firstWhere(
                'id',
                (int) $item->reference_id
            );
            if ($upgrade === null) {
                $failures[] =
                    "Paid invoice item {$item->id} references missing service upgrade {$item->reference_id}.";

                continue;
            }

            if (!$identity->requiresCoordinator($upgrade)) {
                $failure = app(ServiceUpgradeService::class)
                    ->preflightPaidNonCapacityUpgrade(
                        $upgrade,
                        $invoice,
                        $hasPaymentEvidence
                    );
                if (is_string($failure) && $failure !== '') {
                    $failures[] = $failure;
                }

                continue;
            }

            if (!$coordinatorAvailable) {
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
                ]);
                ServiceUpgradeMutationCoordinator::save($upgrade);
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

    /**
     * Test seam for a deterministic mutation after preflight but before the
     * observer-driven commit. Production callers intentionally do nothing.
     */
    protected function beforePaidTransition(Invoice $invoice): void
    {
        //
    }
}
