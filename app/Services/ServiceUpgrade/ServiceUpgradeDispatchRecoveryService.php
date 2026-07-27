<?php

namespace App\Services\ServiceUpgrade;

use App\Jobs\Server\UpgradeJob;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ServiceUpgradeDispatchRecoveryService
{
    private const BATCH_SIZE = 100;

    private const STALE_PROVISIONING_MINUTES = 10;

    /**
     * @return array{scanned: int, dispatched: int, skipped: int, failed: int}
     */
    public function recover(): array
    {
        $summary = [
            'scanned' => 0,
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        ServiceUpgrade::query()
            ->where(function ($query): void {
                $query->whereIn(
                    'status',
                    self::dispatchableStatuses()
                )->orWhere(function ($query): void {
                    $query->where(
                        'status',
                        ServiceUpgrade::STATUS_PROVISIONING
                    )->where(function ($query): void {
                        $query->whereNull('provisioning_started_at')
                            ->orWhere(
                                'provisioning_started_at',
                                '<=',
                                now()->subMinutes(
                                    self::STALE_PROVISIONING_MINUTES
                                )
                            );
                    });
                });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(
                self::BATCH_SIZE,
                function ($upgrades) use (&$summary): void {
                    foreach ($upgrades as $upgrade) {
                        $upgradeId = (int) $upgrade->id;
                        $summary['scanned']++;

                        try {
                            if ($this->dispatchById($upgradeId)) {
                                $summary['dispatched']++;
                            } else {
                                $summary['skipped']++;
                            }
                        } catch (Throwable $exception) {
                            $summary['failed']++;
                            Log::error(
                                'Failed to recover a service upgrade queue dispatch.',
                                [
                                    'service_upgrade_id' => $upgradeId,
                                    'exception' => $exception,
                                ]
                            );
                        }
                    }
                }
            );

        return $summary;
    }

    public function dispatchById(int $upgradeId): bool
    {
        $candidate = $this->prepareForDispatch($upgradeId);
        if ($candidate === null) {
            return false;
        }
        /** @var ServiceUpgrade $upgrade */
        $upgrade = $candidate['upgrade'];
        if ($candidate['recovered_stale']) {
            // A hard-killed worker cannot release ShouldBeUnique. Once both
            // the queue timeout and overlap lease are well past, clear that
            // stale lock before creating the one recoverable redelivery.
            $this->releaseUniqueLock(
                $this->uniqueLock(),
                new UpgradeJob($upgrade)
            );
        }

        return $this->dispatchUpgrade($upgrade);
    }

    /**
     * @return array{upgrade: ServiceUpgrade, recovered_stale: bool}|null
     */
    private function prepareForDispatch(int $upgradeId): ?array
    {
        return DB::transaction(function () use ($upgradeId): ?array {
            $serviceId = (int) ServiceUpgrade::query()
                ->whereKey($upgradeId)
                ->value('service_id');
            if ($serviceId <= 0) {
                return null;
            }

            // Match the runtime lifecycle lock order.
            Service::query()
                ->whereKey($serviceId)
                ->lockForUpdate()
                ->first();
            $upgrade = ServiceUpgrade::query()
                ->with(['service.product', 'product'])
                ->whereKey($upgradeId)
                ->lockForUpdate()
                ->first();
            if ($upgrade === null) {
                return null;
            }
            if (in_array(
                $upgrade->status,
                self::dispatchableStatuses(),
                true
            )) {
                return [
                    'upgrade' => $upgrade,
                    'recovered_stale' => false,
                ];
            }
            if (
                $upgrade->status !== ServiceUpgrade::STATUS_PROVISIONING
                || (
                    $upgrade->provisioning_started_at !== null
                    && $upgrade->provisioning_started_at->gt(
                        now()->subMinutes(
                            self::STALE_PROVISIONING_MINUTES
                        )
                    )
                )
            ) {
                return null;
            }

            $mode = data_get(
                $upgrade->target_snapshot,
                'provisioner.mode'
            );
            $dynamic = app(
                CapacityUpgradeReservationIdentity::class
            )->requiresCoordinator($upgrade);
            if (
                !$upgrade->snapshotFingerprintsAreAuthentic()
                || !in_array($mode, ['external', 'serverless'], true)
            ) {
                $this->markAttention(
                    $upgrade,
                    'A stale provisioning attempt has no authentic provisioner identity.'
                );

                return null;
            }
            if ($mode === 'external' && !$dynamic) {
                $this->markAttention(
                    $upgrade,
                    'The worker stopped after an external upgrade may have been applied. Automatic retry is unsafe; reconcile the provider before finalizing or refunding.'
                );

                return null;
            }

            ServiceUpgradeMutationCoordinator::run(
                $upgrade,
                fn () => $upgrade->forceFill([
                    'status' => ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                    'provisioning_started_at' => null,
                    'last_error' => 'Recovered a stale retry-safe provisioning attempt.',
                    'failed_at' => now(),
                ])->save()
            );

            return [
                'upgrade' => $upgrade->fresh(),
                'recovered_stale' => true,
            ];
        }, 5);
    }

    private function markAttention(
        ServiceUpgrade $upgrade,
        string $message
    ): void {
        ServiceUpgradeMutationCoordinator::run(
            $upgrade,
            fn () => $upgrade->forceFill([
                'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                'active_service_guard_id' => $upgrade->service_id,
                'last_error' => $message,
                'failed_at' => now(),
            ])->save()
        );
    }

    protected function dispatchUpgrade(ServiceUpgrade $upgrade): bool
    {
        $job = new UpgradeJob($upgrade);
        $uniqueLock = $this->uniqueLock();
        if (!$uniqueLock->acquire($job)) {
            return false;
        }

        try {
            Queue::push($job);
        } catch (Throwable $exception) {
            $this->releaseUniqueLock($uniqueLock, $job);

            throw $exception;
        }

        return true;
    }

    /**
     * A queue push can fail after Laravel acquires the unique-job lock. Release
     * it so the next scheduled recovery pass is not delayed for two hours.
     */
    private function releaseUniqueLock(
        UniqueLock $uniqueLock,
        UpgradeJob $job
    ): void {
        try {
            $uniqueLock->release($job);
        } catch (Throwable $exception) {
            Log::warning(
                'Failed to release a service upgrade queue uniqueness lock.',
                [
                    'service_upgrade_id' => (int) $job->serviceUpgrade->getKey(),
                    'exception' => $exception,
                ]
            );
        }
    }

    private function uniqueLock(): UniqueLock
    {
        return new UniqueLock(app('cache')->store());
    }

    /**
     * @return array<int, string>
     */
    private static function dispatchableStatuses(): array
    {
        return [
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            ServiceUpgrade::STATUS_RETRYABLE_FAILED,
        ];
    }
}
