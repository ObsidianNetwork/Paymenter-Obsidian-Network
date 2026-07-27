<?php

namespace App\Console\Commands;

use App\Services\ServiceUpgrade\ServiceUpgradeDispatchRecoveryService;
use Illuminate\Console\Command;

class RecoverServiceUpgradeDispatches extends Command
{
    protected $signature = 'paymenter:recover-service-upgrade-dispatches';

    protected $description = 'Requeue paid or retryable service upgrades that have no recoverable queue delivery';

    public function handle(
        ServiceUpgradeDispatchRecoveryService $recovery
    ): int {
        $summary = $recovery->recover();

        $this->line(sprintf(
            'Scanned %d upgrade(s): %d dispatched, %d skipped, %d failed.',
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
