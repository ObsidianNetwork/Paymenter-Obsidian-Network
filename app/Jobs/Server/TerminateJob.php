<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\ProductStockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class TerminateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 8;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

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
    public function handle(): void
    {
        $data = [];
        $fulfillment = app(DurableFulfillmentService::class);
        $freshService = $this->service->fresh();
        if ($freshService === null) {
            return;
        }
        $this->service = $freshService;
        if (
            $fulfillment->cancellationIsDurablyComplete($this->service)
        ) {
            return;
        }
        // Do not delete the external server unless the owner of its durable
        // reservation is available to record the terminal transition.
        $fulfillment->assertRuntimeAvailable($this->service);

        try {
            $data = ExtensionHelper::terminateServer($this->service);
        } catch (Throwable $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        $fulfillment->completeCancellation($this->service);
        app(ProductStockService::class)->release($this->service);

        if ($this->sendNotification) {
            NotificationHelper::serverTerminatedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }

    public function failed(Throwable $exception): void
    {
        $fulfillment = app(DurableFulfillmentService::class);
        if (! $fulfillment->isReservationBacked($this->service)) {
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
                    .'fulfillment runtime was unavailable or unable to record the '
                    .'terminal state.</p><p>Service ID: '
                    .(int) $this->service->id.'</p><p>Error: '
                    .htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
                    .'</p><p>The service remains cancellation-pending and its '
                    .'product stock remains held. Reconcile the external server '
                    .'before completing cancellation.</p>'
            );
        } catch (Throwable $alertException) {
            Log::error(
                'Failed to email the operator about a reservation-backed cancellation.',
                [...$context, 'alert_error' => $alertException->getMessage()]
            );
        }
    }
}
