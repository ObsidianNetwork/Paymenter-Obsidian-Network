<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\InvoiceTransaction;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the invoice-wide claim made before an interactive provider payment is
 * created. A single durable row prevents two tabs, two gateways, credits, or
 * a saved method from creating independently capturable provider payments.
 */
class InvoicePaymentInitiationService
{
    /** @var array<int, int> */
    private static array $executionDepth = [];

    /** @var array<int, int> */
    private static array $evidenceDepth = [];

    public function create(
        Invoice $invoice,
        Gateway $gateway
    ): InvoicePaymentInitiation {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            throw new \RuntimeException(
                'Durable provider payment initiation is not installed.'
            );
        }

        return DB::transaction(function () use (
            $invoice,
            $gateway
        ): InvoicePaymentInitiation {
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoice->load(['items', 'transactions']);
            $gateway = Gateway::withTrashed()
                ->whereKey($gateway->id)
                ->lockForUpdate()
                ->firstOrFail();
            $gateway->load('settings');

            $this->assertInvoiceAndGatewayEligible(
                $invoice,
                $gateway
            );
            if (
                !ExtensionHelper::supportsDurablePaymentInitiations($gateway)
            ) {
                throw new DisplayException(
                    'This gateway cannot safely create an invoice payment because it does not provide a provider-enforced idempotent payment identity. Choose a supported payment method.'
                );
            }
            $amount = $this->positiveRemainingAmount($invoice);
            $existing = InvoicePaymentInitiation::query()
                ->where('active_invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertMatches(
                    $existing,
                    $invoice,
                    $gateway,
                    $amount
                );

                return $existing;
            }

            app(BillingChargeAttemptService::class)
                ->assertNewPaymentAttemptAllowed($invoice);
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

            $generation =
                (int) InvoicePaymentInitiation::query()
                    ->where('invoice_id', $invoice->id)
                    ->max('generation')
                + 1;

            return InvoicePaymentInitiation::create([
                'invoice_id' => $invoice->id,
                'active_invoice_id' => $invoice->id,
                'generation' => $generation,
                'gateway_id' => $gateway->id,
                'gateway_snapshot_id' => (int) $gateway->id,
                'gateway_extension' => (string) $gateway->extension,
                'amount' => $amount,
                'currency_code' => strtoupper(
                    (string) $invoice->currency_code
                ),
                'idempotency_key' => (string) Str::uuid(),
                'status' => InvoicePaymentInitiation::STATUS_INITIATING,
                'next_reconcile_at' => now()->addSeconds(
                    $this->reconcileEverySeconds()
                ),
            ]);
        }, 5);
    }

    public function execute(
        InvoicePaymentInitiation $initiation
    ): mixed {
        $acquired = $this->acquire((int) $initiation->id);
        if (isset($acquired['error'])) {
            throw new DisplayException($acquired['error']);
        }

        /** @var InvoicePaymentInitiation $attempt */
        $attempt = $acquired['initiation'];
        $durable = $acquired['durable'];
        try {
            $result = $this->coordinatedExecution(
                (int) $attempt->invoice_id,
                fn () => ExtensionHelper::payInvoiceInitiation(
                    $attempt
                )
            );
        } catch (Throwable $exception) {
            $this->recordExecutionFailure(
                (int) $attempt->id,
                $durable,
                $exception
            );

            throw $exception;
        }

        DB::transaction(function () use ($attempt): void {
            $invoice = Invoice::query()
                ->whereKey($attempt->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $locked->status ===
                    InvoicePaymentInitiation::STATUS_INITIATING
            ) {
                $locked->forceFill([
                    'status' => InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                    'provider_status' => 'provider_pending',
                    'initiated_at' => $locked->initiated_at ?? now(),
                    'last_error' => null,
                    'next_reconcile_at' => now()->addSeconds(
                        $this->reconcileEverySeconds()
                    ),
                ])->save();
            }
        }, 5);

        return $result;
    }

    /**
     * @return array{
     *   initiation?: InvoicePaymentInitiation,
     *   durable?: bool,
     *   error?: string
     * }
     */
    private function acquire(int $initiationId): array
    {
        return DB::transaction(function () use (
            $initiationId
        ): array {
            $identity = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return ['error' => 'The provider payment claim is missing.'];
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $initiation = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                (int) $initiation->active_invoice_id
                    !== (int) $invoice->id
            ) {
                return [
                    'error' => 'This provider payment generation is already closed.',
                ];
            }
            $gateway = Gateway::withTrashed()
                ->whereKey($initiation->gateway_snapshot_id)
                ->lockForUpdate()
                ->first();
            if (
                $gateway === null
                || $gateway->trashed()
                || !(bool) $gateway->enabled
                || !hash_equals(
                    (string) $gateway->extension,
                    (string) $initiation->gateway_extension
                )
                || (
                    $initiation->gateway_id !== null
                    && (int) $initiation->gateway_id
                        !== (int) $gateway->id
                )
            ) {
                return $this->attentionResult(
                    $invoice,
                    $initiation,
                    'The frozen provider gateway changed or became unavailable.'
                );
            }
            $gateway->load('settings');
            $durable =
                ExtensionHelper::supportsDurablePaymentInitiations(
                    $gateway
                );
            if (
                $initiation->status ===
                    InvoicePaymentInitiation::STATUS_SUCCEEDED
            ) {
                return ['error' => 'This invoice payment already succeeded.'];
            }
            if (
                $initiation->status ===
                    InvoicePaymentInitiation::STATUS_FAILED
            ) {
                return [
                    'error' => 'This provider payment failed. Choose a new payment method.',
                ];
            }
            if (
                $initiation->status ===
                    InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
            ) {
                return [
                    'error' => 'This provider payment requires manual review.',
                ];
            }
            if (
                $invoice->status !== Invoice::STATUS_PENDING
                || (
                    $invoice->payment_attention_required_at !== null
                    && !$this
                        ->claimOwnsReconciliationAttention(
                            $invoice,
                            $initiation
                        )
                )
            ) {
                return $this->attentionResult(
                    $invoice,
                    $initiation,
                    'The invoice changed or entered payment review before provider initiation completed.'
                );
            }
            if (
                app(CapacityInvoicePaymentService::class)
                    ->deadlineExpired($invoice)
            ) {
                return $this->attentionResult(
                    $invoice,
                    $initiation,
                    'The capacity guarantee expired before the interactive provider payment completed.'
                );
            }
            $invoice->load(['items', 'transactions']);
            $remaining = $this->canonicalAmount($invoice->remaining);
            if (
                $remaining === null
                || !hash_equals(
                    (string) $initiation->amount,
                    $remaining
                )
                || !hash_equals(
                    (string) $initiation->currency_code,
                    strtoupper((string) $invoice->currency_code)
                )
            ) {
                return $this->attentionResult(
                    $invoice,
                    $initiation,
                    'The invoice amount or currency changed after its provider payment was frozen.'
                );
            }
            if (
                (int) $initiation->attempt_count > 0
                && (
                    !$durable
                    || $initiation->created_at->copy()
                        ->addSeconds(
                            $this->idempotencyRetryWindow(
                                $initiation
                            )
                        )
                        ->isPast()
                )
            ) {
                if ($durable) {
                    $initiation->forceFill([
                        'next_reconcile_at' => now(),
                        'last_error' => 'The provider initiation reached its idempotency retention boundary and must be reconciled before it can be resumed.',
                    ])->save();

                    return [
                        'error' => 'This provider payment is being reconciled before it can be resumed.',
                    ];
                }

                return $this->attentionResult(
                    $invoice,
                    $initiation,
                    'The gateway cannot safely reconcile a repeated provider initiation.'
                );
            }

            $initiation->forceFill([
                'attempt_count' => (int) $initiation->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
                'next_reconcile_at' => now()->addSeconds(
                    $this->reconcileEverySeconds()
                ),
            ])->save();

            return [
                'initiation' => $initiation->fresh([
                    'invoice.user',
                    'gateway.settings',
                ]),
                'durable' => $durable,
            ];
        }, 5);
    }

    /**
     * Freeze the provider identity as soon as the idempotent create call
     * returns, before rendering or redirecting to the customer.
     */
    public function recordProviderReference(
        InvoicePaymentInitiation|int $initiation,
        string $providerReference
    ): void {
        $initiationId = $initiation instanceof InvoicePaymentInitiation
                ? (int) $initiation->id
                : (int) $initiation;
        $providerReference = trim($providerReference);
        if (
            $providerReference === ''
            || strlen($providerReference) > 255
        ) {
            throw new \RuntimeException(
                'The provider returned an invalid payment identity.'
            );
        }

        try {
            $conflict = DB::transaction(function () use (
                $initiationId,
                $providerReference
            ): bool {
                $identity = InvoicePaymentInitiation::query()
                    ->whereKey($initiationId)
                    ->firstOrFail(['invoice_id']);
                $invoice = Invoice::query()
                    ->whereKey($identity->invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $locked = InvoicePaymentInitiation::query()
                    ->whereKey($initiationId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (
                    $locked->provider_reference !== null
                    && !hash_equals(
                        (string) $locked->provider_reference,
                        $providerReference
                    )
                ) {
                    $this->markAttentionLocked(
                        $invoice,
                        $locked,
                        'The provider returned conflicting payment identities for one idempotency key. Both remote objects require manual reconciliation.'
                    );

                    return true;
                }
                if ($locked->provider_reference === null) {
                    $locked->forceFill([
                        'provider_reference' => $providerReference,
                        'next_reconcile_at' => now()->addSeconds(
                            $this->reconcileEverySeconds()
                        ),
                    ])->save();
                }

                return false;
            }, 5);
        } catch (Throwable $exception) {
            if (
                $this->quarantineProviderReferenceConflict(
                    $initiationId,
                    $providerReference
                )
            ) {
                throw new \RuntimeException(
                    'The provider payment identity already belongs to another durable invoice generation.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
        if ($conflict) {
            throw new \RuntimeException(
                'The provider returned a conflicting payment identity for one idempotency key.'
            );
        }
    }

    private function quarantineProviderReferenceConflict(
        int $initiationId,
        string $providerReference
    ): bool {
        $target = InvoicePaymentInitiation::query()
            ->whereKey($initiationId)
            ->first([
                'id',
                'invoice_id',
                'gateway_snapshot_id',
            ]);
        if ($target === null) {
            return false;
        }
        $other = InvoicePaymentInitiation::query()
            ->where(
                'gateway_snapshot_id',
                $target->gateway_snapshot_id
            )
            ->where('provider_reference', $providerReference)
            ->where('id', '!=', $initiationId)
            ->first(['id', 'invoice_id']);
        if ($other === null) {
            return false;
        }
        $invoiceIds = collect([
            (int) $target->invoice_id,
            (int) $other->invoice_id,
        ])->unique()->sort()->values();
        $claimIds = collect([
            (int) $target->id,
            (int) $other->id,
        ])->unique()->sort()->values();
        DB::transaction(function () use (
            $invoiceIds,
            $claimIds,
            $providerReference,
            $target
        ): void {
            $invoices = Invoice::query()
                ->whereIn('id', $invoiceIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $claims = InvoicePaymentInitiation::query()
                ->whereIn('id', $claimIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $stillConflicting = $claims->contains(
                fn (
                    InvoicePaymentInitiation $claim
                ): bool => (int) $claim->id !== (int) $target->id
                    && (int) $claim->gateway_snapshot_id
                        === (int) $target->gateway_snapshot_id
                    && $claim->provider_reference !== null
                    && hash_equals(
                        (string) $claim->provider_reference,
                        $providerReference
                    )
            );
            if (!$stillConflicting) {
                return;
            }
            foreach ($claims as $claim) {
                $invoice = $invoices->get(
                    (int) $claim->invoice_id
                );
                if ($invoice !== null) {
                    $this->markAttentionLocked(
                        $invoice,
                        $claim,
                        'One provider payment identity was returned for multiple durable invoice generations.'
                    );
                }
            }
        }, 5);

        return true;
    }

    public function assertProviderCompletionAllowed(
        Invoice|int $invoice,
        string $gatewayExtension,
        string $providerReference
    ): InvoicePaymentInitiation {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;

        return DB::transaction(function () use (
            $invoiceId,
            $gatewayExtension,
            $providerReference
        ): InvoicePaymentInitiation {
            $invoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();
            $initiation = InvoicePaymentInitiation::query()
                ->where('active_invoice_id', $invoiceId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $invoice->status !== Invoice::STATUS_PENDING
                || $invoice->payment_attention_required_at !== null
                || !in_array($initiation->status, [
                    InvoicePaymentInitiation::STATUS_INITIATING,
                    InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                ], true)
                || !hash_equals(
                    strtolower((string) $initiation->gateway_extension),
                    strtolower(trim($gatewayExtension))
                )
                || $initiation->provider_reference === null
                || !hash_equals(
                    (string) $initiation->provider_reference,
                    trim($providerReference)
                )
            ) {
                throw new \RuntimeException(
                    'This provider payment is not the invoice-owned durable initiation.'
                );
            }
            if (
                app(CapacityInvoicePaymentService::class)
                    ->deadlineExpired($invoice)
            ) {
                throw new \RuntimeException(
                    'The invoice capacity guarantee expired before provider capture.'
                );
            }
            $invoice->load(['items', 'transactions']);
            $remaining = $this->canonicalAmount(
                $invoice->remaining
            );
            if (
                $remaining === null
                || !hash_equals(
                    (string) $initiation->amount,
                    $remaining
                )
                || !hash_equals(
                    (string) $initiation->currency_code,
                    strtoupper(
                        (string) $invoice->currency_code
                    )
                )
            ) {
                throw new \RuntimeException(
                    'The invoice amount or currency changed before provider capture.'
                );
            }

            return $initiation;
        }, 5);
    }

    public function assertProviderReferenceMatches(
        Invoice|int $invoice,
        string $gatewayExtension,
        string $providerReference
    ): InvoicePaymentInitiation {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        $initiation = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoiceId)
            ->whereRaw(
                'LOWER(gateway_extension) = ?',
                [strtolower(trim($gatewayExtension))]
            )
            ->where(
                'provider_reference',
                trim($providerReference)
            )
            ->first();
        $initiation ??= InvoicePaymentInitiation::query()
            ->where('active_invoice_id', $invoiceId)
            ->firstOrFail();
        if (
            !hash_equals(
                strtolower((string) $initiation->gateway_extension),
                strtolower(trim($gatewayExtension))
            )
            || $initiation->provider_reference === null
            || !hash_equals(
                (string) $initiation->provider_reference,
                trim($providerReference)
            )
        ) {
            throw new \RuntimeException(
                'Provider evidence does not belong to the invoice-owned payment initiation.'
            );
        }

        return $initiation;
    }

    public function providerGenerationForReference(
        string $gatewayExtension,
        string $providerReference
    ): ?InvoicePaymentInitiation {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return null;
        }
        $gatewayExtension = strtolower(
            trim($gatewayExtension)
        );
        $providerReference = trim($providerReference);
        if (
            $gatewayExtension === ''
            || $providerReference === ''
        ) {
            return null;
        }
        $matches = InvoicePaymentInitiation::query()
            ->whereRaw(
                'LOWER(gateway_extension) = ?',
                [$gatewayExtension]
            )
            ->where(
                'provider_reference',
                $providerReference
            )
            ->limit(2)
            ->get();
        if ($matches->count() > 1) {
            throw new \RuntimeException(
                'The provider resource identity matches multiple durable payment generations.'
            );
        }

        return $matches->first();
    }

    public function hasProviderGenerationHistory(
        Invoice|int $invoice,
        ?string $gatewayExtension = null
    ): bool {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return false;
        }
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        if ($invoiceId <= 0) {
            return false;
        }
        $query = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoiceId);
        $gatewayExtension = strtolower(
            trim((string) $gatewayExtension)
        );
        if ($gatewayExtension !== '') {
            $query->whereRaw(
                'LOWER(gateway_extension) = ?',
                [$gatewayExtension]
            );
        }

        return $query->exists();
    }

    /**
     * Quarantine signed provider evidence whose embedded generation metadata
     * does not reproduce the immutable claim. The adapter must still pass the
     * financial evidence to addPayment() so a real charge is retained for
     * refund review rather than discarded.
     */
    public function verifyProviderGenerationMetadata(
        int $invoiceId,
        string $gatewayExtension,
        string $providerReference,
        mixed $providerInitiationId,
        mixed $providerInitiationKey = null,
        bool $keyRequired = true
    ): bool {
        return DB::transaction(function () use (
            $invoiceId,
            $gatewayExtension,
            $providerReference,
            $providerInitiationId,
            $providerInitiationKey,
            $keyRequired
        ): bool {
            $invoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();
            $gatewayExtension = strtolower(
                trim($gatewayExtension)
            );
            $providerReference = trim($providerReference);
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoiceId)
                ->whereRaw(
                    'LOWER(gateway_extension) = ?',
                    [$gatewayExtension]
                )
                ->where(
                    'provider_reference',
                    $providerReference
                )
                ->lockForUpdate()
                ->first();
            $claim ??= InvoicePaymentInitiation::query()
                ->where('active_invoice_id', $invoiceId)
                ->whereRaw(
                    'LOWER(gateway_extension) = ?',
                    [$gatewayExtension]
                )
                ->lockForUpdate()
                ->first();
            $claim ??= InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoiceId)
                ->whereRaw(
                    'LOWER(gateway_extension) = ?',
                    [$gatewayExtension]
                )
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();
            if ($claim === null) {
                app(CapacityInvoicePaymentService::class)
                    ->requireAttention(
                        $invoice,
                        'Signed provider evidence has no durable Paymenter initiation generation.'
                    );

                return false;
            }
            $idMatches = is_numeric($providerInitiationId)
                && (int) $providerInitiationId ===
                    (int) $claim->id;
            $keyMatches = !$keyRequired
                || (
                    is_string($providerInitiationKey)
                    && hash_equals(
                        (string) $claim->idempotency_key,
                        trim($providerInitiationKey)
                    )
                );
            if (
                $idMatches
                && $keyMatches
                && $claim->provider_reference === null
                && (int) $claim->active_invoice_id ===
                    (int) $invoice->id
            ) {
                $conflict = InvoicePaymentInitiation::query()
                    ->where(
                        'gateway_snapshot_id',
                        $claim->gateway_snapshot_id
                    )
                    ->where(
                        'provider_reference',
                        $providerReference
                    )
                    ->where('id', '!=', $claim->id)
                    ->lockForUpdate()
                    ->first();
                if ($conflict === null) {
                    // The idempotent create response may have been lost after
                    // the provider committed the object. Exact signed
                    // generation metadata safely recovers that immutable
                    // resource identity without creating another object.
                    $claim->forceFill([
                        'provider_reference' => $providerReference,
                    ])->save();
                }
            }
            $referenceMatches =
                $claim->provider_reference !== null
                && hash_equals(
                    (string) $claim->provider_reference,
                    $providerReference
                );
            if (
                $idMatches
                && $keyMatches
                && $referenceMatches
            ) {
                return true;
            }

            $this->markAttentionLocked(
                $invoice,
                $claim,
                'Signed provider metadata or resource identity conflicts with the durable invoice payment generation.'
            );

            return false;
        }, 5);
    }

    public function hasClaim(Invoice|int $invoice): bool
    {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return false;
        }
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;

        return $invoiceId > 0
            && InvoicePaymentInitiation::query()
                ->where('active_invoice_id', $invoiceId)
                ->exists();
    }

    public function assertNewPaymentAttemptAllowed(
        Invoice|int $invoice
    ): void {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return;
        }
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        if (
            $invoiceId <= 0
            || $this->isExecuting($invoiceId)
            || $this->isRecordingEvidence($invoiceId)
        ) {
            return;
        }
        $claim = InvoicePaymentInitiation::query()
            ->where('active_invoice_id', $invoiceId)
            ->first();
        if ($claim !== null) {
            throw new \RuntimeException(
                "Invoice {$invoiceId} already owns provider payment initiation {$claim->id} ({$claim->status})."
            );
        }
    }

    public function assertInvoiceLifecycleMutable(
        Invoice|int $invoice
    ): void {
        $this->assertNewPaymentAttemptAllowed($invoice);
    }

    public function assertGatewayMutable(int $gatewayId): void
    {
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return;
        }
        $claim = InvoicePaymentInitiation::query()
            ->where('gateway_snapshot_id', $gatewayId)
            ->whereNotNull('active_invoice_id')
            ->orderBy('id')
            ->first();
        if ($claim !== null) {
            throw new \RuntimeException(
                "Gateway {$gatewayId} is pinned by provider payment initiation {$claim->id} ({$claim->status})."
            );
        }
    }

    public function shouldCoordinateProviderEvidence(
        int $invoiceId,
        ?int $gatewayId = null,
        ?string $transactionId = null,
        ?string $providerResourceReference = null
    ): bool {
        if (
            $this->isRecordingEvidence($invoiceId)
            || !Schema::hasTable('invoice_payment_initiations')
        ) {
            return false;
        }
        if ($this->hasClaim($invoiceId)) {
            return true;
        }
        if ($gatewayId === null) {
            return false;
        }
        $query = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoiceId)
            ->where('gateway_snapshot_id', $gatewayId);
        $providerResourceReference = trim(
            (string) $providerResourceReference
        );
        $transactionId = trim((string) $transactionId);
        if ($providerResourceReference !== '') {
            if ((clone $query)
                ->where(
                    'provider_reference',
                    $providerResourceReference
                )
                ->exists()
            ) {
                return true;
            }
        }
        if ($transactionId !== '') {
            if ((clone $query)
                ->where(
                    'provider_transaction_id',
                    $transactionId
                )
                ->exists()
            ) {
                return true;
            }
        }

        // Once an invoice has any durable interactive history, every signed
        // provider callback must remain in this coordinator. A wrong gateway
        // identity is itself evidence to quarantine; it must never become a
        // route back into the uncoordinated legacy settlement path.
        return ($providerResourceReference !== ''
                || $transactionId !== '')
            && InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoiceId)
                ->exists();
    }

    /**
     * Reconcile every due active generation without allowing one provider
     * outage to prevent later rows from being inspected.
     *
     * @return array{
     *   scanned: int,
     *   reconciled: int,
     *   released: int,
     *   skipped: int,
     *   failed: int
     * }
     */
    public function recover(int $limit = 100): array
    {
        $summary = [
            'scanned' => 0,
            'reconciled' => 0,
            'released' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        if (!Schema::hasTable('invoice_payment_initiations')) {
            return $summary;
        }
        $limit = max(1, min(1000, $limit));
        $ids = InvoicePaymentInitiation::query()
            ->whereNotNull('active_invoice_id')
            ->whereIn('status', [
                InvoicePaymentInitiation::STATUS_INITIATING,
                InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            ])
            ->where(function ($query): void {
                $query
                    ->where(
                        'status',
                        '!=',
                        InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
                    )
                    ->orWhere(
                        'attention_reconcilable',
                        true
                    );
            })
            ->where(function ($query): void {
                $query->whereNull('next_reconcile_at')
                    ->orWhere('next_reconcile_at', '<=', now());
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('reconciliation_lease_expires_at')
                    ->orWhere(
                        'reconciliation_lease_expires_at',
                        '<=',
                        now()
                    );
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $summary['scanned']++;
            try {
                $result = $this->reconcile((int) $id);
                if (array_key_exists($result, $summary)) {
                    $summary[$result]++;
                } else {
                    $summary['reconciled']++;
                }
            } catch (Throwable) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @return 'reconciled'|'released'|'skipped'
     */
    public function reconcile(
        InvoicePaymentInitiation|int $initiation,
        bool $cancelIfSafe = false
    ): string {
        $initiationId = $initiation instanceof InvoicePaymentInitiation
                ? (int) $initiation->id
                : (int) $initiation;
        $leased = $this->leaseForReconciliation($initiationId);
        if ($leased === null) {
            return 'skipped';
        }
        $leaseToken = (string) $leased->reconciliation_lease_token;

        try {
            if (
                $leased->provider_reference === null
                || trim((string) $leased->provider_reference) === ''
            ) {
                // The create response may have been lost after the provider
                // accepted the request. Replay only the same durable key.
                $this->execute($leased);
                $leased = InvoicePaymentInitiation::query()
                    ->whereKey($initiationId)
                    ->firstOrFail();
                if (
                    $leased->active_invoice_id === null
                    || $leased->status ===
                        InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
                ) {
                    return 'skipped';
                }
                if (
                    $leased->provider_reference === null
                    || trim(
                        (string) $leased->provider_reference
                    ) === ''
                ) {
                    throw new \RuntimeException(
                        'The provider replay did not recover its immutable payment identity.'
                    );
                }
            }

            $cancelIfSafe =
                $cancelIfSafe
                || $this->abandonmentDeadline($leased)->isPast()
                || app(CapacityInvoicePaymentService::class)
                    ->deadlineExpired((int) $leased->invoice_id);
            $outcome = $this->normalizeReconciliationOutcome(
                $leased,
                ExtensionHelper::reconcileInvoicePaymentInitiation(
                    $leased,
                    $cancelIfSafe
                )
            );

            return match ($outcome['evidence_status']) {
                'succeeded' => $this->recordReconciledSuccess(
                    $leased,
                    $outcome
                ),
                'processing' => $this->recordReconciledProcessing(
                    $leased,
                    $outcome
                ),
                'open' => $this->recordReconciledOpen(
                    $leased,
                    $leaseToken,
                    $outcome
                ),
                'failed' => $this->releaseTerminalFailure(
                    $leased,
                    $leaseToken,
                    $outcome
                ),
                'attention' => $this->recordReconciliationAttention(
                    $leased,
                    $leaseToken,
                    $outcome
                ),
            };
        } catch (Throwable $exception) {
            $this->recordReconciliationFailure(
                $initiationId,
                $leaseToken,
                $exception
            );

            throw $exception;
        }
    }

    private function leaseForReconciliation(
        int $initiationId
    ): ?InvoicePaymentInitiation {
        return DB::transaction(function () use (
            $initiationId
        ): ?InvoicePaymentInitiation {
            $identity = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return null;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $initiation = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                (int) $initiation->active_invoice_id
                    !== (int) $invoice->id
                || !in_array($initiation->status, [
                    InvoicePaymentInitiation::STATUS_INITIATING,
                    InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                    InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                ], true)
                || (
                    $initiation->status ===
                        InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
                    && !(bool) $initiation
                        ->attention_reconcilable
                )
                || (
                    $initiation->reconciliation_lease_expires_at
                        !== null
                    && $initiation
                        ->reconciliation_lease_expires_at
                        ->isFuture()
                )
            ) {
                return null;
            }
            if (
                $invoice->status !== Invoice::STATUS_PENDING
                || (
                    $invoice->payment_attention_required_at
                        !== null
                    && !$this
                        ->claimOwnsReconciliationAttention(
                            $invoice,
                            $initiation
                        )
                )
            ) {
                $this->markAttentionLocked(
                    $invoice,
                    $initiation,
                    'The invoice lifecycle changed before its active provider payment could be reconciled.'
                );

                return null;
            }
            $leaseToken = (string) Str::uuid();
            $initiation->forceFill([
                'reconciliation_lease_token' => $leaseToken,
                'reconciliation_lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'reconciliation_attempt_count' => (int) $initiation
                    ->reconciliation_attempt_count + 1,
                'next_reconcile_at' => null,
            ])->save();

            return $initiation->fresh([
                'invoice.user',
                'gateway.settings',
            ]);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @return array{
     *   provider_reference: string,
     *   provider_transaction_id: string|null,
     *   provider_status: string,
     *   evidence_status: 'open'|'processing'|'succeeded'|'failed'|'attention',
     *   amount: string,
     *   currency_code: string,
     *   fee: string|null,
     *   message: string|null,
     *   attention_reconcilable: bool
     * }
     */
    private function normalizeReconciliationOutcome(
        InvoicePaymentInitiation $initiation,
        array $outcome
    ): array {
        $providerReference = trim(
            (string) ($outcome['provider_reference'] ?? '')
        );
        $providerTransactionId = isset(
            $outcome['provider_transaction_id']
        )
            ? trim(
                (string) $outcome['provider_transaction_id']
            )
            : null;
        $providerTransactionId =
            $providerTransactionId === ''
                ? null
                : $providerTransactionId;
        $providerStatus = trim(
            (string) ($outcome['provider_status'] ?? '')
        );
        $evidenceStatus = trim(
            (string) ($outcome['evidence_status'] ?? '')
        );
        $amount = $this->canonicalAmount(
            $outcome['amount'] ?? null
        );
        $currency = strtoupper(
            trim((string) ($outcome['currency_code'] ?? ''))
        );
        $fee = array_key_exists('fee', $outcome)
            && $outcome['fee'] !== null
                ? $this->canonicalAmount($outcome['fee'])
                : null;
        $message = isset($outcome['message'])
            ? trim((string) $outcome['message'])
            : null;
        $message = $message === '' ? null : $message;
        $attentionReconcilable =
            ($outcome['attention_reconcilable'] ?? false) === true;

        if (
            $providerReference === ''
            || strlen($providerReference) > 255
            || !hash_equals(
                (string) $initiation->provider_reference,
                $providerReference
            )
            || $providerStatus === ''
            || strlen($providerStatus) > 100
            || !in_array($evidenceStatus, [
                'open',
                'processing',
                'succeeded',
                'failed',
                'attention',
            ], true)
            || $amount === null
            || !hash_equals(
                (string) $initiation->amount,
                $amount
            )
            || !hash_equals(
                (string) $initiation->currency_code,
                $currency
            )
            || (
                in_array($evidenceStatus, [
                    'processing',
                    'succeeded',
                ], true)
                && (
                    $providerTransactionId === null
                    || strlen($providerTransactionId) > 255
                )
            )
            || (
                $providerTransactionId !== null
                && strlen($providerTransactionId) > 255
            )
            || (
                array_key_exists('fee', $outcome)
                && $outcome['fee'] !== null
                && $fee === null
            )
            || (
                array_key_exists(
                    'attention_reconcilable',
                    $outcome
                )
                && !is_bool($outcome['attention_reconcilable'])
            )
            || (
                $attentionReconcilable
                && $evidenceStatus !== 'attention'
            )
        ) {
            throw new \RuntimeException(
                'The provider returned an invalid or conflicting payment reconciliation result.'
            );
        }

        return [
            'provider_reference' => $providerReference,
            'provider_transaction_id' => $providerTransactionId,
            'provider_status' => $providerStatus,
            'evidence_status' => $evidenceStatus,
            'amount' => $amount,
            'currency_code' => $currency,
            'fee' => $fee,
            'message' => $message === null
                ? null
                : mb_substr($message, 0, 65535),
            'attention_reconcilable' => $attentionReconcilable,
        ];
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function recordReconciledSuccess(
        InvoicePaymentInitiation $initiation,
        array $outcome
    ): string {
        ExtensionHelper::addPayment(
            (int) $initiation->invoice_id,
            (string) $initiation->gateway_extension,
            $outcome['amount'],
            $outcome['fee'],
            $outcome['provider_transaction_id'],
            InvoiceTransactionStatus::Succeeded,
            false,
            null,
            $outcome['currency_code'],
            $outcome['provider_reference']
        );

        return 'reconciled';
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function recordReconciledProcessing(
        InvoicePaymentInitiation $initiation,
        array $outcome
    ): string {
        ExtensionHelper::addProcessingPayment(
            (int) $initiation->invoice_id,
            (string) $initiation->gateway_extension,
            $outcome['amount'],
            $outcome['fee'],
            $outcome['provider_transaction_id'],
            null,
            $outcome['currency_code'],
            $outcome['provider_reference']
        );
        $this->scheduleNextReconciliation(
            (int) $initiation->id
        );

        return 'reconciled';
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function recordReconciledOpen(
        InvoicePaymentInitiation $initiation,
        string $leaseToken,
        array $outcome
    ): string {
        DB::transaction(function () use (
            $initiation,
            $leaseToken,
            $outcome
        ): void {
            $invoice = Invoice::query()
                ->whereKey($initiation->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($initiation->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $locked->active_invoice_id === null
                || !hash_equals(
                    (string) $locked
                        ->reconciliation_lease_token,
                    $leaseToken
                )
            ) {
                return;
            }
            if (
                $this->claimOwnsReconciliationAttention(
                    $invoice,
                    $locked
                )
            ) {
                $invoice->forceFill([
                    'payment_attention_required_at' => null,
                    'payment_attention_reason' => null,
                    'payment_attention_alerted_at' => null,
                ])->save();
            }
            $locked->forceFill([
                'status' => InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                'provider_status' => $outcome['provider_status'],
                'last_error' => $outcome['message'],
                'failed_at' => null,
                'attention_reason' => null,
                'attention_reconcilable' => false,
                'reconciliation_lease_token' => null,
                'reconciliation_lease_expires_at' => null,
                'next_reconcile_at' => now()->addSeconds(
                    $this->reconcileEverySeconds()
                ),
            ])->save();
        }, 5);

        return 'reconciled';
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function releaseTerminalFailure(
        InvoicePaymentInitiation $initiation,
        string $leaseToken,
        array $outcome
    ): string {
        return DB::transaction(function () use (
            $initiation,
            $leaseToken,
            $outcome
        ): string {
            $invoice = Invoice::query()
                ->whereKey($initiation->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($initiation->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $locked->active_invoice_id === null
                || !hash_equals(
                    (string) $locked
                        ->reconciliation_lease_token,
                    $leaseToken
                )
            ) {
                return 'skipped';
            }
            if (
                $invoice->status !== Invoice::STATUS_PENDING
                || (
                    $invoice->payment_attention_required_at !== null
                    && !$this
                        ->claimOwnsReconciliationAttention(
                            $invoice,
                            $locked
                        )
                )
            ) {
                $this->markAttentionLocked(
                    $invoice,
                    $locked,
                    'The provider became terminal only after the invoice lifecycle changed.'
                );

                return 'reconciled';
            }

            $transactionId =
                $outcome['provider_transaction_id']
                ?? $locked->provider_transaction_id;
            if (is_string($transactionId) && $transactionId !== '') {
                $existing = $this->lockedProviderTransaction(
                    (int) $locked->gateway_snapshot_id,
                    $transactionId
                );
                if ($existing !== null) {
                    $existingStatus =
                        $existing->status instanceof InvoiceTransactionStatus
                            ? $existing->status
                            : InvoiceTransactionStatus::tryFrom(
                                (string) $existing->status
                            );
                    if (
                        $existingStatus ===
                            InvoiceTransactionStatus::Succeeded
                    ) {
                        $this->markAttentionLocked(
                            $invoice,
                            $locked,
                            'The provider reported a terminal unpaid object after local succeeded evidence was already recorded.'
                        );

                        return 'reconciled';
                    }
                    if (
                        $existingStatus ===
                            InvoiceTransactionStatus::Processing
                    ) {
                        $this->coordinatedEvidenceDepth(
                            (int) $invoice->id,
                            fn () => app(
                                CapacityInvoicePaymentService::class
                            )->recordPaymentEvidence(
                                (int) $invoice->id,
                                function () use ($existing): void {
                                    $existing->status =
                                        InvoiceTransactionStatus::Failed;
                                    $existing->save();
                                }
                            )
                        );
                    }
                }
            }

            $reason = $outcome['message']
                ?? "Provider status {$outcome['provider_status']} is terminal and cannot create a charge.";
            if (
                $this->claimOwnsReconciliationAttention(
                    $invoice,
                    $locked
                )
                && $invoice->transactions()
                    ->whereIn('status', [
                        InvoiceTransactionStatus::Processing->value,
                        InvoiceTransactionStatus::Succeeded->value,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first() === null
            ) {
                $invoice->forceFill([
                    'payment_attention_required_at' => null,
                    'payment_attention_reason' => null,
                    'payment_attention_alerted_at' => null,
                ])->save();
            }
            $locked->forceFill([
                'active_invoice_id' => null,
                'status' => InvoicePaymentInitiation::STATUS_FAILED,
                'provider_transaction_id' => $transactionId,
                'provider_status' => $outcome['provider_status'],
                'last_error' => $reason,
                'failed_at' => $locked->failed_at ?? now(),
                'released_at' => $locked->released_at ?? now(),
                'release_reason' => $reason,
                'attention_reconcilable' => false,
                'reconciliation_lease_token' => null,
                'reconciliation_lease_expires_at' => null,
                'next_reconcile_at' => null,
            ])->save();

            return 'released';
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function recordReconciliationAttention(
        InvoicePaymentInitiation $initiation,
        string $leaseToken,
        array $outcome
    ): string {
        DB::transaction(function () use (
            $initiation,
            $leaseToken,
            $outcome
        ): void {
            $invoice = Invoice::query()
                ->whereKey($initiation->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($initiation->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $locked->active_invoice_id === null
                || !hash_equals(
                    (string) $locked
                        ->reconciliation_lease_token,
                    $leaseToken
                )
            ) {
                return;
            }
            $this->markAttentionLocked(
                $invoice,
                $locked,
                $outcome['message']
                    ?? "Provider reconciliation returned unsupported status {$outcome['provider_status']}.",
                $outcome['attention_reconcilable']
            );
        }, 5);

        return 'reconciled';
    }

    private function scheduleNextReconciliation(
        int $initiationId
    ): void {
        $identity = InvoicePaymentInitiation::query()
            ->whereKey($initiationId)
            ->first(['invoice_id']);
        if ($identity === null) {
            return;
        }
        DB::transaction(function () use (
            $initiationId,
            $identity
        ): void {
            Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->active_invoice_id === null) {
                return;
            }
            $locked->forceFill([
                'reconciliation_lease_token' => null,
                'reconciliation_lease_expires_at' => null,
                'next_reconcile_at' => now()->addSeconds(
                    $this->reconcileEverySeconds()
                ),
            ])->save();
        }, 5);
    }

    private function recordReconciliationFailure(
        int $initiationId,
        string $leaseToken,
        Throwable $exception
    ): void {
        $identity = InvoicePaymentInitiation::query()
            ->whereKey($initiationId)
            ->first(['invoice_id']);
        if ($identity === null) {
            return;
        }
        DB::transaction(function () use (
            $initiationId,
            $identity,
            $leaseToken,
            $exception
        ): void {
            Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $locked->active_invoice_id === null
                || !hash_equals(
                    (string) $locked
                        ->reconciliation_lease_token,
                    $leaseToken
                )
            ) {
                return;
            }
            $attempts = max(
                1,
                (int) $locked->reconciliation_attempt_count
            );
            $backoff = min(
                3600,
                $this->reconcileEverySeconds()
                    * (2 ** min(6, $attempts - 1))
            );
            $locked->forceFill([
                'last_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    65535
                ),
                'reconciliation_lease_token' => null,
                'reconciliation_lease_expires_at' => null,
                'next_reconcile_at' => now()->addSeconds($backoff),
            ])->save();
        }, 5);
    }

    private function coordinatedEvidenceDepth(
        int $invoiceId,
        Closure $callback
    ): mixed {
        self::$evidenceDepth[$invoiceId] =
            (self::$evidenceDepth[$invoiceId] ?? 0) + 1;
        try {
            return $callback();
        } finally {
            self::$evidenceDepth[$invoiceId]--;
            if (self::$evidenceDepth[$invoiceId] === 0) {
                unset(self::$evidenceDepth[$invoiceId]);
            }
        }
    }

    public function recordProviderEvidence(
        int $invoiceId,
        ?int $gatewayId,
        mixed $amount,
        ?string $transactionId,
        InvoiceTransactionStatus $status,
        Closure $persist,
        ?string $providerCurrency = null,
        ?string $providerResourceReference = null
    ): mixed {
        self::$evidenceDepth[$invoiceId] =
            (self::$evidenceDepth[$invoiceId] ?? 0) + 1;
        try {
            return DB::transaction(function () use (
                $invoiceId,
                $gatewayId,
                $amount,
                $transactionId,
                $status,
                $persist,
                $providerCurrency,
                $providerResourceReference
            ): mixed {
                $invoice = Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $initiation =
                    $this->lockedEvidenceInitiation(
                        $invoiceId,
                        $gatewayId,
                        $transactionId,
                        $providerResourceReference
                    );
                if ($initiation === null) {
                    throw new \RuntimeException(
                        'Provider evidence has no durable invoice payment generation.'
                    );
                }
                if (
                    $this->isExactReleasedFailureReplay(
                        $initiation,
                        $gatewayId,
                        $amount,
                        $transactionId,
                        $status,
                        $providerCurrency,
                        $providerResourceReference
                    )
                ) {
                    $transactionId = trim(
                        (string) $transactionId
                    );

                    return $transactionId === ''
                        ? null
                        : $this->lockedProviderTransaction(
                            $gatewayId,
                            $transactionId
                        );
                }

                $attentionReason =
                    $this->incomingEvidenceAttentionReason(
                        $invoice,
                        $initiation,
                        $gatewayId,
                        $amount,
                        $transactionId,
                        $status,
                        $providerCurrency,
                        $providerResourceReference
                    );
                if ($attentionReason !== null) {
                    $reconcilable =
                        $status ===
                            InvoiceTransactionStatus::Failed
                        && (int) $initiation
                            ->active_invoice_id === $invoiceId;
                    $this->markAttentionLocked(
                        $invoice,
                        $initiation,
                        $attentionReason,
                        $reconcilable
                    );
                    if (
                        (int) $initiation->active_invoice_id
                            !== $invoiceId
                    ) {
                        $active =
                            InvoicePaymentInitiation::query()
                                ->where(
                                    'active_invoice_id',
                                    $invoiceId
                                )
                                ->lockForUpdate()
                                ->first();
                        if ($active !== null) {
                            $active->forceFill([
                                'status' => InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                                'last_error' => 'A late provider callback for an earlier payment generation requires manual reconciliation.',
                                'failed_at' => $active->failed_at ?? now(),
                                'reconciliation_lease_token' => null,
                                'reconciliation_lease_expires_at' => null,
                                'next_reconcile_at' => null,
                            ])->save();
                        }
                    }

                    $result = app(
                        CapacityInvoicePaymentService::class
                    )->recoverPaymentEvidence(
                        $invoiceId,
                        $attentionReason,
                        $persist
                    );
                    $this->assertPersistedEvidence(
                        $result,
                        $invoiceId,
                        $gatewayId,
                        $amount,
                        $transactionId,
                        $status
                    );

                    return $result;
                }

                $result = $persist();
                $this->assertPersistedEvidence(
                    $result,
                    $invoiceId,
                    $gatewayId,
                    $amount,
                    $transactionId,
                    $status
                );
                $invoice->refresh();
                if (
                    $invoice->payment_attention_required_at !== null
                ) {
                    $reason = trim(
                        (string) $invoice->payment_attention_reason
                    );
                    $this->markAttentionLocked(
                        $invoice,
                        $initiation,
                        $reason !== ''
                            ? $reason
                            : 'The invoice entered manual payment review while provider evidence was being recorded.'
                    );

                    return $result;
                }

                $transactionId = trim((string) $transactionId);
                $attributes = [
                    'provider_transaction_id' => $transactionId,
                    'provider_status' => $status->value,
                    'last_error' => null,
                ];
                if (
                    $status ===
                        InvoiceTransactionStatus::Succeeded
                ) {
                    $attributes['status'] =
                        InvoicePaymentInitiation::STATUS_SUCCEEDED;
                    $attributes['active_invoice_id'] = null;
                    $attributes['settled_at'] =
                        $initiation->settled_at ?? now();
                    $attributes['reconciliation_lease_token'] = null;
                    $attributes[
                        'reconciliation_lease_expires_at'
                    ] = null;
                    $attributes['next_reconcile_at'] = null;
                } elseif (
                    $status === InvoiceTransactionStatus::Failed
                ) {
                    throw new \LogicException(
                        'Failed provider evidence must enter recovery before persistence.'
                    );
                } else {
                    $attributes['status'] =
                        InvoicePaymentInitiation::STATUS_PROVIDER_PENDING;
                }
                $initiation->forceFill($attributes)->save();

                return $result;
            }, 5);
        } finally {
            self::$evidenceDepth[$invoiceId]--;
            if (self::$evidenceDepth[$invoiceId] === 0) {
                unset(self::$evidenceDepth[$invoiceId]);
            }
        }
    }

    private function lockedEvidenceInitiation(
        int $invoiceId,
        ?int $gatewayId,
        ?string $transactionId,
        ?string $providerResourceReference
    ): ?InvoicePaymentInitiation {
        $resource = trim((string) $providerResourceReference);
        if ($gatewayId !== null && $resource !== '') {
            $exact = InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoiceId)
                ->where('gateway_snapshot_id', $gatewayId)
                ->where('provider_reference', $resource)
                ->lockForUpdate()
                ->first();
            if ($exact !== null) {
                return $exact;
            }
        }
        $transactionId = trim((string) $transactionId);
        if ($gatewayId !== null && $transactionId !== '') {
            $exact = InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoiceId)
                ->where('gateway_snapshot_id', $gatewayId)
                ->where(
                    'provider_transaction_id',
                    $transactionId
                )
                ->lockForUpdate()
                ->first();
            if ($exact !== null) {
                return $exact;
            }
        }

        $active = InvoicePaymentInitiation::query()
            ->where('active_invoice_id', $invoiceId)
            ->lockForUpdate()
            ->first();
        if ($active !== null) {
            return $active;
        }
        if ($gatewayId === null) {
            return null;
        }

        $sameGateway = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoiceId)
            ->where('gateway_snapshot_id', $gatewayId)
            ->orderByDesc('generation')
            ->lockForUpdate()
            ->first();
        if ($sameGateway !== null) {
            return $sameGateway;
        }
        if ($resource === '' && $transactionId === '') {
            return null;
        }

        // Preserve signed evidence even when the callback names the wrong
        // gateway. The newest historical generation becomes the quarantine
        // owner, and incomingEvidenceAttentionReason() records the gateway
        // mismatch before financial evidence is persisted under attention.
        return InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('generation')
            ->lockForUpdate()
            ->first();
    }

    private function isExactReleasedFailureReplay(
        InvoicePaymentInitiation $initiation,
        ?int $gatewayId,
        mixed $amount,
        ?string $transactionId,
        InvoiceTransactionStatus $status,
        ?string $providerCurrency,
        ?string $providerResourceReference
    ): bool {
        if (
            $status !== InvoiceTransactionStatus::Failed
            || $initiation->status !==
                InvoicePaymentInitiation::STATUS_FAILED
            || $initiation->active_invoice_id !== null
            || (int) $initiation->gateway_snapshot_id
                !== $gatewayId
            || !hash_equals(
                (string) $initiation->amount,
                (string) $this->canonicalAmount($amount)
            )
            || (
                $providerCurrency !== null
                && !hash_equals(
                    (string) $initiation->currency_code,
                    strtoupper(trim($providerCurrency))
                )
            )
        ) {
            return false;
        }
        $resource = trim((string) $providerResourceReference);
        if (
            $resource !== ''
            && $initiation->provider_reference !== null
            && hash_equals(
                (string) $initiation->provider_reference,
                $resource
            )
        ) {
            return true;
        }
        $transactionId = trim((string) $transactionId);

        return $transactionId !== ''
            && $initiation->provider_transaction_id !== null
            && hash_equals(
                (string) $initiation->provider_transaction_id,
                $transactionId
            );
    }

    private function incomingEvidenceAttentionReason(
        Invoice $invoice,
        InvoicePaymentInitiation $initiation,
        ?int $gatewayId,
        mixed $amount,
        ?string $transactionId,
        InvoiceTransactionStatus $status,
        ?string $providerCurrency = null,
        ?string $providerResourceReference = null
    ): ?string {
        $transactionId = trim((string) $transactionId);
        $canonical = $this->canonicalAmount($amount);
        if (
            $gatewayId !==
                (int) $initiation->gateway_snapshot_id
            || $canonical === null
            || !hash_equals(
                (string) $initiation->amount,
                $canonical
            )
            || $transactionId === ''
            || (
                $initiation->provider_transaction_id !== null
                && !hash_equals(
                    (string) $initiation->provider_transaction_id,
                    $transactionId
                )
            )
            || !hash_equals(
                (string) $initiation->currency_code,
                strtoupper((string) $invoice->currency_code)
            )
            || (
                $providerCurrency !== null
                && !hash_equals(
                    (string) $initiation->currency_code,
                    strtoupper(trim($providerCurrency))
                )
            )
            || (
                $providerResourceReference !== null
                && $initiation->provider_reference !== null
                && !hash_equals(
                    (string) $initiation->provider_reference,
                    trim($providerResourceReference)
                )
            )
            || (
                $providerResourceReference === null
                && in_array(
                    strtolower(
                        (string) $initiation->gateway_extension
                    ),
                    ['stripe', 'mollie'],
                    true
                )
                && $initiation->provider_reference !== null
                && !hash_equals(
                    (string) $initiation->provider_reference,
                    $transactionId
                )
            )
        ) {
            return 'Provider payment evidence conflicts with the invoice-owned durable initiation.';
        }
        if (
            $status === InvoiceTransactionStatus::Failed
        ) {
            return 'The provider reported a failed interactive payment, but its remote payment identity must be cancelled or reconciled before another method can be attempted.';
        }
        if (
            in_array($initiation->status, [
                InvoicePaymentInitiation::STATUS_FAILED,
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            ], true)
        ) {
            $reason = trim((string) $initiation->last_error);

            return $reason !== ''
                ? $reason
                : 'Provider evidence arrived for an initiation already requiring manual review.';
        }

        $existing = $this->lockedProviderTransaction(
            $gatewayId,
            $transactionId
        );
        if ($existing !== null) {
            $existingStatus = $existing->status instanceof InvoiceTransactionStatus
                    ? $existing->status
                    : InvoiceTransactionStatus::tryFrom(
                        (string) $existing->status
                    );
            $transitionAllowed =
                $existingStatus === $status
                || (
                    $existingStatus ===
                        InvoiceTransactionStatus::Processing
                    && in_array($status, [
                        InvoiceTransactionStatus::Succeeded,
                        InvoiceTransactionStatus::Failed,
                    ], true)
                );
            if (
                (int) $existing->invoice_id !== (int) $invoice->id
                || (
                    $existing->gateway_id === null
                        ? null
                        : (int) $existing->gateway_id
                ) !== $gatewayId
                || !hash_equals(
                    (string) $existing->transaction_id,
                    $transactionId
                )
                || !hash_equals(
                    (string) $existing->amount,
                    (string) $initiation->amount
                )
                || (bool) $existing->is_credit_transaction
                || !$transitionAllowed
            ) {
                return 'Provider payment evidence conflicts with an existing immutable invoice transaction.';
            }
        }
        if (
            $initiation->status ===
                InvoicePaymentInitiation::STATUS_SUCCEEDED
        ) {
            if (
                $status === InvoiceTransactionStatus::Succeeded
                && $existing !== null
            ) {
                return null;
            }

            return 'Provider evidence conflicts with an initiation already settled by a different lifecycle state.';
        }
        if ($invoice->payment_attention_required_at !== null) {
            $reason = trim(
                (string) $invoice->payment_attention_reason
            );

            return $reason !== ''
                ? $reason
                : 'The invoice already requires manual payment review.';
        }
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return 'Provider evidence arrived after the pending invoice lifecycle ended.';
        }

        return app(CapacityInvoicePaymentService::class)
            ->incomingEvidenceAttentionReason(
                $invoice,
                $status,
                $gatewayId,
                $transactionId,
                $amount
            );
    }

    private function lockedProviderTransaction(
        ?int $gatewayId,
        string $transactionId
    ): ?InvoiceTransaction {
        $query = InvoiceTransaction::query();
        if (
            Schema::hasColumn(
                'invoice_transactions',
                'gateway_transaction_guard'
            )
        ) {
            $query->where(
                'gateway_transaction_guard',
                InvoiceTransaction::gatewayTransactionGuard(
                    $gatewayId,
                    $transactionId
                )
            );
        } else {
            $query->where('gateway_id', $gatewayId)
                ->where('transaction_id', $transactionId);
        }

        return $query->lockForUpdate()->first();
    }

    private function assertPersistedEvidence(
        mixed $result,
        int $invoiceId,
        ?int $gatewayId,
        mixed $amount,
        ?string $transactionId,
        InvoiceTransactionStatus $status
    ): void {
        $canonical = $this->canonicalAmount($amount);
        $transactionId = trim((string) $transactionId);
        $resultStatus = $result instanceof InvoiceTransaction
            ? (
                $result->status instanceof InvoiceTransactionStatus
                    ? $result->status
                    : InvoiceTransactionStatus::tryFrom(
                        (string) $result->status
                    )
            )
            : null;
        if (
            !$result instanceof InvoiceTransaction
            || (int) $result->invoice_id !== $invoiceId
            || (
                $result->gateway_id === null
                    ? null
                    : (int) $result->gateway_id
            ) !== $gatewayId
            || $canonical === null
            || !hash_equals(
                (string) $result->amount,
                $canonical
            )
            || $transactionId === ''
            || !hash_equals(
                (string) $result->transaction_id,
                $transactionId
            )
            || $resultStatus !== $status
            || (bool) $result->is_credit_transaction
        ) {
            throw new \RuntimeException(
                'Provider payment evidence did not persist as the exact immutable invoice transaction.'
            );
        }
    }

    private function recordExecutionFailure(
        int $initiationId,
        bool $durable,
        Throwable $exception
    ): void {
        DB::transaction(function () use (
            $initiationId,
            $durable,
            $exception
        ): void {
            $identity = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->first(['invoice_id']);
            if ($identity === null) {
                return;
            }
            $invoice = Invoice::query()
                ->whereKey($identity->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $initiation = InvoicePaymentInitiation::query()
                ->whereKey($initiationId)
                ->lockForUpdate()
                ->firstOrFail();
            $message = mb_substr(
                $exception->getMessage(),
                0,
                65535
            );
            if (
                $initiation->status ===
                    InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
            ) {
                return;
            }
            if ($durable) {
                $initiation->forceFill([
                    'status' => InvoicePaymentInitiation::STATUS_INITIATING,
                    'last_error' => $message,
                    'next_reconcile_at' => now()->addSeconds(
                        $this->reconcileEverySeconds()
                    ),
                ])->save();

                return;
            }

            $this->markAttentionLocked(
                $invoice,
                $initiation,
                'The non-idempotent gateway failed after provider initiation began: '
                    . $message
            );
        }, 5);
    }

    private function attentionResult(
        Invoice $invoice,
        InvoicePaymentInitiation $initiation,
        string $reason
    ): array {
        $this->markAttentionLocked(
            $invoice,
            $initiation,
            $reason
        );

        return ['error' => $reason];
    }

    private function markAttentionLocked(
        Invoice $invoice,
        InvoicePaymentInitiation $initiation,
        string $reason,
        bool $reconcilable = false
    ): void {
        $reason = trim($reason);
        $prefix = "[provider-initiation:{$initiation->id}:{$initiation->generation}]";
        $ownedReason = $prefix . ' ' . $reason;
        $initiation->forceFill([
            'status' => InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            'last_error' => $reason,
            'failed_at' => $initiation->failed_at ?? now(),
            'attention_reason' => $ownedReason,
            'attention_reconcilable' => $reconcilable,
            'reconciliation_lease_token' => null,
            'reconciliation_lease_expires_at' => null,
            'next_reconcile_at' => $reconcilable
                ? now()
                : null,
        ])->save();
        $currentInvoiceReason = trim(
            (string) $invoice->payment_attention_reason
        );
        if (
            $invoice->payment_attention_required_at === null
            || str_starts_with(
                $currentInvoiceReason,
                $prefix . ' '
            )
        ) {
            app(CapacityInvoicePaymentService::class)
                ->requireAttention($invoice, $ownedReason);
        }
    }

    private function claimOwnsReconciliationAttention(
        Invoice $invoice,
        InvoicePaymentInitiation $initiation
    ): bool {
        return (bool) $initiation->attention_reconcilable
            && $initiation->attention_reason !== null
            && $invoice->payment_attention_reason !== null
            && hash_equals(
                (string) $initiation->attention_reason,
                (string) $invoice->payment_attention_reason
            );
    }

    private function assertInvoiceAndGatewayEligible(
        Invoice $invoice,
        Gateway $gateway
    ): void {
        if (
            $invoice->status !== Invoice::STATUS_PENDING
            || $invoice->payment_attention_required_at !== null
            || $gateway->trashed()
            || !(bool) $gateway->enabled
            || trim((string) $gateway->extension) === ''
            || preg_match(
                '/^[A-Z]{3}$/D',
                strtoupper((string) $invoice->currency_code)
            ) !== 1
            || app(CapacityInvoicePaymentService::class)
                ->deadlineExpired($invoice)
        ) {
            throw new \RuntimeException(
                'The invoice and gateway are not eligible for provider payment initiation.'
            );
        }
    }

    private function assertMatches(
        InvoicePaymentInitiation $initiation,
        Invoice $invoice,
        Gateway $gateway,
        string $amount
    ): void {
        if (
            (int) $initiation->invoice_id !== (int) $invoice->id
            || (int) $initiation->gateway_snapshot_id
                !== (int) $gateway->id
            || !hash_equals(
                (string) $initiation->gateway_extension,
                (string) $gateway->extension
            )
            || !hash_equals((string) $initiation->amount, $amount)
            || !hash_equals(
                (string) $initiation->currency_code,
                strtoupper((string) $invoice->currency_code)
            )
        ) {
            throw new \RuntimeException(
                'This invoice already owns a conflicting provider payment initiation.'
            );
        }
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
                'A provider payment requires a positive exact remaining amount.'
            );
        }

        return $amount;
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

    private function abandonmentDeadline(
        InvoicePaymentInitiation $initiation
    ): CarbonInterface {
        return ($initiation->initiated_at
            ?? $initiation->created_at
            ?? now())->copy()->addMinutes(
                $this->abandonAfterMinutes()
            );
    }

    private function abandonAfterMinutes(): int
    {
        return max(
            15,
            min(
                7 * 24 * 60,
                (int) config(
                    'services.invoice_payment_initiations.abandon_after_minutes',
                    120
                )
            )
        );
    }

    private function reconcileEverySeconds(): int
    {
        return max(
            30,
            min(
                3600,
                (int) config(
                    'services.invoice_payment_initiations.reconcile_every_seconds',
                    60
                )
            )
        );
    }

    private function leaseSeconds(): int
    {
        return max(
            60,
            min(
                900,
                (int) config(
                    'services.invoice_payment_initiations.lease_seconds',
                    120
                )
            )
        );
    }

    private function idempotencyRetryWindow(
        InvoicePaymentInitiation $initiation
    ): int {
        return match (
            strtolower((string) $initiation->gateway_extension)
        ) {
            'stripe' => 23 * 60 * 60,
            'paypal' => 5 * 60 * 60,
            'mollie' => 55 * 60,
            'paypal_ipn' => 365 * 24 * 60 * 60,
            default => 0,
        };
    }
}
