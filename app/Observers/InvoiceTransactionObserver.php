<?php

namespace App\Observers;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction as InvoiceTransactionEvent;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\CapacityInvoicePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceTransactionObserver
{
    /**
     * Handle the InvoiceTransaction "creating" event.
     */
    public function creating(InvoiceTransaction $invoice): void
    {
        if (
            Schema::hasColumn(
                'invoice_transactions',
                'gateway_transaction_guard'
            )
        ) {
            $invoice->setAttribute(
                'gateway_transaction_guard',
                InvoiceTransaction::gatewayTransactionGuard(
                    $invoice->gateway_id,
                    $invoice->transaction_id
                )
            );
        }
        app(CapacityInvoicePaymentService::class)
            ->assertPaymentAttemptAllowed((int) $invoice->invoice_id);
        $this->assertAtomicSucceededPayment($invoice);
        event(new InvoiceTransactionEvent\Creating($invoice));
    }

    /**
     * Handle the InvoiceTransaction "created" event.
     */
    public function created(InvoiceTransaction $invoice): void
    {
        event(new InvoiceTransactionEvent\Created($invoice));
    }

    /**
     * Handle the InvoiceTransaction "updating" event.
     */
    public function updating(InvoiceTransaction $invoice): void
    {
        if ($invoice->isDirty(['gateway_id', 'transaction_id'])) {
            throw new \RuntimeException(
                'A persisted gateway transaction identity is immutable.'
            );
        }
        if (
            $invoice->isDirty([
                'invoice_id',
                'amount',
                'status',
                'is_credit_transaction',
            ])
            && $this->wasSucceededCapacityEvidence($invoice)
        ) {
            throw new \RuntimeException(
                'Succeeded capacity payment evidence is immutable.'
            );
        }
        $evidenceFields = [
            'invoice_id',
            'gateway_id',
            'amount',
            'fee',
            'transaction_id',
            'status',
            'is_credit_transaction',
        ];
        if ($invoice->isDirty($evidenceFields)) {
            app(CapacityInvoicePaymentService::class)
                ->assertPaymentAttemptAllowed((int) $invoice->invoice_id);
        }
        if ($invoice->isDirty('status')) {
            $this->assertAtomicSucceededPayment($invoice);
        }
        event(new InvoiceTransactionEvent\Updating($invoice));
    }

    /**
     * Handle the InvoiceTransaction "updated" event.
     */
    public function updated(InvoiceTransaction $invoice): void
    {
        event(new InvoiceTransactionEvent\Updated($invoice));
    }

    /**
     * Handle the InvoiceTransaction "deleted" event.
     */
    public function deleted(InvoiceTransaction $invoice): void
    {
        event(new InvoiceTransactionEvent\Deleted($invoice));
    }

    public function deleting(InvoiceTransaction $invoice): void
    {
        app(CapacityInvoicePaymentService::class)
            ->assertPaymentAttemptAllowed((int) $invoice->invoice_id);
        if ($this->isSucceededCapacityEvidence($invoice)) {
            throw new \RuntimeException(
                'Succeeded capacity payment evidence cannot be deleted.'
            );
        }
    }

    private function assertAtomicSucceededPayment(InvoiceTransaction $transaction): void
    {
        $status = $transaction->status instanceof InvoiceTransactionStatus
            ? $transaction->status
            : InvoiceTransactionStatus::tryFrom((string) $transaction->status);

        if (
            $status === InvoiceTransactionStatus::Succeeded
            && app(CapacityInvoicePaymentService::class)
                ->isCapacityBacked((int) $transaction->invoice_id)
            && (
                DB::transactionLevel() === 0
                || ! app(CapacityInvoicePaymentService::class)
                    ->isRecordingPaymentEvidence(
                        (int) $transaction->invoice_id
                    )
            )
        ) {
            throw new \RuntimeException(
                'Succeeded capacity invoice transactions must be recorded through the atomic payment coordinator.'
            );
        }
    }

    private function isSucceededCapacityEvidence(
        InvoiceTransaction $transaction
    ): bool {
        $status = $transaction->status instanceof InvoiceTransactionStatus
            ? $transaction->status
            : InvoiceTransactionStatus::tryFrom(
                (string) $transaction->status
            );

        return $status === InvoiceTransactionStatus::Succeeded
            && app(CapacityInvoicePaymentService::class)
                ->isCapacityBacked((int) $transaction->invoice_id);
    }

    private function wasSucceededCapacityEvidence(
        InvoiceTransaction $transaction
    ): bool {
        $status = InvoiceTransactionStatus::tryFrom(
            (string) $transaction->getRawOriginal('status')
        );

        return $status === InvoiceTransactionStatus::Succeeded
            && app(CapacityInvoicePaymentService::class)
                ->isCapacityBacked(
                    (int) $transaction->getRawOriginal('invoice_id')
                );
    }
}
