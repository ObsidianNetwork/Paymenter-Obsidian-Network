<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CapacityInvoicePaymentService
{
    /**
     * @var array<int, int>
     */
    private static array $paymentEvidenceCoordinatorDepth = [];

    /**
     * @var array<int, string>
     */
    private static array $paymentEvidenceRecoveryReasons = [];

    public function recordPaymentEvidence(
        Invoice|int $invoice,
        Closure $persist
    ): mixed {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;

        return DB::transaction(function () use (
            $invoiceId,
            $persist
        ): mixed {
            self::$paymentEvidenceCoordinatorDepth[$invoiceId] =
                (self::$paymentEvidenceCoordinatorDepth[$invoiceId] ?? 0) + 1;

            try {
                return $persist();
            } finally {
                $remainingDepth =
                    self::$paymentEvidenceCoordinatorDepth[$invoiceId] - 1;
                if ($remainingDepth === 0) {
                    unset(
                        self::$paymentEvidenceCoordinatorDepth[$invoiceId]
                    );
                } else {
                    self::$paymentEvidenceCoordinatorDepth[$invoiceId] =
                        $remainingDepth;
                }
            }
        }, 5);
    }

    public function isRecordingPaymentEvidence(Invoice|int $invoice): bool
    {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;

        return (self::$paymentEvidenceCoordinatorDepth[$invoiceId] ?? 0) > 0;
    }

    public function paymentEvidenceRecoveryReason(int $invoiceId): ?string
    {
        return self::$paymentEvidenceRecoveryReasons[$invoiceId] ?? null;
    }

    public function requiresAttention(Invoice|int $invoice): bool
    {
        if (!Schema::hasColumn('invoices', 'payment_attention_required_at')) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;

        return Invoice::query()
            ->whereKey($invoiceId)
            ->whereNotNull('payment_attention_required_at')
            ->exists();
    }

    public function assertPaymentAttemptAllowed(Invoice|int $invoice): void
    {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;
        if ($this->paymentEvidenceRecoveryReason($invoiceId) !== null) {
            return;
        }
        app(BillingChargeAttemptService::class)
            ->assertNewPaymentAttemptAllowed($invoice);
        app(InvoicePaymentInitiationService::class)
            ->assertNewPaymentAttemptAllowed($invoice);
        if ($this->requiresAttention($invoice)) {
            throw new \RuntimeException(
                'This invoice requires manual payment review. New payment attempts are disabled.'
            );
        }
    }

    public function incomingEvidenceAttentionReason(
        Invoice $invoice,
        InvoiceTransactionStatus $status,
        ?int $gatewayId = null,
        ?string $transactionId = null,
        mixed $amount = null,
        ?int $billingChargeAttemptId = null
    ): ?string {
        if (
            !in_array($status, [
                InvoiceTransactionStatus::Processing,
                InvoiceTransactionStatus::Succeeded,
            ], true)
            || !$this->requiresFulfillmentCoordinator($invoice)
        ) {
            return null;
        }
        if ($invoice->status === Invoice::STATUS_CANCELLED) {
            return 'Payment evidence was recorded after the invoice was cancelled. Do not fulfill it, and perform refund or account-credit review.';
        }
        if (
            $invoice->status === Invoice::STATUS_PENDING
            && $this->deadlineExpired($invoice)
        ) {
            return 'Payment evidence was recorded at or after the capacity guarantee deadline. Capacity remains released; do not provision, and perform refund or account-credit review.';
        }

        return app(BillingChargeAttemptService::class)
            ->incomingEvidenceAttentionReason(
                $invoice,
                $gatewayId,
                $transactionId,
                $amount,
                $status,
                $billingChargeAttemptId
            );
    }

    public function recoverPaymentEvidence(
        Invoice|int $invoice,
        string $reason,
        Closure $persist
    ): mixed {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;
        if (
            !$this->requiresFulfillmentCoordinator($invoiceId)
            && !$this->requiresAttention($invoiceId)
        ) {
            throw new \RuntimeException(
                'Payment-evidence recovery is limited to capacity-backed or already-attentioned invoices.'
            );
        }
        self::$paymentEvidenceRecoveryReasons[$invoiceId] = $reason;

        try {
            return DB::transaction(function () use (
                $invoiceId,
                $reason,
                $persist
            ): mixed {
                $result = $persist();
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->requireAttention($lockedInvoice, $reason);

                return $result;
            }, 5);
        } finally {
            unset(self::$paymentEvidenceRecoveryReasons[$invoiceId]);
        }
    }

    public function isCapacityBacked(Invoice|int $invoice): bool
    {
        if (
            !Schema::hasTable('ptero_resource_reservations')
            || !Schema::hasColumn('ptero_resource_reservations', 'invoice_id')
        ) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice ? (int) $invoice->id : $invoice;

        return DB::table('ptero_resource_reservations')
            ->where('invoice_id', $invoiceId)
            ->exists();
    }

    /**
     * Payment coordination also covers renewal invoices for services whose
     * durable checkout commitment belongs to an older invoice. Keep this
     * separate from isCapacityBacked(): renewal cancellation must not release
     * the already-provisioned service or reuse the original checkout deadline.
     */
    public function requiresFulfillmentCoordinator(
        Invoice|int $invoice
    ): bool {
        if (
            app(InvoicePaymentInitiationService::class)
                ->hasClaim($invoice)
        ) {
            return true;
        }
        if (
            app(BillingChargeAttemptService::class)
                ->hasAttempt($invoice)
        ) {
            return true;
        }
        if ($this->hasServiceUpgradeObligation($invoice)) {
            return true;
        }
        if ($this->isCapacityBacked($invoice)) {
            return true;
        }
        if (
            !Schema::hasTable('invoice_items')
            || !Schema::hasTable('ptero_resource_reservations')
            || !Schema::hasColumn(
                'ptero_resource_reservations',
                'service_id'
            )
        ) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;
        if ($invoiceId <= 0) {
            return false;
        }

        $query = DB::table('invoice_items as item')
            ->where('item.invoice_id', $invoiceId)
            ->where('item.reference_type', Service::class)
            ->whereNotNull('item.reference_id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ptero_resource_reservations as reservation')
                    ->whereColumn(
                        'reservation.service_id',
                        'item.reference_id'
                    );
                if (Schema::hasColumn(
                    'ptero_resource_reservations',
                    'purpose'
                )) {
                    $query->where('reservation.purpose', 'checkout');
                }
            });

        return $query->exists();
    }

    public function hasServiceUpgradeObligation(
        Invoice|int $invoice
    ): bool {
        if (
            !Schema::hasTable('invoice_items')
            || !Schema::hasTable('service_upgrades')
        ) {
            return false;
        }

        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : $invoice;
        if ($invoiceId <= 0) {
            return false;
        }

        return DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->where('reference_type', ServiceUpgrade::class)
            ->whereNotNull('reference_id')
            ->exists()
            || ServiceUpgrade::query()
                ->where('invoice_id', $invoiceId)
                ->exists();
    }

    public function deadlineExpired(Invoice $invoice): bool
    {
        $deadline = $this->effectiveDeadline($invoice);

        return $deadline !== null && $deadline->lessThanOrEqualTo(now());
    }

    public function effectiveDeadline(Invoice $invoice): ?CarbonInterface
    {
        $capacityBacked = $this->isCapacityBacked($invoice);
        if (
            !$capacityBacked
            && !$this->hasServiceUpgradeObligation($invoice)
        ) {
            return null;
        }

        $reservationDeadline = $capacityBacked
            ? DB::table('ptero_resource_reservations')
                ->where('invoice_id', $invoice->id)
                ->selectRaw(
                    'MIN(COALESCE(guaranteed_until, expires_at)) AS deadline'
                )
                ->value('deadline')
            : null;
        $deadlines = collect([
            $invoice->due_at,
            $reservationDeadline !== null
                ? Carbon::parse($reservationDeadline)
                : null,
        ])->filter();

        return $deadlines->sortBy(fn (CarbonInterface $date) => $date->getTimestamp())
            ->first();
    }

    public function hasInFlightOrSucceededPayment(Invoice $invoice): bool
    {
        return $invoice->transactions()
            ->whereIn('status', [
                InvoiceTransactionStatus::Processing->value,
                InvoiceTransactionStatus::Succeeded->value,
            ])
            ->exists();
    }

    /**
     * Persist an externally observable payment fact for operator
     * refund/credit review. This deliberately leaves the invoice unpaid so an
     * expired capacity promise or failed immutable upgrade proof can never be
     * consumed.
     */
    public function requireAttention(Invoice $invoice, string $reason): Invoice
    {
        $firstAlert = $invoice->payment_attention_alerted_at === null;
        $invoice->forceFill([
            'payment_attention_required_at' => $invoice->payment_attention_required_at ?? now(),
            'payment_attention_reason' => $reason,
            'payment_attention_alerted_at' => $invoice->payment_attention_alerted_at ?? now(),
        ])->save();

        if ($firstAlert) {
            $invoiceId = (int) $invoice->id;
            DB::afterCommit(fn () => $this->notifyOperators($invoiceId, $reason));
        }

        return $invoice->fresh();
    }

    private function notifyOperators(int $invoiceId, string $reason): void
    {
        $invoice = Invoice::query()->with('items')->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        $serviceId = (int) ($invoice->items
            ->firstWhere('reference_type', Service::class)
            ?->reference_id ?? 0);
        if ($serviceId === 0) {
            $upgradeId = (int) ($invoice->items
                ->firstWhere('reference_type', ServiceUpgrade::class)
                ?->reference_id ?? 0);
            $serviceId = (int) (
                ServiceUpgrade::query()->whereKey($upgradeId)->value('service_id')
                ?? 0
            );
        }
        $reservation = Schema::hasTable('ptero_resource_reservations')
            ? DB::table('ptero_resource_reservations')
                ->where('invoice_id', $invoiceId)
                ->orderBy('id')
                ->first()
            : null;
        $snapshot = [
            'reservation_id' => $reservation?->id,
            'service_id' => $serviceId,
            'invoice_id' => $invoiceId,
            'node_id' => $reservation?->node_id,
            'memory' => $reservation?->memory,
            'cpu' => $reservation?->cpu,
            'disk' => $reservation?->disk,
            'error' => $reason,
            'operation' => 'payment_attention',
        ];
        $alertServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\AlertService';
        if (
            class_exists($alertServiceClass)
            && method_exists(app($alertServiceClass), 'notifyPaymentAttention')
        ) {
            app($alertServiceClass)->notifyPaymentAttention($snapshot);

            return;
        }

        Log::critical('Capacity-backed invoice payment requires refund or credit review.', $snapshot);
    }
}
