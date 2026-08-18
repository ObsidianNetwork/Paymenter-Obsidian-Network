<?php

namespace App\Console\Commands;

use App\Services\ServiceUpgrade\ServiceUpgradeReconciliationService;
use Illuminate\Console\Command;

class ReconcileServiceUpgrade extends Command
{
    protected $signature = 'service-upgrade:reconcile
        {upgrade : Service upgrade ID}
        {action : retry, attest_completed, or refunded_not_applied}
        {--reason= : Required operator attestation}
        {--operator= : Required operator identity}';

    protected $description =
        'Safely reconcile an indeterminate static service upgrade';

    public function handle(
        ServiceUpgradeReconciliationService $reconciliation
    ): int {
        try {
            $upgrade = $reconciliation->reconcile(
                (int) $this->argument('upgrade'),
                (string) $this->argument('action'),
                (string) $this->option('reason'),
                (string) $this->option('operator')
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Upgrade {$upgrade->id} is now {$upgrade->status}; "
            . "target stock remains provably {$this->stockState($upgrade)}."
        );

        return self::SUCCESS;
    }

    private function stockState(object $upgrade): string
    {
        if (
            $upgrade->legacy_refund_only_at !== null
            || $upgrade->target_stock_reserved_at === null
        ) {
            return 'not applicable (no target-stock hold)';
        }
        if ($upgrade->target_stock_consumed_at !== null) {
            return 'consumed';
        }
        if ($upgrade->target_stock_released_at !== null) {
            return 'released';
        }

        return 'reserved';
    }
}
