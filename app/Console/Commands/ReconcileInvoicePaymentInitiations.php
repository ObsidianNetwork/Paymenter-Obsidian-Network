<?php

namespace App\Console\Commands;

use App\Services\Invoice\InvoicePaymentInitiationService;
use Illuminate\Console\Command;

class ReconcileInvoicePaymentInitiations extends Command
{
    protected $signature =
        'paymenter:reconcile-invoice-payment-initiations';

    protected $description =
        'Reconcile durable interactive provider payments and release terminal abandoned claims';

    public function handle(
        InvoicePaymentInitiationService $initiations
    ): int {
        $summary = $initiations->recover();

        $this->line(sprintf(
            'Scanned %d provider initiation(s): %d reconciled, %d released, %d skipped, %d failed.',
            $summary['scanned'],
            $summary['reconciled'],
            $summary['released'],
            $summary['skipped'],
            $summary['failed']
        ));

        return $summary['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
