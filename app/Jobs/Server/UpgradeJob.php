<?php

namespace App\Jobs\Server;

use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Models\ServiceUpgrade;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class UpgradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 5;

    private ?string $ownedReservationLeaseId = null;

    public function __construct(
        public ServiceUpgrade $serviceUpgrade,
        public bool $sendNotification = true
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'server-fulfillment:'.$this->serviceUpgrade->service_id
            ))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(ServiceUpgradeService $upgrades): void
    {
        $upgrade = $this->serviceUpgrade;
        $leaseId = null;

        try {
            $upgrade = $upgrades->beginProvisioning($this->serviceUpgrade);
            if ($upgrade === null) {
                return;
            }

            $properties = (array) data_get($upgrade->target_snapshot, 'properties', []);
            if ($this->usesDynamicCapacity($upgrade)) {
                $reservation = $this->capacityService()->beginProvisioning($upgrade);
                $leaseId = (string) $reservation['provisioning_lease_id'];
                $this->ownedReservationLeaseId = $leaseId;
                $properties['node'] = (int) $reservation['node_id'];
                $properties['location'] = (int) $reservation['location_id'];
                $properties['_dynamic_upgrade'] = [
                    'panel_identity' => $reservation['panel_identity'],
                    'node_id' => (int) $reservation['node_id'],
                    'external_server_id' => (int) $reservation['external_server_id'],
                    'external_server_uuid' =>
                        (string) $reservation['external_server_uuid'],
                    'external_server_identifier' =>
                        (string) $reservation['external_server_identifier'],
                    'external_server_external_id' =>
                        (string) $reservation['external_server_external_id'],
                    'external_user_id' =>
                        (int) $reservation['external_user_id'],
                    'user_external_id' =>
                        (string) $reservation['user_external_id'],
                    'user_email' => (string) $reservation['user_email'],
                    'nest_id' => (int) $reservation['nest_id'],
                    'egg_id' => (int) $reservation['egg_id'],
                    'preserved_build' => (array) (
                        $reservation['preserved_build'] ?? []
                    ),
                    'allocation_id' => (int) $reservation['allocation_id'],
                    'assigned_allocation_ids' => array_values(array_map(
                        'intval',
                        (array) $reservation['assigned_allocation_ids']
                    )),
                    'source' => (array) $reservation['source'],
                    'target' => (array) $reservation['target'],
                ];
            }

            try {
                ExtensionHelper::upgradeServer(
                    $upgrade->service,
                    $upgrade->product,
                    $properties
                );
            } catch (\Throwable $exception) {
                if (
                    ! $this->usesDynamicCapacity($upgrade)
                    && $exception->getMessage() === 'No server assigned to this product'
                ) {
                    // Legacy configuration-only products have no external server.
                } else {
                    throw $exception;
                }
            }

            $upgrades->complete($upgrade, $leaseId);
        } catch (PermanentProvisioningException $exception) {
            $upgrades->recordFailure($upgrade, $exception, true, $leaseId);
            $this->fail($exception);

            return;
        } catch (\Throwable $exception) {
            if ($this->isPermanentClientFailure($exception)) {
                $upgrades->recordFailure($upgrade, $exception, true, $leaseId);
                $this->fail($exception);

                return;
            }

            $upgrades->recordFailure($upgrade, $exception, false, $leaseId);

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        app(ServiceUpgradeService::class)->recordFailure(
            $this->serviceUpgrade,
            $exception,
            true,
            $this->ownedReservationLeaseId
        );
    }

    private function usesDynamicCapacity(ServiceUpgrade $upgrade): bool
    {
        return $upgrade->service->product->usesDynamicResources()
            || $upgrade->product->usesDynamicResources();
    }

    private function capacityService(): object
    {
        $class = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        if (! class_exists($class)) {
            throw new \RuntimeException(
                'Dynamic upgrade reservation support is unavailable.'
            );
        }

        return app($class);
    }

    private function isPermanentClientFailure(\Throwable $exception): bool
    {
        $status = (int) $exception->getCode();

        return $status >= 400
            && $status < 500
            && ! in_array($status, [408, 409, 425, 429], true);
    }
}
