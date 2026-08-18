<?php

namespace App\Jobs\Server;

use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\ServiceJobDispatchService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 8;

    public $uniqueFor = 43200;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Service $service,
        public $sendNotification = true,
        public ?int $dispatchId = null,
        public ?string $dispatchToken = null
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [
            'create',
            $this->service->id,
            $this->dispatchToken ?? 'legacy',
        ]);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('server-fulfillment:' . $this->service->id))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function backoff(): array
    {
        return [15, 60, 300, 900, 1800, 3600, 10800];
    }

    /**
     * Execute the job.
     */
    public function handle(ServiceJobDispatchService $dispatches): void
    {
        $execution = $dispatches->run(
            $this->service,
            ServiceJobDispatchService::ACTION_CREATE,
            $this->dispatchId,
            $this->dispatchToken,
            function (Service $service): array {
                $this->service = $service;
                if (
                    $this->service->cancellation()
                        ->where('type', 'immediate')
                        ->exists()
                ) {
                    return ['created' => false, 'data' => []];
                }

                $data = [];
                try {
                    $data = ExtensionHelper::createServer($this->service);
                } catch (PermanentProvisioningException $e) {
                    $this->failed($e);
                    $this->fail($e);

                    return ['created' => false, 'data' => []];
                } catch (Exception $e) {
                    if ($e->getMessage() !== 'No server assigned to this product') {
                        throw $e;
                    }
                }

                return [
                    'created' => true,
                    'data' => is_array($data) ? $data : [],
                ];
            }
        );
        if (
            !$execution['executed']
            || !($execution['result']['created'] ?? false)
        ) {
            return;
        }

        $this->service = $execution['service']->fresh();
        $data = $execution['result']['data'];
        if ($this->sendNotification && $this->service->status === Service::STATUS_ACTIVE) {
            $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
            $notificationPending = null;
            if (class_exists($reservationServiceClass)) {
                $reservationService = app($reservationServiceClass);
                $notificationPending = method_exists($reservationService, 'customerNotificationPending')
                    ? $reservationService->customerNotificationPending($this->service->id)
                    : null;
                if ($notificationPending === false) {
                    return;
                }
            }
            NotificationHelper::serverCreatedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
            if ($notificationPending === true) {
                $reservationService->markCustomerNotified($this->service->id);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $dispatches = app(ServiceJobDispatchService::class);
        if ($exception instanceof PermanentProvisioningException) {
            $dispatches->abandon(
                $this->dispatchId,
                $this->dispatchToken
            );
        } else {
            $dispatches->postponeFailure(
                $this->dispatchId,
                $this->dispatchToken,
                $exception
            );
        }

        $fulfillment = app(DurableFulfillmentService::class);
        if (
            !$this->service->exists
            || !$fulfillment->isReservationBacked($this->service)
        ) {
            return;
        }

        $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $recordingError = null;
        if (class_exists($reservationServiceClass)) {
            try {
                $recorded = app($reservationServiceClass)
                    ->recordPermanentProvisioningFailure(
                        $this->service->fresh() ?? $this->service,
                        $exception
                    );
                if ($recorded !== null) {
                    return;
                }
            } catch (Throwable $recordingException) {
                $recordingError = $recordingException->getMessage();
            }
        }

        $context = [
            'operation' => 'provisioning',
            'service_id' => (int) $this->service->id,
            'error' => $exception->getMessage(),
            'recording_error' => $recordingError,
        ];
        Log::critical(
            'Reservation-backed server provisioning requires operator intervention.',
            $context
        );
        try {
            NotificationHelper::sendSystemEmailNotification(
                'Reservation-backed server provisioning failed',
                '<p>Server provisioning exhausted its retries while its durable '
                    . 'fulfillment runtime was unavailable or unable to record the '
                    . 'failure state.</p><p>Service ID: '
                    . (int) $this->service->id . '</p><p>Error: '
                    . htmlspecialchars(
                        $exception->getMessage(),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '</p><p>The paid capacity remains held. Restore the Dynamic '
                    . 'Pterodactyl runtime and reconcile this service before any '
                    . 'manual provisioning or cancellation.</p>'
            );
        } catch (Throwable $alertException) {
            Log::error(
                'Failed to email the operator about reservation-backed provisioning.',
                [...$context, 'alert_error' => $alertException->getMessage()]
            );
        }
    }
}
