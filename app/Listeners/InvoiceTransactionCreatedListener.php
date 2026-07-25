<?php

namespace App\Listeners;

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
        $invoice = $event->invoiceTransaction->invoice;
        if ($invoice->remaining <= 0 && $invoice->status !== 'paid') {
            app(MarkInvoicePaidService::class)->handle($invoice);
        }
    }
}
