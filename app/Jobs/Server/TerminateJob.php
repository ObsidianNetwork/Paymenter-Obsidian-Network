<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\ProductStockService;
use App\Services\Service\ServiceJobDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class TerminateJob implements ShouldBeUnique, ShouldQueue
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
            'terminate',
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
        $fulfillment = app(DurableFulfillmentService::class);
        $execution = $dispatches->run(
            $this->service,
            ServiceJobDispatchService::ACTION_TERMINATE,
            $this->dispatchId,
            $this->dispatchToken,
            function (Service $service) use ($fulfillment): array {
                $this->service = $service;
                if (
                    $fulfillment
                        ->cancellationIsDurablyComplete($this->service)
                ) {
                    return ['notify' => false, 'data' => []];
                }
                // Do not delete the external server unless the owner of its
                // durable reservation is available to record the terminal
                // transition.
                $fulfillment->assertRuntimeAvailable($this->service);

                $data = [];
                try {
                    $data = ExtensionHelper::terminateServer($this->service);
                } catch (Throwable $e) {
                    if ($e->getMessage() !== 'No server assigned to this product') {
                        throw $e;
                    }
                }

                $fulfillment->completeCancellation($this->service);
                app(ProductStockService::class)->release($this->service);

                return [
                    'notify' => true,
                    'data' => is_array($data) ? $data : [],
                ];
            }
        );
        if (
            !$execution['executed']
            || !($execution['result']['notify'] ?? false)
        ) {
            return;
        }

        if ($this->sendNotification) {
            $this->service = $execution['service']->fresh();
            NotificationHelper::serverTerminatedNotification(
                $this->service->user,
                $this->service,
                $execution['result']['data']
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        app(ServiceJobDispatchService::class)->postponeFailure(
            $this->dispatchId,
            $this->dispatchToken,
            $exception
        );

        $fulfillment = app(DurableFulfillmentService::class);
        if (!$fulfillment->isReservationBacked($this->service)) {
            return;
        }

        $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $recordingError = null;
        if (class_exists($reservationServiceClass) && $this->service->exists) {
            try {
                $recorded = app($reservationServiceClass)
                    ->recordPermanentCancellationFailure(
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
            'operation' => 'cancellation',
            'service_id' => (int) $this->service->id,
            'error' => $exception->getMessage(),
            'recording_error' => $recordingError,
        ];
        Log::critical(
            'Reservation-backed server cancellation requires operator intervention.',
            $context
        );
        try {
            NotificationHelper::sendSystemEmailNotification(
                'Reservation-backed server cancellation failed',
                '<p>A server cancellation exhausted its retries while its durable '
                    . 'fulfillment runtime was unavailable or unable to record the '
                    . 'terminal state.</p><p>Service ID: '
                    . (int) $this->service->id . '</p><p>Error: '
                    . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
                    . '</p><p>The service remains cancellation-pending and its '
                    . 'product stock remains held. Reconcile the external server '
                    . 'before completing cancellation.</p>'
            );
        } catch (Throwable $alertException) {
            Log::error(
                'Failed to email the operator about a reservation-backed cancellation.',
                [...$context, 'alert_error' => $alertException->getMessage()]
            );
        }
    }
}
