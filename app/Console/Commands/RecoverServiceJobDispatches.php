<?php

namespace App\Console\Commands;

use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Console\Command;

class RecoverServiceJobDispatches extends Command
{
    protected $signature = 'paymenter:recover-service-job-dispatches';

    protected $description = 'Recover durable create, suspend, unsuspend, and terminate queue dispatches';

    public function handle(ServiceJobDispatchService $dispatches): int
    {
        $summary = $dispatches->recover();

        $this->line(sprintf(
            'Scanned %d service dispatch(es): %d dispatched, %d skipped, %d failed.',
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
