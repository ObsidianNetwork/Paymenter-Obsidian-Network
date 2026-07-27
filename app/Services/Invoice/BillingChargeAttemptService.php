<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Exceptions\IndeterminateBillingChargeException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\ProcessBillingChargeAttemptJob;
use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class BillingChargeAttemptService
{
    private const BATCH_SIZE = 100;

    private const LEASE_MINUTES = 5;

    private const MAX_ATTEMPTS = 8;

    /** @var list<int> */
    private const RETRY_DELAYS = [
        60,
        300,
        900,
        1800,
        3600,
        10800,
        21600,
    ];

    /** @var array<int, int> */
    private static array $executionDepth = [];

    /** @var array<int, int> */
    private static array $evidenceDepth = [];

    public function createForRenewal(
        Invoice $invoice,
        BillingAgreement $billingAgreement
    ): BillingChargeAttempt {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'A renewal billing charge attempt must be created in the renewal invoice transaction.'
            );
        }

        $invoice = Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();
        $invoice->load(['items', 'transactions']);
        $serviceItem = $invoice->items
            ->where('reference_type', Service::class)
            ->values();
        if (
            $invoice->items->count() !== 1
            || $serviceItem->count() !== 1
        ) {
            throw new \RuntimeException(
                'An automatic saved-method charge must belong to exactly one renewal service line.'
            );
        }
        $service = Service::query()
            ->whereKey($serviceItem->first()->reference_id)
            ->lockForUpdate()
            ->firstOrFail();
        $billingAgreement = BillingAgreement::withTrashed()
            ->whereKey($billingAgreement->id)
            ->lockForUpdate()
            ->firstOrFail();
        $gateway = Gateway::withTrashed()
            ->whereKey($billingAgreement->gateway_id)
            ->lockForUpdate()
            ->firstOrFail();
        $gateway->load('settings');

        $this->assertRenewalChargeIdentity(
            $invoice,
            $billingAgreement,
            $gateway,
            $service
        );
        $attempt = $this->findOrCreateAttemptLocked(
            $invoice,
            $billingAgreement,
            $gateway,
            BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL
        );

        $attemptId = (int) $attempt->id;
        DB::afterCommit(
            fn () => $this->dispatchSafely($attemptId)
        );

        return $attempt;
    }

    /**
     * Freeze a customer-initiated saved-method charge before any provider
     * request. The invoice row serializes concurrent clicks and webhook
     * settlement, while the unique invoice claim makes retries reuse the
     * exact same provider idempotency key.
     */
    public function createForManualPayment(
        Invoice $invoice,
        BillingAgreement $billingAgreement
    ): BillingChargeAttempt {
        if (!Schema::hasTable('billing_charge_attempts')) {
            throw new \RuntimeException(
                'Durable saved-method charging is not installed.'
            );
        }

        return DB::transaction(function () use (
            $invoice,
            $billingAgreement
        ): BillingChargeAttempt {
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoice->load(['items', 'transactions']);
            $billingAgreement = BillingAgreement::withTrashed()
                ->whereKey($billingAgreement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $gateway = Gateway::withTrashed()
                ->whereKey($billingAgreement->gateway_id)
                ->lockForUpdate()
                ->firstOrFail();
            $gateway->load('settings');

            $this->assertManualChargeIdentity(
                $invoice,
                $billingAgreement,
                $gateway
            );

            $capacityPayments = app(
                CapacityInvoicePaymentService::class
            );
            if ($capacityPayments->deadlineExpired($invoice)) {
                throw new \RuntimeException(
                    'This invoice can no longer be paid because its capacity guarantee expired.'
                );
            }
            $attempt = BillingChargeAttempt::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();
            if ($attempt !== null) {
                $providerCustomerReference =
                    ExtensionHelper::billingAttemptProviderCustomerReference(
                        $gateway,
                        $billingAgreement
                    );
                $amount = $this->positiveRemainingAmount($invoice);
                $purpose = in_array($attempt->purpose, [
                    BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
                    BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD,
                ], true)
                    ? (string) $attempt->purpose
                    : BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD;
                $this->assertAttemptMatches(
                    $attempt,
                    $invoice,
                    $billingAgreement,
                    $gateway,
                    $amount,
                    $providerCustomerReference,
                    $purpose
                );

                return $attempt;
            }

            $capacityPayments->assertPaymentAttemptAllowed($invoice);
            if (
                !ExtensionHelper::supportsCustomerInitiatedBillingAttempts(
                    $gateway
                )
            ) {
                throw new \RuntimeException(
                    'This gateway cannot safely complete a customer-initiated saved-method charge.'
                );
            }
            if ($invoice->transactions()
                ->where(
                    'status',
                    InvoiceTransactionStatus::Processing->value
                )
                ->exists()
            ) {
                throw new \RuntimeException(
                    'This invoice already has a provider payment in progress.'
                );
            }

            return $this->findOrCreateAttemptLocked(
                $invoice,
                $billingAgreement,
                $gateway,
                BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD
            );
        }, 5);
    }

    public function hasAttempt(Invoice|int $invoice): bool
    {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;

        return $invoiceId > 0
            && BillingChargeAttempt::query()
                ->where('invoice_id', $invoiceId)
                ->exists();
    }

    public function hasNonterminalAttempt(Invoice|int $invoice): bool
    {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;

        return $invoiceId > 0
            && BillingChargeAttempt::query()
                ->where('invoice_id', $invoiceId)
                ->whereIn(
                    'status',
                    BillingChargeAttempt::nonterminalStatuses()
                )
                ->exists();
    }

    public function shouldCoordinateProviderEvidence(
        int $invoiceId,
        ?int $expectedAttemptId = null,
        ?int $gatewayId = null,
        ?string $transactionId = null
    ): bool {
        if (
            $this->isRecordingEvidence($invoiceId)
            || !Schema::hasTable('billing_charge_attempts')
        ) {
            return false;
        }
        $attempt = BillingChargeAttempt::query()
            ->where('invoice_id', $invoiceId)
            ->first();
        if ($attempt === null) {
            return false;
        }
        if (
            $expectedAttemptId !== null
            || in_array($attempt->status, [
                ...BillingChargeAttempt::nonterminalStatuses(),
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
            ], true)
        ) {
            return true;
        }

        return $gatewayId === (int) $attempt->gateway_snapshot_id
            && $transactionId !== null
            && trim($transactionId) !== ''
            && $attempt->provider_transaction_id !== null
            && hash_equals(
                (string) $attempt->provider_transaction_id,
                $transactionId
            );
    }

    public function assertNewPaymentAttemptAllowed(
        Invoice|int $invoice
    ): void {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        if (
            $invoiceId <= 0
            || $this->isExecuting($invoiceId)
            || $this->isRecordingEvidence($invoiceId)
            || !$this->hasNonterminalAttempt($invoiceId)
        ) {
            return;
        }

        throw new \RuntimeException(
            'This invoice already has an automatic saved-payment-method charge in progress.'
        );
    }

    public function assertInvoiceLifecycleMutable(
        Invoice|int $invoice
    ): void {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return;
        }
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        $query = BillingChargeAttempt::query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', [
                ...BillingChargeAttempt::nonterminalStatuses(),
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
            ]);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $attempt = $query->first();
        if ($attempt !== null) {
            throw new \RuntimeException(
                "Invoice {$invoiceId} has a {$attempt->status} saved-method charge and cannot be cancelled or terminated."
            );
        }
    }

    public function assertBillingAgreementMutable(
        BillingAgreement|int $billingAgreement
    ): void {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return;
        }
        $agreementId = $billingAgreement instanceof BillingAgreement
            ? (int) $billingAgreement->id
            : (int) $billingAgreement;
        $attempt = BillingChargeAttempt::query()
            ->where('billing_agreement_snapshot_id', $agreementId)
            ->whereIn(
                'status',
                BillingChargeAttempt::nonterminalStatuses()
            )
            ->orderBy('id')
            ->first();
        if ($attempt !== null) {
            throw new \RuntimeException(
                "Payment method {$agreementId} is pinned by billing charge attempt {$attempt->id} ({$attempt->status})."
            );
        }
    }

    public function assertGatewayMutable(int $gatewayId): void
    {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return;
        }
        $attempt = BillingChargeAttempt::query()
            ->where('gateway_snapshot_id', $gatewayId)
            ->whereIn('status', [
                ...BillingChargeAttempt::nonterminalStatuses(),
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
            ])
            ->orderBy('id')
            ->first();
        if ($attempt !== null) {
            throw new \RuntimeException(
                "Gateway {$gatewayId} is pinned by billing charge attempt {$attempt->id} ({$attempt->status})."
            );
        }
    }

    public function assertServiceTerminationAllowed(
        Service|int $service
    ): void {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return;
        }
        $serviceId = $service instanceof Service
            ? (int) $service->id
            : (int) $service;
        $attempt = BillingChargeAttempt::query()
            ->whereIn('status', [
                ...BillingChargeAttempt::nonterminalStatuses(),
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
            ])
            ->whereHas('invoice.items', function ($query) use (
                $serviceId
            ): void {
                $query->where('reference_type', Service::class)
                    ->where('reference_id', $serviceId);
            })
            ->orderBy('id')
            ->first();
        if ($attempt !== null) {
            throw new \RuntimeException(
                "Service {$serviceId} cannot be terminated while billing charge attempt {$attempt->id} is {$attempt->status}."
            );
        }
    }

    public function chargeSavedMethod(
        Invoice $invoice,
        BillingAgreement $billingAgreement
    ): bool {
        $attempt = Schema::hasTable('billing_charge_attempts')
            ? BillingChargeAttempt::query()
                ->where('invoice_id', $invoice->id)
                ->first()
            : null;
        if ($attempt === null) {
            return false;
        }
        if (
            (int) $attempt->billing_agreement_snapshot_id
                !== (int) $billingAgreement->id
            || (int) $attempt->gateway_snapshot_id
                !== (int) $billingAgreement->gateway_id
            || !hash_equals(
                (string) $attempt->billing_agreement_reference,
                (string) $billingAgreement->external_reference
            )
        ) {
            throw new DisplayException(
                'This renewal already has a charge tied to its original saved payment method. Wait for it to finish or use a different payment type.'
            );
        }
        if ($attempt->status === BillingChargeAttempt::STATUS_SUCCEEDED) {
            return true;
        }
        if (in_array($attempt->status, [
            BillingChargeAttempt::STATUS_FAILED,
            BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
        ], true)) {
            throw new DisplayException(
                'The automatic saved-method charge cannot be retried from this screen. Use another payment type or contact support.'
            );
        }

        $this->process((int) $attempt->id);
        $attempt->refresh();

        return in_array($attempt->status, [
            BillingChargeAttempt::STATUS_SUCCEEDED,
            BillingChargeAttempt::STATUS_PROVIDER_PENDING,
            BillingChargeAttempt::STATUS_LEASED,
            BillingChargeAttempt::STATUS_RETRYABLE,
        ], true);
    }

    /**
     * @return array{scanned: int, dispatched: int, skipped: int, failed: int}
     */
    public function recover(): array
    {
        $summary = [
            'scanned' => 0,
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        if (!Schema::hasTable('billing_charge_attempts')) {
            return $summary;
        }

        BillingChargeAttempt::query()
            ->whereIn(
                'status',
                BillingChargeAttempt::nonterminalStatuses()
            )
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', '!=',
                        BillingChargeAttempt::STATUS_LEASED)
                        ->where(function ($query): void {
                            $query->whereNull('available_at')
                                ->orWhere('available_at', '<=', now());
                        });
                })->orWhere(function ($query): void {
                    $query->where(
                        'status',
                        BillingChargeAttempt::STATUS_LEASED
                    )->where(function ($query): void {
                        $query->whereNull('lease_expires_at')
                            ->orWhere('lease_expires_at', '<=', now());
                    });
                });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(
                self::BATCH_SIZE,
                function ($attempts) use (&$summary): void {
                    foreach ($attempts as $attempt) {
                        $summary['scanned']++;
                        try {
                            if ($this->dispatchById((int) $attempt->id)) {
                                $summary['dispatched']++;
                            } else {
                                $summary['skipped']++;
                            }
                        } catch (Throwable $exception) {
                            $summary['failed']++;
                            Log::error(
                                'Failed to recover a billing charge attempt.',
                                [
                                    'billing_charge_attempt_id' => (int) $attempt->id,
                                    'exception' => $exception,
                                ]
                            );
                        }
                    }
                }
            );

        return $summary;
    }

    public function dispatchById(int $attemptId): bool
    {
        $attempt = BillingChargeAttempt::query()
            ->whereKey($attemptId)
            ->whereIn(
                'status',
                BillingChargeAttempt::nonterminalStatuses()
            )
            ->first();
        if (
            $attempt === null
            || (
                $attempt->status === BillingChargeAttempt::STATUS_LEASED
                && $attempt->lease_expires_at?->isFuture()
            )
            || (
                $attempt->status !== BillingChargeAttempt::STATUS_LEASED
                && $attempt->available_at?->isFuture()
            )
        ) {
            return false;
        }

        $job = new ProcessBillingChargeAttemptJob($attemptId);
        $uniqueLock = new UniqueLock(app('cache')->store());
        if (!$uniqueLock->acquire($job)) {
            return false;
        }

        try {
            Queue::push($job);
        } catch (Throwable $exception) {
            $this->releaseUniqueLock($uniqueLock, $job);
            BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->whereIn(
                    'status',
                    BillingChargeAttempt::nonterminalStatuses()
                )
                ->update([
                    'available_at' => now()->addMinute(),
                    'last_error' => mb_substr(
                        $exception->getMessage(),
                        0,
                        65535
                    ),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return true;
    }

    public function process(int $attemptId): bool
    {
        $lease = $this->acquireLease($attemptId);
        if ($lease === null) {
            return false;
        }

        /** @var BillingChargeAttempt $attempt */
        $attempt = $lease['attempt'];
        $leaseToken = $lease['lease_token'];
        try {
            $outcome = $this->coordinatedExecution(
                (int) $attempt->invoice_id,
                fn (): array => ExtensionHelper::chargeBillingAttempt(
                    $attempt
                )
            );
        } catch (IndeterminateBillingChargeException $exception) {
            $this->markAttention(
                $attemptId,
                $leaseToken,
                $exception->getMessage()
            );

            return false;
        } catch (Throwable $exception) {
            $this->recordFailure(
                $attemptId,
                $leaseToken,
                $exception
            );

            return false;
        }

        try {
            $this->recordProviderOutcome(
                $attemptId,
                $leaseToken,
                $outcome
            );
        } catch (Throwable $exception) {
            $this->markAttention(
                $attemptId,
                null,
                'The provider returned a charge result, but its local payment evidence could not be reconciled: '
                    . mb_substr($exception->getMessage(), 0, 2000)
            );

            return false;
        }

        return true;
    }

    /**
     * @param  array{
     *   provider_reference: string,
     *   provider_transaction_id: string|null,
     *   provider_status: string,
     *   evidence_status: string,
     *   fee?: string|null,
     *   message?: string|null
     * }  $outcome
     */
    private function recordProviderOutcome(
        int $attemptId,
        string $leaseToken,
        array $outcome
    ): void {
        $outcome = $this->validateOutcome($outcome);
        $snapshot = DB::transaction(function () use (
            $attemptId,
            $leaseToken,
            $outcome
        ): array {
            $identity = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->first(['invoice_id']);
            if ($identity === null) {
                throw new \RuntimeException(
                    'The billing charge attempt disappeared.'
                );
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $attempt->status !== BillingChargeAttempt::STATUS_LEASED
            ) {
                $sameReference =
                    $attempt->provider_reference !== null
                    && hash_equals(
                        (string) $attempt->provider_reference,
                        $outcome['provider_reference']
                    );
                $sameTransaction =
                    $attempt->provider_transaction_id !== null
                    && $outcome['provider_transaction_id'] !== null
                    && hash_equals(
                        (string) $attempt->provider_transaction_id,
                        $outcome['provider_transaction_id']
                    );
                $referenceConflict =
                    $attempt->provider_reference !== null
                    && !$sameReference;
                $transactionConflict =
                    $attempt->provider_transaction_id !== null
                    && $outcome['provider_transaction_id'] !== null
                    && !$sameTransaction;
                if (
                    ($sameReference || $sameTransaction)
                    && ($referenceConflict || $transactionConflict)
                ) {
                    $reason = 'A provider callback and charge response disagree about the saved-method charge resource or transaction identity.';
                    $this->markAttentionLocked(
                        $invoice,
                        $attempt,
                        $reason,
                        $outcome['provider_status']
                    );

                    return $this->evidenceSnapshot(
                        $attempt,
                        [
                            ...$outcome,
                            'evidence_status' => 'attention',
                            'message' => $reason,
                        ]
                    );
                }
                if ($sameReference || $sameTransaction) {
                    // A verified callback can commit before the HTTP request
                    // that created or retrieved the same provider object
                    // returns. The callback already recorded the authoritative
                    // payment state, so the late response is a read-only
                    // reconciliation rather than a status downgrade.
                    if (
                        $attempt->provider_reference === null
                        || (
                            in_array(
                                $attempt->status,
                                BillingChargeAttempt::nonterminalStatuses(),
                                true
                            )
                            && (int) $attempt->attempt_count !== 0
                        )
                    ) {
                        $repair = [];
                        if ($attempt->provider_reference === null) {
                            $repair['provider_reference'] =
                                $outcome['provider_reference'];
                        }
                        if (
                            in_array(
                                $attempt->status,
                                BillingChargeAttempt::nonterminalStatuses(),
                                true
                            )
                        ) {
                            $repair['attempt_count'] = 0;
                        }
                        $attempt->forceFill($repair)->save();
                    }

                    return [
                        'already_recorded' => true,
                    ];
                }
            }
            $ownsReconciliationLease =
                in_array($attempt->status, [
                    BillingChargeAttempt::STATUS_LEASED,
                    BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                ], true)
                && $attempt->lease_token !== null
                && hash_equals(
                    (string) $attempt->lease_token,
                    $leaseToken
                );
            if (!$ownsReconciliationLease) {
                throw new \RuntimeException(
                    'The billing charge lease no longer owns this provider result.'
                );
            }
            if (
                $attempt->provider_reference !== null
                && !hash_equals(
                    (string) $attempt->provider_reference,
                    $outcome['provider_reference']
                )
            ) {
                $reason = 'The same billing idempotency key resolved to a different provider resource. Reconcile both provider objects before changing the invoice.';
                $this->markAttentionLocked(
                    $invoice,
                    $attempt,
                    $reason,
                    $outcome['provider_status']
                );

                return $this->evidenceSnapshot(
                    $attempt,
                    [
                        ...$outcome,
                        'evidence_status' => 'attention',
                        'message' => $reason,
                    ]
                );
            }

            $attempt->forceFill([
                'provider_reference' => $outcome['provider_reference'],
                'provider_transaction_id' => $outcome['provider_transaction_id']
                        ?? $attempt->provider_transaction_id,
                'provider_status' => $outcome['provider_status'],
                'provider_payload' => [
                    'provider_reference' => $outcome['provider_reference'],
                    'provider_transaction_id' => $outcome['provider_transaction_id'],
                    'provider_status' => $outcome['provider_status'],
                    'evidence_status' => $outcome['evidence_status'],
                ],
                'status' => BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                'attempt_count' => 0,
                'lease_token' => null,
                'lease_expires_at' => null,
                'available_at' => now()->addMinute(),
                'last_error' => $outcome['message'],
            ])->save();

            return $this->evidenceSnapshot($attempt, $outcome);
        }, 5);

        if (($snapshot['already_recorded'] ?? false) === true) {
            return;
        }

        $gateway = Gateway::withTrashed()
            ->whereKey($snapshot['gateway_id'])
            ->firstOrFail();
        $arguments = [
            $snapshot['invoice_id'],
            $gateway,
            $snapshot['amount'],
            $snapshot['fee'],
            $snapshot['provider_transaction_id'],
        ];
        match ($snapshot['evidence_status']) {
            'succeeded' => ExtensionHelper::addPayment(
                ...$arguments,
                billingChargeAttemptId: $attemptId
            ),
            'processing' => ExtensionHelper::addProcessingPayment(
                ...$arguments,
                billingChargeAttemptId: $attemptId
            ),
            'failed' => ExtensionHelper::addFailedPayment(
                ...$arguments,
                billingChargeAttemptId: $attemptId
            ),
            'attention' => $this->markAttention(
                $attemptId,
                null,
                $snapshot['message']
                    ?? 'The provider returned a charge state that requires operator reconciliation.'
            ),
        };
    }

    /**
     * Coordinate a gateway webhook or synchronous provider result with the
     * immutable attempt. The nested payment coordinator records the invoice
     * transaction; this outer invoice -> attempt lock makes the attempt state
     * and payment evidence one atomic commit.
     */
    public function recordProviderEvidence(
        int $invoiceId,
        ?int $gatewayId,
        mixed $amount,
        ?string $transactionId,
        InvoiceTransactionStatus $status,
        ?int $expectedAttemptId,
        Closure $persist
    ): mixed {
        if (
            !Schema::hasTable('billing_charge_attempts')
            || $this->isRecordingEvidence($invoiceId)
        ) {
            return $persist();
        }
        $identity = BillingChargeAttempt::query()
            ->where('invoice_id', $invoiceId)
            ->first(['id']);
        if ($identity === null) {
            return $persist();
        }

        self::$evidenceDepth[$invoiceId] =
            (self::$evidenceDepth[$invoiceId] ?? 0) + 1;
        try {
            return DB::transaction(function () use (
                $amount,
                $expectedAttemptId,
                $gatewayId,
                $identity,
                $invoiceId,
                $persist,
                $status,
                $transactionId
            ): mixed {
                $invoice = Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $attempt = BillingChargeAttempt::query()
                    ->whereKey($identity->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (
                    $expectedAttemptId !== null
                    && (int) $attempt->id !== $expectedAttemptId
                ) {
                    throw new \RuntimeException(
                        'Provider payment metadata references the wrong billing charge attempt.'
                    );
                }
                $superseded = $this
                    ->supersededProviderEvidenceAlreadyRecorded(
                        $invoice,
                        $gatewayId,
                        $transactionId,
                        $amount,
                        $status
                    );
                if ($superseded !== null) {
                    return $superseded;
                }
                if ($this->exactProviderEvidenceAlreadyRecorded(
                    $invoice,
                    $gatewayId,
                    $transactionId,
                    $amount,
                    $status
                )) {
                    return $persist();
                }

                $result = $persist();
                if (!$result instanceof InvoiceTransaction) {
                    throw new \RuntimeException(
                        'Provider payment evidence did not return an invoice transaction.'
                    );
                }
                $reason = $this->incomingEvidenceAttentionReason(
                    $invoice,
                    $gatewayId,
                    $transactionId,
                    $amount,
                    $status,
                    $expectedAttemptId
                );
                $recoveryReason = app(
                    CapacityInvoicePaymentService::class
                )->paymentEvidenceRecoveryReason($invoiceId);
                $reason ??= $recoveryReason;
                $invoice->refresh();
                if (
                    $reason === null
                    && $invoice->payment_attention_required_at !== null
                ) {
                    $reason = trim(
                        (string) $invoice->payment_attention_reason
                    );
                    if ($reason === '') {
                        $reason = 'The invoice entered manual payment review while provider evidence was being recorded.';
                    }
                }

                $this->reconcileEvidenceLocked(
                    $invoice,
                    $attempt,
                    $result,
                    $status,
                    $reason
                );

                return $result;
            }, 5);
        } finally {
            self::$evidenceDepth[$invoiceId]--;
            if (self::$evidenceDepth[$invoiceId] === 0) {
                unset(self::$evidenceDepth[$invoiceId]);
            }
        }
    }

    public function incomingEvidenceAttentionReason(
        Invoice $invoice,
        ?int $gatewayId,
        ?string $transactionId,
        mixed $amount,
        InvoiceTransactionStatus $status,
        ?int $expectedAttemptId = null
    ): ?string {
        if (
            !Schema::hasTable('billing_charge_attempts')
            || !in_array($status, [
                InvoiceTransactionStatus::Processing,
                InvoiceTransactionStatus::Succeeded,
                InvoiceTransactionStatus::Failed,
            ], true)
        ) {
            return null;
        }
        $query = BillingChargeAttempt::query()
            ->where('invoice_id', $invoice->id);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $attempt = $query->first();
        if ($attempt === null) {
            return null;
        }
        if (
            $attempt->status === BillingChargeAttempt::STATUS_FAILED
            && $expectedAttemptId === null
            && (
                $gatewayId !== (int) $attempt->gateway_snapshot_id
                || $transactionId === null
                || $attempt->provider_transaction_id === null
                || !hash_equals(
                    (string) $attempt->provider_transaction_id,
                    $transactionId
                )
            )
        ) {
            // A definite provider failure or an exhausted network retry frees
            // the invoice for a new customer-selected payment. Only evidence
            // explicitly tied to the frozen attempt, or replaying its exact
            // provider transaction, remains under this coordinator.
            return null;
        }
        if (
            $expectedAttemptId !== null
            && (int) $attempt->id !== $expectedAttemptId
        ) {
            return 'Provider payment metadata references a different saved-method charge attempt.';
        }
        if (
            $expectedAttemptId === null
            && $attempt->provider_transaction_id === null
        ) {
            return 'Provider payment evidence does not identify the frozen saved-method charge attempt.';
        }
        $incomingAmount = $this->canonicalAmount($amount);
        if (
            $gatewayId !== (int) $attempt->gateway_snapshot_id
            || $incomingAmount === null
            || !hash_equals((string) $attempt->amount, $incomingAmount)
            || $transactionId === null
            || trim($transactionId) === ''
        ) {
            return 'Provider payment evidence does not match the frozen saved-method charge amount, gateway, or transaction identity.';
        }
        if (
            $attempt->provider_transaction_id !== null
            && !hash_equals(
                (string) $attempt->provider_transaction_id,
                $transactionId
            )
        ) {
            return 'A distinct second provider payment was reported for an invoice that already owns a saved-method charge. Preserve both payments and perform refund or account-credit review.';
        }

        $existingSucceeded = $invoice->transactions()
            ->where(
                'status',
                InvoiceTransactionStatus::Succeeded->value
            )
            ->where('is_credit_transaction', false)
            ->orderBy('id')
            ->get(['gateway_id', 'transaction_id'])
            ->first(function ($transaction) use (
                $gatewayId,
                $transactionId
            ): bool {
                return (int) $transaction->gateway_id !== $gatewayId
                    || !hash_equals(
                        (string) $transaction->transaction_id,
                        $transactionId
                    );
            });
        if ($existingSucceeded !== null) {
            return 'A distinct second provider payment was reported after this renewal invoice had already settled. Preserve both payments and perform refund or account-credit review.';
        }
        if ($invoice->payment_attention_required_at !== null) {
            $reason = trim(
                (string) $invoice->payment_attention_reason
            );

            return $reason !== ''
                ? $reason
                : 'The saved-method charge produced payment evidence while its invoice was already under manual review.';
        }

        return null;
    }

    public function providerPaymentMethodDeleted(
        BillingAgreement $billingAgreement
    ): void {
        if (!Schema::hasTable('billing_charge_attempts')) {
            $billingAgreement->delete();

            return;
        }

        DB::transaction(function () use ($billingAgreement): void {
            $lockedAgreement = BillingAgreement::withTrashed()
                ->whereKey($billingAgreement->id)
                ->lockForUpdate()
                ->first();
            if (
                $lockedAgreement === null
                || $lockedAgreement->trashed()
            ) {
                return;
            }

            // Pin the agreement before discovering its attempts. Renewal
            // creation takes the same row lock before inserting an attempt,
            // so it cannot appear between this scan and local revocation.
            $attemptIds = BillingChargeAttempt::query()
                ->where(
                    'billing_agreement_snapshot_id',
                    $lockedAgreement->id
                )
                ->whereIn('status', [
                    ...BillingChargeAttempt::nonterminalStatuses(),
                    BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                ])
                ->orderBy('invoice_id')
                ->pluck('id');
            foreach ($attemptIds as $attemptId) {
                $this->recordProviderPaymentMethodDeletion(
                    (int) $attemptId
                );
            }

            $lockedAgreement->delete();
        }, 5);
    }

    private function recordProviderPaymentMethodDeletion(
        int $attemptId
    ): void {
        DB::transaction(function () use ($attemptId): void {
            $identity = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();
            $reason = 'The provider removed this saved payment method while its automatic renewal charge was unresolved.';
            if (
                $attempt->status ===
                    BillingChargeAttempt::STATUS_LEASED
                && $attempt->lease_token !== null
            ) {
                // The network request may already have created a charge. Keep
                // only its evidence-reconciliation lease while preventing any
                // new provider request or lifecycle mutation.
                $attempt->forceFill([
                    'status' => BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                    'provider_status' => 'payment_method_deleted_in_flight',
                    'available_at' => null,
                    'last_error' => $reason,
                    'failed_at' => now(),
                ])->save();
                app(CapacityInvoicePaymentService::class)
                    ->requireAttention($invoice, $reason);

                return;
            }

            $this->markAttentionLocked(
                $invoice,
                $attempt,
                $reason,
                'payment_method_deleted'
            );
        }, 5);
    }

    public function claimUnreportedSettlements(): int
    {
        if (!Schema::hasTable('billing_charge_attempts')) {
            return 0;
        }

        return DB::transaction(function (): int {
            $attempts = BillingChargeAttempt::query()
                ->where('status', BillingChargeAttempt::STATUS_SUCCEEDED)
                ->whereNotNull('settled_at')
                ->whereNull('metric_recorded_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($attempts->isEmpty()) {
                return 0;
            }
            BillingChargeAttempt::query()
                ->whereKey($attempts->modelKeys())
                ->whereNull('metric_recorded_at')
                ->update([
                    'metric_recorded_at' => now(),
                    'updated_at' => now(),
                ]);

            return $attempts->count();
        }, 5);
    }

    private function acquireLease(int $attemptId): ?array
    {
        return DB::transaction(function () use ($attemptId): ?array {
            $identity = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return null;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->first();
            if ($invoice === null) {
                return null;
            }
            $attempt = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->first();
            if (
                $attempt === null
                || !in_array(
                    $attempt->status,
                    BillingChargeAttempt::nonterminalStatuses(),
                    true
                )
                || (
                    $attempt->status ===
                        BillingChargeAttempt::STATUS_LEASED
                    && $attempt->lease_expires_at?->isFuture()
                )
                || (
                    $attempt->status !==
                        BillingChargeAttempt::STATUS_LEASED
                    && $attempt->available_at?->isFuture()
                )
            ) {
                return null;
            }

            if (
                (int) $attempt->attempt_count > 0
                && $attempt->provider_reference === null
                && $attempt->created_at->copy()->addSeconds(
                    $this->idempotencyRetryWindow($attempt)
                )->isPast()
            ) {
                $this->markAttentionLocked(
                    $invoice,
                    $attempt,
                    'The provider charge remains ambiguous beyond its idempotency retention window. Do not create another provider payment; reconcile the provider account manually.',
                    'idempotency_window_expired'
                );

                return null;
            }

            if (!$this->assertAttemptPayableLocked($invoice, $attempt)) {
                return null;
            }
            $leaseToken = (string) Str::uuid();
            $attempt->forceFill([
                'status' => BillingChargeAttempt::STATUS_LEASED,
                'lease_token' => $leaseToken,
                'lease_expires_at' => now()->addMinutes(
                    self::LEASE_MINUTES
                ),
                'attempt_count' => (int) $attempt->attempt_count + 1,
                'last_attempt_at' => now(),
                'available_at' => null,
                'last_error' => null,
            ])->save();

            return [
                'attempt' => $attempt->fresh([
                    'invoice.user.properties',
                    'billingAgreement',
                    'gateway.settings',
                ]),
                'lease_token' => $leaseToken,
            ];
        }, 5);
    }

    private function assertAttemptPayableLocked(
        Invoice $invoice,
        BillingChargeAttempt $attempt
    ): bool {
        if (
            $invoice->status !== Invoice::STATUS_PENDING
            || $invoice->payment_attention_required_at !== null
        ) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                "The invoice became {$invoice->status} or entered payment review before the saved-method network request.",
                'invoice_not_payable'
            );

            return false;
        }
        if (
            app(CapacityInvoicePaymentService::class)
                ->deadlineExpired($invoice)
        ) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                'The capacity guarantee expired before the saved-method provider request could be submitted or reconciled.',
                'capacity_deadline_expired'
            );

            return false;
        }
        $invoice->load(['items', 'transactions']);
        $remaining = $this->canonicalAmount($invoice->remaining);
        if (
            $remaining === null
            || !hash_equals((string) $attempt->amount, $remaining)
            || !hash_equals(
                (string) $attempt->currency_code,
                strtoupper((string) $invoice->currency_code)
            )
        ) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                'The invoice amount or currency changed after the automatic saved-method charge was frozen.',
                'invoice_drift'
            );

            return false;
        }

        $agreement = BillingAgreement::withTrashed()
            ->whereKey($attempt->billing_agreement_snapshot_id)
            ->lockForUpdate()
            ->first();
        $gateway = Gateway::withTrashed()
            ->whereKey($attempt->gateway_snapshot_id)
            ->lockForUpdate()
            ->first();
        if (
            $agreement === null
            || $agreement->trashed()
            || $gateway === null
            || $gateway->trashed()
            || !(bool) $gateway->enabled
            || (int) $agreement->user_id !== (int) $invoice->user_id
            || (int) $agreement->gateway_id
                !== (int) $attempt->gateway_snapshot_id
            || !hash_equals(
                (string) $agreement->external_reference,
                (string) $attempt->billing_agreement_reference
            )
            || !hash_equals(
                (string) $gateway->extension,
                (string) $attempt->gateway_extension
            )
        ) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                'The saved payment method or gateway changed or became unavailable before automatic charging.',
                'payment_method_unavailable'
            );

            return false;
        }

        $conflictingProcessing = $invoice->transactions()
            ->where(
                'status',
                InvoiceTransactionStatus::Processing->value
            )
            ->orderBy('id')
            ->get(['gateway_id', 'transaction_id'])
            ->first(function ($transaction) use ($attempt): bool {
                return $attempt->provider_transaction_id === null
                    || (int) $transaction->gateway_id
                        !== (int) $attempt->gateway_snapshot_id
                    || !hash_equals(
                        (string) $transaction->transaction_id,
                        (string) $attempt->provider_transaction_id
                    );
            });
        if ($conflictingProcessing !== null) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                'Another provider payment entered processing while the automatic saved-method charge was unresolved.',
                'conflicting_payment'
            );

            return false;
        }

        return true;
    }

    private function recordFailure(
        int $attemptId,
        string $leaseToken,
        Throwable $exception
    ): void {
        DB::transaction(function () use (
            $attemptId,
            $exception,
            $leaseToken
        ): void {
            $identity = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->first();
            if ($invoice === null) {
                return;
            }
            $attempt = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->where('status', BillingChargeAttempt::STATUS_LEASED)
                ->where('lease_token', $leaseToken)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                return;
            }
            $terminal = (int) $attempt->attempt_count
                >= self::MAX_ATTEMPTS;
            $delayIndex = min(
                max(0, (int) $attempt->attempt_count - 1),
                count(self::RETRY_DELAYS) - 1
            );
            if ($terminal) {
                $this->markAttentionLocked(
                    $invoice,
                    $attempt,
                    'The saved-method charge exhausted its automatic retries without a verifiable provider result. Do not submit another payment until the provider account has been reconciled. Last error: '
                        . mb_substr(
                            $exception->getMessage(),
                            0,
                            2000
                        ),
                    'provider_retry_exhausted'
                );
                $invoiceId = (int) $attempt->invoice_id;
                DB::afterCommit(
                    fn () => $this->notifyPaymentFailed($invoiceId)
                );

                return;
            }
            $attempt->forceFill([
                'status' => BillingChargeAttempt::STATUS_RETRYABLE,
                'lease_token' => null,
                'lease_expires_at' => null,
                'available_at' => now()->addSeconds(
                    self::RETRY_DELAYS[$delayIndex]
                ),
                'last_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    65535
                ),
                'failed_at' => null,
            ])->save();
        }, 5);
    }

    private function markAttention(
        int $attemptId,
        ?string $leaseToken,
        string $reason
    ): void {
        DB::transaction(function () use (
            $attemptId,
            $leaseToken,
            $reason
        ): void {
            $identity = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt = BillingChargeAttempt::query()
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $leaseToken !== null
                && (
                    $attempt->lease_token === null
                    || !hash_equals(
                        (string) $attempt->lease_token,
                        $leaseToken
                    )
                )
            ) {
                return;
            }
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                $reason,
                'needs_attention'
            );
        }, 5);
    }

    private function markAttentionLocked(
        Invoice $invoice,
        BillingChargeAttempt $attempt,
        string $reason,
        string $providerStatus
    ): void {
        $attempt->forceFill([
            'status' => BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
            'provider_status' => $providerStatus,
            'lease_token' => null,
            'lease_expires_at' => null,
            'available_at' => null,
            'last_error' => mb_substr($reason, 0, 65535),
            'failed_at' => now(),
        ])->save();
        app(CapacityInvoicePaymentService::class)
            ->requireAttention($invoice, $reason);
    }

    private function reconcileEvidenceLocked(
        Invoice $invoice,
        BillingChargeAttempt $attempt,
        InvoiceTransaction $transaction,
        InvoiceTransactionStatus $status,
        ?string $attentionReason
    ): void {
        if ($attentionReason !== null) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                $attentionReason,
                'conflicting_payment_evidence'
            );

            return;
        }

        $transactionId = (string) $transaction->transaction_id;
        if (
            $attempt->provider_transaction_id !== null
            && !hash_equals(
                (string) $attempt->provider_transaction_id,
                $transactionId
            )
        ) {
            $this->markAttentionLocked(
                $invoice,
                $attempt,
                'A distinct second provider transaction conflicts with the frozen saved-method charge.',
                'conflicting_payment_evidence'
            );

            return;
        }

        $targetStatus = match ($status) {
            InvoiceTransactionStatus::Succeeded => BillingChargeAttempt::STATUS_SUCCEEDED,
            InvoiceTransactionStatus::Failed => BillingChargeAttempt::STATUS_FAILED,
            InvoiceTransactionStatus::Processing => BillingChargeAttempt::STATUS_PROVIDER_PENDING,
        };
        $alreadyExact =
            $attempt->status === $targetStatus
            && $attempt->provider_transaction_id !== null
            && hash_equals(
                (string) $attempt->provider_transaction_id,
                $transactionId
            )
            && (
                $status !== InvoiceTransactionStatus::Succeeded
                || $attempt->settled_at !== null
            );
        if ($alreadyExact) {
            return;
        }

        $wasFailed =
            $attempt->status === BillingChargeAttempt::STATUS_FAILED;
        $attempt->forceFill([
            'status' => $targetStatus,
            'attempt_count' => 0,
            'provider_transaction_id' => $transactionId,
            'provider_status' => $status->value,
            'lease_token' => null,
            'lease_expires_at' => null,
            'available_at' => $status === InvoiceTransactionStatus::Processing
                    ? now()->addMinute()
                    : null,
            'last_error' => null,
            'settled_at' => $status === InvoiceTransactionStatus::Succeeded
                    ? ($attempt->settled_at ?? now())
                    : null,
            'failed_at' => $status === InvoiceTransactionStatus::Failed
                    ? ($attempt->failed_at ?? now())
                    : null,
        ])->save();
        if (
            $status === InvoiceTransactionStatus::Failed
            && !$wasFailed
        ) {
            $invoiceId = (int) $invoice->id;
            DB::afterCommit(
                fn () => $this->notifyPaymentFailed($invoiceId)
            );
        }
    }

    private function supersededProviderEvidenceAlreadyRecorded(
        Invoice $invoice,
        ?int $gatewayId,
        ?string $transactionId,
        mixed $amount,
        InvoiceTransactionStatus $incomingStatus
    ): ?InvoiceTransaction {
        if ($transactionId === null || trim($transactionId) === '') {
            return null;
        }
        $canonicalAmount = $this->canonicalAmount($amount);
        if ($canonicalAmount === null) {
            return null;
        }
        $transaction = $invoice->transactions()
            ->where('gateway_id', $gatewayId)
            ->where('transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();
        if (
            $transaction === null
            || !hash_equals(
                (string) $transaction->amount,
                $canonicalAmount
            )
            || (bool) $transaction->is_credit_transaction
        ) {
            return null;
        }
        $recordedStatus = $transaction->status
            instanceof InvoiceTransactionStatus
                ? $transaction->status
                : InvoiceTransactionStatus::tryFrom(
                    (string) $transaction->status
                );
        if (
            $recordedStatus === InvoiceTransactionStatus::Succeeded
            && $incomingStatus !== InvoiceTransactionStatus::Succeeded
        ) {
            return $transaction;
        }
        if (
            $recordedStatus === InvoiceTransactionStatus::Failed
            && $incomingStatus === InvoiceTransactionStatus::Processing
        ) {
            return $transaction;
        }

        return null;
    }

    private function exactProviderEvidenceAlreadyRecorded(
        Invoice $invoice,
        ?int $gatewayId,
        ?string $transactionId,
        mixed $amount,
        InvoiceTransactionStatus $status
    ): bool {
        if ($transactionId === null || trim($transactionId) === '') {
            return false;
        }
        $canonicalAmount = $this->canonicalAmount($amount);
        if ($canonicalAmount === null) {
            return false;
        }

        return $invoice->transactions()
            ->where('gateway_id', $gatewayId)
            ->where('transaction_id', $transactionId)
            ->lockForUpdate()
            ->get()
            ->contains(function (InvoiceTransaction $transaction) use (
                $canonicalAmount,
                $gatewayId,
                $status,
                $transactionId
            ): bool {
                $recordedStatus = $transaction->status
                    instanceof InvoiceTransactionStatus
                        ? $transaction->status
                        : InvoiceTransactionStatus::tryFrom(
                            (string) $transaction->status
                        );

                return (int) $transaction->gateway_id === $gatewayId
                    && hash_equals(
                        (string) $transaction->transaction_id,
                        $transactionId
                    )
                    && hash_equals(
                        (string) $transaction->amount,
                        $canonicalAmount
                    )
                    && !(bool) $transaction->is_credit_transaction
                    && $recordedStatus === $status;
            });
    }

    private function coordinatedExecution(
        int $invoiceId,
        Closure $callback
    ): mixed {
        self::$executionDepth[$invoiceId] =
            (self::$executionDepth[$invoiceId] ?? 0) + 1;
        try {
            return $callback();
        } finally {
            self::$executionDepth[$invoiceId]--;
            if (self::$executionDepth[$invoiceId] === 0) {
                unset(self::$executionDepth[$invoiceId]);
            }
        }
    }

    private function isExecuting(int $invoiceId): bool
    {
        return (self::$executionDepth[$invoiceId] ?? 0) > 0;
    }

    private function isRecordingEvidence(int $invoiceId): bool
    {
        return (self::$evidenceDepth[$invoiceId] ?? 0) > 0;
    }

    private function assertRenewalChargeIdentity(
        Invoice $invoice,
        BillingAgreement $agreement,
        Gateway $gateway,
        Service $service
    ): void {
        $item = $invoice->items->first();
        $itemPrice = $this->canonicalAmount($item?->price);
        $servicePrice = $this->canonicalAmount($service->price);
        if (
            $invoice->status !== Invoice::STATUS_PENDING
            || $invoice->payment_attention_required_at !== null
            || $agreement->trashed()
            || $gateway->trashed()
            || !(bool) $gateway->enabled
            || (int) $agreement->user_id !== (int) $invoice->user_id
            || (int) $agreement->gateway_id !== (int) $gateway->id
            || (int) $service->user_id !== (int) $invoice->user_id
            || (int) $service->billing_agreement_id
                !== (int) $agreement->id
            || $service->status !== Service::STATUS_ACTIVE
            || !hash_equals(
                strtoupper((string) $service->currency_code),
                strtoupper((string) $invoice->currency_code)
            )
            || (int) $item?->reference_id !== (int) $service->id
            || (int) $item?->quantity !== (int) $service->quantity
            || $itemPrice === null
            || $servicePrice === null
            || !hash_equals($servicePrice, $itemPrice)
            || $service->expires_at === null
            || $invoice->due_at === null
            || !$service->expires_at->equalTo($invoice->due_at)
            || trim((string) $agreement->external_reference) === ''
            || preg_match(
                '/^[A-Z]{3}$/D',
                strtoupper((string) $invoice->currency_code)
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The renewal invoice, saved payment method, and gateway are not eligible for automatic charging.'
            );
        }
    }

    private function assertManualChargeIdentity(
        Invoice $invoice,
        BillingAgreement $agreement,
        Gateway $gateway
    ): void {
        if (
            $invoice->status !== Invoice::STATUS_PENDING
            || $invoice->payment_attention_required_at !== null
            || $agreement->trashed()
            || $gateway->trashed()
            || !(bool) $gateway->enabled
            || (int) $agreement->user_id !== (int) $invoice->user_id
            || (int) $agreement->gateway_id !== (int) $gateway->id
            || trim((string) $agreement->external_reference) === ''
            || preg_match(
                '/^[A-Z]{3}$/D',
                strtoupper((string) $invoice->currency_code)
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The invoice, saved payment method, and gateway are not eligible for charging.'
            );
        }
    }

    private function findOrCreateAttemptLocked(
        Invoice $invoice,
        BillingAgreement $billingAgreement,
        Gateway $gateway,
        string $purpose
    ): BillingChargeAttempt {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'A billing charge attempt must be frozen in a database transaction.'
            );
        }
        $providerCustomerReference =
            ExtensionHelper::billingAttemptProviderCustomerReference(
                $gateway,
                $billingAgreement
            );
        $amount = $this->positiveRemainingAmount($invoice);
        $attempt = BillingChargeAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if ($attempt !== null) {
            $this->assertAttemptMatches(
                $attempt,
                $invoice,
                $billingAgreement,
                $gateway,
                $amount,
                $providerCustomerReference,
                $purpose
            );

            return $attempt;
        }

        return BillingChargeAttempt::create([
            'invoice_id' => $invoice->id,
            'billing_agreement_id' => $billingAgreement->id,
            'billing_agreement_snapshot_id' => (int) $billingAgreement->id,
            'billing_agreement_reference' => (string) $billingAgreement->external_reference,
            'gateway_id' => $gateway->id,
            'gateway_snapshot_id' => (int) $gateway->id,
            'gateway_extension' => (string) $gateway->extension,
            'provider_customer_reference' => $providerCustomerReference,
            'purpose' => $purpose,
            'amount' => $amount,
            'currency_code' => strtoupper(
                (string) $invoice->currency_code
            ),
            'idempotency_key' => (string) Str::uuid(),
            'status' => BillingChargeAttempt::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    private function positiveRemainingAmount(Invoice $invoice): string
    {
        $amount = $this->canonicalAmount($invoice->remaining);
        if (
            $amount === null
            || str_starts_with($amount, '-')
            || $amount === '0.00'
        ) {
            throw new \RuntimeException(
                'A saved-method charge requires a positive exact remaining amount.'
            );
        }

        return $amount;
    }

    private function assertAttemptMatches(
        BillingChargeAttempt $attempt,
        Invoice $invoice,
        BillingAgreement $agreement,
        Gateway $gateway,
        string $amount,
        ?string $providerCustomerReference,
        string $purpose
    ): void {
        if (
            (int) $attempt->invoice_id !== (int) $invoice->id
            || (int) $attempt->billing_agreement_snapshot_id
                !== (int) $agreement->id
            || (int) $attempt->gateway_snapshot_id !== (int) $gateway->id
            || !hash_equals(
                (string) $attempt->billing_agreement_reference,
                (string) $agreement->external_reference
            )
            || !hash_equals(
                (string) $attempt->gateway_extension,
                (string) $gateway->extension
            )
            || !hash_equals(
                (string) $attempt->purpose,
                $purpose
            )
            || (
                $attempt->provider_customer_reference !==
                    $providerCustomerReference
                && (
                    $attempt->provider_customer_reference === null
                    || $providerCustomerReference === null
                    || !hash_equals(
                        (string) $attempt
                            ->provider_customer_reference,
                        $providerCustomerReference
                    )
                )
            )
            || !hash_equals((string) $attempt->amount, $amount)
            || !hash_equals(
                (string) $attempt->currency_code,
                strtoupper((string) $invoice->currency_code)
            )
        ) {
            throw new \RuntimeException(
                'The renewal invoice already owns a conflicting saved-method charge attempt.'
            );
        }
    }

    private function validateOutcome(array $outcome): array
    {
        $reference = $outcome['provider_reference'] ?? null;
        $transactionId = $outcome['provider_transaction_id'] ?? null;
        $providerStatus = $outcome['provider_status'] ?? null;
        $evidenceStatus = $outcome['evidence_status'] ?? null;
        if (
            !is_string($reference)
            || trim($reference) === ''
            || !is_string($providerStatus)
            || trim($providerStatus) === ''
            || !is_string($evidenceStatus)
            || !in_array($evidenceStatus, [
                'processing',
                'succeeded',
                'failed',
                'attention',
            ], true)
            || (
                $evidenceStatus !== 'attention'
                && (
                    !is_string($transactionId)
                    || trim($transactionId) === ''
                )
            )
        ) {
            throw new IndeterminateBillingChargeException(
                'The gateway returned an invalid saved-method charge outcome.'
            );
        }

        return [
            'provider_reference' => trim($reference),
            'provider_transaction_id' => is_string($transactionId)
                ? trim($transactionId)
                : null,
            'provider_status' => trim($providerStatus),
            'evidence_status' => $evidenceStatus,
            'fee' => isset($outcome['fee'])
                ? $this->canonicalAmount($outcome['fee'])
                : null,
            'message' => isset($outcome['message'])
                ? mb_substr((string) $outcome['message'], 0, 65535)
                : null,
        ];
    }

    private function evidenceSnapshot(
        BillingChargeAttempt $attempt,
        array $outcome
    ): array {
        return [
            'invoice_id' => (int) $attempt->invoice_id,
            'gateway_id' => (int) $attempt->gateway_snapshot_id,
            'amount' => (string) $attempt->amount,
            'fee' => $outcome['fee'],
            'provider_transaction_id' => $outcome['provider_transaction_id'],
            'evidence_status' => $outcome['evidence_status'],
            'message' => $outcome['message'],
        ];
    }

    private function canonicalAmount(mixed $amount): ?string
    {
        try {
            $transaction = new InvoiceTransaction;
            $transaction->setAttribute('amount', $amount);
            $canonical = $transaction->getAttribute('amount');

            return is_string($canonical)
                && preg_match(
                    '/^-?(?:0|[1-9]\d*)\.\d{2}$/D',
                    $canonical
                ) === 1
                    ? $canonical
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function idempotencyRetryWindow(
        BillingChargeAttempt $attempt
    ): int {
        return match (strtolower($attempt->gateway_extension)) {
            'stripe' => 23 * 60 * 60,
            'paypal' => 5 * 60 * 60,
            default => 60 * 60,
        };
    }

    private function dispatchSafely(int $attemptId): void
    {
        try {
            $this->dispatchById($attemptId);
        } catch (Throwable $exception) {
            Log::error(
                'The renewal invoice committed before its billing charge queue dispatch succeeded; scheduled recovery will retry it.',
                [
                    'billing_charge_attempt_id' => $attemptId,
                    'exception' => $exception,
                ]
            );
        }
    }

    private function releaseUniqueLock(
        UniqueLock $uniqueLock,
        ProcessBillingChargeAttemptJob $job
    ): void {
        try {
            $uniqueLock->release($job);
        } catch (Throwable $exception) {
            Log::warning(
                'Failed to release a billing charge queue uniqueness lock.',
                [
                    'billing_charge_attempt_id' => $job->attemptId,
                    'exception' => $exception,
                ]
            );
        }
    }

    private function notifyPaymentFailed(int $invoiceId): void
    {
        try {
            $invoice = Invoice::query()->find($invoiceId);
            if ($invoice === null || $invoice->user === null) {
                return;
            }

            NotificationHelper::invoicePaymentFailedNotification(
                $invoice->user,
                $invoice
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to send the saved-method charge failure notification.',
                [
                    'invoice_id' => $invoiceId,
                    'exception' => $exception,
                ]
            );
        }
    }
}
