<?php

namespace App\Console\Commands;

use App\Services\Invoice\BillingChargeAttemptService;
use Illuminate\Console\Command;

class RecoverBillingChargeAttempts extends Command
{
    protected $signature = 'paymenter:recover-billing-charge-attempts';

    protected $description = 'Recover durable saved-payment-method renewal charges';

    public function handle(BillingChargeAttemptService $attempts): int
    {
        $summary = $attempts->recover();

        $this->line(sprintf(
            'Scanned %d billing charge attempt(s): %d dispatched, %d skipped, %d failed.',
            $summary['scanned'],
            $summary['dispatched'],
            $summary['skipped'],
            $summary['failed']
        ));

        return $summary['failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
