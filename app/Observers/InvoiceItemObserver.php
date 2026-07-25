<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Models\InvoiceItem;
use App\Services\Invoice\CapacityInvoicePaymentService;

class InvoiceItemObserver
{
    /**
     * Handle the InvoiceItem "creating" event.
     */
    public function creating(InvoiceItem $invoice): void
    {
        if (
            $invoice->invoice_id !== null
            && app(CapacityInvoicePaymentService::class)
                ->isCapacityBacked((int) $invoice->invoice_id)
        ) {
            throw new \RuntimeException(
                'Capacity-backed invoice fulfillment lines are immutable.'
            );
        }
        event(new InvoiceItemEvent\Creating($invoice));
    }

    /**
     * Handle the InvoiceItem "created" event.
     */
    public function created(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Created($invoice));
    }

    /**
     * Handle the InvoiceItem "updating" event.
     */
    public function updating(InvoiceItem $invoice): void
    {
        $sourceInvoiceId = $invoice->getRawOriginal('invoice_id');
        $destinationInvoiceId = $invoice->invoice_id;
        $payments = app(CapacityInvoicePaymentService::class);
        if (
            $invoice->isDirty([
                'invoice_id',
                'quantity',
                'price',
                'reference_id',
                'reference_type',
            ])
            && (
                (
                    $sourceInvoiceId !== null
                    && $payments->isCapacityBacked(
                        (int) $sourceInvoiceId
                    )
                )
                || (
                    $destinationInvoiceId !== null
                    && $payments->isCapacityBacked(
                        (int) $destinationInvoiceId
                    )
                )
            )
        ) {
            throw new \RuntimeException(
                'Capacity-backed invoice fulfillment lines are immutable.'
            );
        }

        event(new InvoiceItemEvent\Updating($invoice));
    }

    public function deleting(InvoiceItem $invoice): void
    {
        if (
            $invoice->invoice !== null
            && app(CapacityInvoicePaymentService::class)
                ->isCapacityBacked($invoice->invoice)
        ) {
            throw new \RuntimeException(
                'Capacity-backed invoice fulfillment lines cannot be deleted.'
            );
        }
    }

    /**
     * Handle the InvoiceItem "updated" event.
     */
    public function updated(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Updated($invoice));
    }

    /**
     * Handle the InvoiceItem "deleted" event.
     */
    public function deleted(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Deleted($invoice));
    }
}
