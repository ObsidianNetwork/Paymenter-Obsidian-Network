<?php

namespace App\Jobs\Server;

use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateJob implements ShouldQueue
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
        $this->service = $this->service->fresh();
        if (! $this->service) {
            return;
        }
        if (
            in_array($this->service->status, [
                Service::STATUS_CANCELLED,
                Service::STATUS_CANCELLATION_PENDING,
            ], true)
            || $this->service->cancellation()->where('type', 'immediate')->exists()
        ) {
            return;
        }

        $data = [];
        // $data is the data that will be used to send the email, data is coming from the extension itself
        try {
            $data = ExtensionHelper::createServer($this->service);
        } catch (PermanentProvisioningException $e) {
            $this->failed($e);
            $this->fail($e);

            return;
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        $this->service->refresh();
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
        $fulfillment = app(DurableFulfillmentService::class);
        if (
            ! $this->service->exists
            || ! $fulfillment->isReservationBacked($this->service)
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
                    .'fulfillment runtime was unavailable or unable to record the '
                    .'failure state.</p><p>Service ID: '
                    .(int) $this->service->id.'</p><p>Error: '
                    .htmlspecialchars(
                        $exception->getMessage(),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    .'</p><p>The paid capacity remains held. Restore the Dynamic '
                    .'Pterodactyl runtime and reconcile this service before any '
                    .'manual provisioning or cancellation.</p>'
            );
        } catch (Throwable $alertException) {
            Log::error(
                'Failed to email the operator about reservation-backed provisioning.',
                [...$context, 'alert_error' => $alertException->getMessage()]
            );
        }
    }
}
