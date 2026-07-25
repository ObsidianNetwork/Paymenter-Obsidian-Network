<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created;
use App\Events\InvoiceTransaction\Updated;
use App\Services\Invoice\MarkInvoicePaidService;

class InvoiceTransactionCreatedListener
{
    /**
     * Handle the event.
     */
    public function handle(Created|Updated $event): void
    {
        $transaction = $event->invoiceTransaction;
        if ($transaction->status !== InvoiceTransactionStatus::Succeeded) {
            return;
        }

        $invoice = $transaction->invoice;
        if ($invoice->remaining <= 0 && $invoice->status !== 'paid') {
            app(MarkInvoicePaidService::class)->handle($invoice);
        }
    }
}
