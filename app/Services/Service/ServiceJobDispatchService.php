<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Service;
use App\Models\ServiceJobDispatch;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class ServiceJobDispatchService
{
    public const ACTION_CREATE = 'create';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_UNSUSPEND = 'unsuspend';

    public const ACTION_TERMINATE = 'terminate';

    private const BATCH_SIZE = 100;

    public function requestCreate(
        Service $service,
        bool $sendNotification = true
    ): ServiceJobDispatch {
        return $this->request(
            $service,
            self::ACTION_CREATE,
            $sendNotification
        );
    }

    public function requestSuspend(
        Service $service,
        bool $sendNotification = true
    ): ServiceJobDispatch {
        return $this->request(
            $service,
            self::ACTION_SUSPEND,
            $sendNotification
        );
    }

    public function requestUnsuspend(Service $service): ServiceJobDispatch
    {
        return $this->request(
            $service,
            self::ACTION_UNSUSPEND,
            false
        );
    }

    public function requestTerminate(
        Service $service,
        bool $sendNotification = true
    ): ServiceJobDispatch {
        return $this->request(
            $service,
            self::ACTION_TERMINATE,
            $sendNotification
        );
    }

    public function request(
        Service $service,
        string $action,
        bool $sendNotification = true
    ): ServiceJobDispatch {
        // Always establish a transaction boundary, including when a caller
        // already owns a wider lifecycle transaction. Laravel retains the
        // after-commit callback until the true outer commit in production,
        // while the nested boundary keeps transactional tests faithful.
        return DB::transaction(
            fn (): ServiceJobDispatch => $this->requestLocked(
                $service,
                $action,
                $sendNotification
            ),
            5
        );
    }

    private function requestLocked(
        Service $service,
        string $action,
        bool $sendNotification
    ): ServiceJobDispatch {
        $lockedService = Service::query()
            ->whereKey($service->id)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertActionAllowed($action, $lockedService->status);

        $dispatch = ServiceJobDispatch::query()
            ->where('service_id', $lockedService->id)
            ->lockForUpdate()
            ->first();
        if (
            $dispatch !== null
            && $dispatch->action === self::ACTION_CREATE
            && !in_array(
                $action,
                [
                    self::ACTION_CREATE,
                    self::ACTION_TERMINATE,
                ],
                true
            )
        ) {
            throw new \RuntimeException(
                "Cannot queue server {$action} while server creation is still pending."
            );
        }
        $exactGeneration = $dispatch !== null
            && $dispatch->action === $action
            && $dispatch->expected_status === $lockedService->status
            && (bool) $dispatch->send_notification === $sendNotification;
        if (!$exactGeneration) {
            $dispatch ??= new ServiceJobDispatch;
            $dispatch->fill([
                'service_id' => $lockedService->id,
                'action' => $action,
                'expected_status' => $lockedService->status,
                'dispatch_token' => (string) Str::uuid(),
                'send_notification' => $sendNotification,
                'dispatch_attempts' => 0,
                'available_at' => now(),
                'last_dispatched_at' => null,
                'last_error' => null,
            ]);
            $dispatch->save();
        }

        $dispatchId = (int) $dispatch->id;
        $dispatchToken = (string) $dispatch->dispatch_token;
        DB::afterCommit(
            fn () => $this->dispatchSafely(
                $dispatchId,
                $dispatchToken
            )
        );

        return $dispatch;
    }

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

        ServiceJobDispatch::query()
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(
                self::BATCH_SIZE,
                function ($dispatches) use (&$summary): void {
                    foreach ($dispatches as $dispatch) {
                        $summary['scanned']++;
                        try {
                            if ($this->dispatchById((int) $dispatch->id)) {
                                $summary['dispatched']++;
                            } else {
                                $summary['skipped']++;
                            }
                        } catch (Throwable $exception) {
                            $summary['failed']++;
                            Log::error(
                                'Failed to recover a server lifecycle queue dispatch.',
                                [
                                    'service_job_dispatch_id' => (int) $dispatch->id,
                                    'exception' => $exception,
                                ]
                            );
                        }
                    }
                }
            );

        return $summary;
    }

    public function dispatchById(
        int $dispatchId,
        ?string $expectedToken = null
    ): bool {
        $dispatch = ServiceJobDispatch::query()
            ->whereKey($dispatchId)
            ->when(
                $expectedToken !== null,
                fn ($query) => $query->where(
                    'dispatch_token',
                    $expectedToken
                )
            )
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->first();
        if ($dispatch === null) {
            return false;
        }

        $service = Service::query()->find($dispatch->service_id);
        if (
            $service === null
            || $service->status !== $dispatch->expected_status
            || !$this->actionAllowed(
                $dispatch->action,
                $service->status
            )
        ) {
            ServiceJobDispatch::query()
                ->whereKey($dispatch->id)
                ->where(
                    'dispatch_token',
                    (string) $dispatch->dispatch_token
                )
                ->delete();

            return false;
        }

        $job = $this->makeJob($dispatch, $service);
        $uniqueLock = $this->uniqueLock();
        if (!$uniqueLock->acquire($job)) {
            return false;
        }

        try {
            Queue::push($job);
            ServiceJobDispatch::query()
                ->whereKey($dispatch->id)
                ->where('dispatch_token', $dispatch->dispatch_token)
                ->update([
                    'dispatch_attempts' => DB::raw(
                        'dispatch_attempts + 1'
                    ),
                    'last_dispatched_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            $this->releaseUniqueLock($uniqueLock, $job);
            ServiceJobDispatch::query()
                ->whereKey($dispatch->id)
                ->where('dispatch_token', $dispatch->dispatch_token)
                ->update([
                    'available_at' => now()->addMinute(),
                    'last_error' => mb_substr(
                        $exception->getMessage(),
                        0,
                        65535
                    ),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return true;
    }

    /**
     * Run one exact outbox generation while holding the service row across the
     * remote action. A newer lifecycle request supersedes the token, and a
     * changed service state makes the old job a safe no-op.
     *
     * @return array{executed: bool, service: Service|null, result: mixed}
     */
    public function run(
        Service $serializedService,
        string $action,
        ?int $dispatchId,
        ?string $dispatchToken,
        Closure $operation
    ): array {
        // Do not enable automatic transaction retries here: the closure may
        // have completed an external server mutation before a local deadlock
        // is reported. The queue retry/reconciliation path owns any retry.
        return DB::transaction(function () use (
            $action,
            $dispatchId,
            $dispatchToken,
            $operation,
            $serializedService
        ): array {
            $service = Service::query()
                ->whereKey($serializedService->id)
                ->lockForUpdate()
                ->first();
            if ($service === null) {
                return [
                    'executed' => false,
                    'service' => null,
                    'result' => null,
                ];
            }

            $dispatch = null;
            if ($dispatchId !== null && $dispatchToken !== null) {
                $dispatch = ServiceJobDispatch::query()
                    ->whereKey($dispatchId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $dispatch === null
                    || !hash_equals(
                        (string) $dispatch->dispatch_token,
                        $dispatchToken
                    )
                    || $dispatch->action !== $action
                    || (int) $dispatch->service_id
                        !== (int) $serializedService->id
                ) {
                    return [
                        'executed' => false,
                        'service' => null,
                        'result' => null,
                    ];
                }
            }

            if (
                !$this->actionAllowed($action, $service->status)
                || (
                    $dispatch !== null
                    && $dispatch->expected_status !== $service->status
                )
            ) {
                $dispatch?->delete();

                return [
                    'executed' => false,
                    'service' => $service,
                    'result' => null,
                ];
            }

            $result = $operation($service);
            $dispatch?->delete();

            return [
                'executed' => true,
                'service' => $service,
                'result' => $result,
            ];
        });
    }

    public function postponeFailure(
        ?int $dispatchId,
        ?string $dispatchToken,
        Throwable $exception
    ): void {
        if ($dispatchId === null || $dispatchToken === null) {
            return;
        }

        ServiceJobDispatch::query()
            ->whereKey($dispatchId)
            ->where('dispatch_token', $dispatchToken)
            ->update([
                'available_at' => now()->addHour(),
                'last_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    65535
                ),
                'updated_at' => now(),
            ]);
    }

    public function abandon(
        ?int $dispatchId,
        ?string $dispatchToken
    ): void {
        if ($dispatchId === null || $dispatchToken === null) {
            return;
        }

        ServiceJobDispatch::query()
            ->whereKey($dispatchId)
            ->where('dispatch_token', $dispatchToken)
            ->delete();
    }

    private function dispatchSafely(
        int $dispatchId,
        string $dispatchToken
    ): void {
        try {
            $this->dispatchById($dispatchId, $dispatchToken);
        } catch (Throwable $exception) {
            Log::error(
                'The server lifecycle transition committed before its queue dispatch succeeded; scheduled recovery will retry it.',
                [
                    'service_job_dispatch_id' => $dispatchId,
                    'exception' => $exception,
                ]
            );
        }
    }

    private function makeJob(
        ServiceJobDispatch $dispatch,
        Service $service
    ): ShouldQueue {
        $arguments = [
            $service,
            (bool) $dispatch->send_notification,
            (int) $dispatch->id,
            (string) $dispatch->dispatch_token,
        ];

        return match ($dispatch->action) {
            self::ACTION_CREATE => new CreateJob(...$arguments),
            self::ACTION_SUSPEND => new SuspendJob(...$arguments),
            self::ACTION_UNSUSPEND => new UnsuspendJob(
                $service,
                (int) $dispatch->id,
                (string) $dispatch->dispatch_token
            ),
            self::ACTION_TERMINATE => new TerminateJob(...$arguments),
            default => throw new \RuntimeException(
                "Unsupported service job action {$dispatch->action}."
            ),
        };
    }

    private function releaseUniqueLock(
        UniqueLock $uniqueLock,
        ShouldQueue $job
    ): void {
        try {
            $uniqueLock->release($job);
        } catch (Throwable $exception) {
            Log::warning(
                'Failed to release a server lifecycle queue uniqueness lock.',
                [
                    'job' => $job::class,
                    'exception' => $exception,
                ]
            );
        }
    }

    private function uniqueLock(): UniqueLock
    {
        return new UniqueLock(app('cache')->store());
    }

    private function assertActionAllowed(
        string $action,
        string $status
    ): void {
        if (!$this->actionAllowed($action, $status)) {
            throw new \RuntimeException(
                "Cannot queue server {$action} while the service is {$status}."
            );
        }
    }

    private function actionAllowed(string $action, string $status): bool
    {
        return match ($action) {
            self::ACTION_CREATE => in_array(
                $status,
                [
                    Service::STATUS_PENDING,
                    Service::STATUS_PROVISIONING,
                    Service::STATUS_PROVISIONING_FAILED,
                    Service::STATUS_ACTIVE,
                ],
                true
            ),
            self::ACTION_SUSPEND => $status === Service::STATUS_SUSPENDED,
            self::ACTION_UNSUSPEND => $status === Service::STATUS_ACTIVE,
            self::ACTION_TERMINATE => in_array(
                $status,
                [
                    Service::STATUS_CANCELLED,
                    Service::STATUS_CANCELLATION_PENDING,
                ],
                true
            ),
            default => false,
        };
    }
}
