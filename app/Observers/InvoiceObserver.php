<?php

namespace App\Observers;

use App\Events\Invoice as InvoiceEvent;
use App\Models\Invoice;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\MarkInvoicePaidService;
use App\Services\Invoice\ProcessPaidInvoiceService;

class InvoiceObserver
{
    /**
     * Handle the Invoice "creating" event.
     */
    public function creating(Invoice $invoice): void
    {
        event(new InvoiceEvent\Creating($invoice));
    }

    /**
     * Handle the Invoice "created" event.
     */
    public function created(Invoice $invoice): void
    {
        event(new InvoiceEvent\Created($invoice));

        $sendEmail = $invoice->send_create_email;

        dispatch(function () use ($invoice, $sendEmail) {
            event(new InvoiceEvent\Finalized($invoice, $sendEmail));
        })->afterResponse();
    }

    /**
     * Handle the Invoice "updating" event.
     */
    public function updating(Invoice $invoice): void
    {
        if (
            $invoice->isDirty(['user_id', 'currency_code', 'due_at'])
            && app(CapacityInvoicePaymentService::class)
                ->requiresFulfillmentCoordinator($invoice)
        ) {
            throw new \RuntimeException(
                'Durable-fulfillment invoice ownership, currency, and deadline are immutable.'
            );
        }

        if (
            $invoice->isDirty('status')
            && $invoice->status === Invoice::STATUS_PAID
            && app(CapacityInvoicePaymentService::class)
                ->requiresFulfillmentCoordinator($invoice)
            && !MarkInvoicePaidService::isCoordinating($invoice)
        ) {
            throw new \RuntimeException(
                'Invoices must be marked paid through the fulfillment coordinator.'
            );
        }
        if (
            $invoice->isDirty('status')
            && $invoice->status === Invoice::STATUS_CANCELLED
            && app(CapacityInvoicePaymentService::class)
                ->requiresFulfillmentCoordinator($invoice)
            && !CancelInvoiceService::isCoordinating($invoice)
        ) {
            throw new \RuntimeException(
                'Capacity-backed and capacity-renewal invoices must be cancelled through the fulfillment coordinator.'
            );
        }

        event(new InvoiceEvent\Updating($invoice));
    }

    public function deleting(Invoice $invoice): void
    {
        app(CancelInvoiceService::class)->assertCanDelete($invoice);
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        if ($invoice->isDirty('status') && $invoice->status == 'paid') {
            app(ProcessPaidInvoiceService::class)->handle($invoice);
            event(new InvoiceEvent\Paid($invoice));
        }
        event(new InvoiceEvent\Updated($invoice));
    }

    /**
     * Handle the Invoice "deleted" event.
     */
    public function deleted(Invoice $invoice): void
    {
        event(new InvoiceEvent\Deleted($invoice));
    }
}
