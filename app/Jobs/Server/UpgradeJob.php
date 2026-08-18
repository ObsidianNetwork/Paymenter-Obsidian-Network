<?php

namespace App\Jobs\Server;

use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Models\ServiceUpgrade;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeProvisionerIdentityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class UpgradeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 5;

    /**
     * Cover all five attempts, their configured backoffs, and runtime.
     */
    public $uniqueFor = 7200;

    private ?string $ownedReservationLeaseId = null;

    public function __construct(
        public ServiceUpgrade $serviceUpgrade,
        public bool $sendNotification = true
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return (string) $this->serviceUpgrade->getKey();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'server-fulfillment:' . $this->serviceUpgrade->service_id
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
        $remoteAttempted = false;
        $dynamic = false;

        try {
            $upgrade = $upgrades->beginProvisioning($this->serviceUpgrade);
            if ($upgrade === null) {
                return;
            }

            $properties = (array) data_get($upgrade->target_snapshot, 'properties', []);
            $userIdentity = data_get(
                $upgrade->target_snapshot,
                'provisioner.user_identity'
            );
            if (is_array($userIdentity)) {
                $properties['_provisioner_user_identity'] = $userIdentity;
            }
            $dynamic = $this->usesDynamicCapacity($upgrade);
            if ($dynamic) {
                $reservation = $this->capacityService()->beginProvisioning($upgrade);
                $leaseId = (string) $reservation['provisioning_lease_id'];
                $this->ownedReservationLeaseId = $leaseId;
                $properties['node'] = (int) $reservation['node_id'];
                $properties['location'] = (int) $reservation['location_id'];
                $properties['_dynamic_upgrade'] = [
                    'panel_identity' => $reservation['panel_identity'],
                    'node_id' => (int) $reservation['node_id'],
                    'external_server_id' => (int) $reservation['external_server_id'],
                    'external_server_uuid' => (string) $reservation['external_server_uuid'],
                    'external_server_identifier' => (string) $reservation['external_server_identifier'],
                    'external_server_external_id' => (string) $reservation['external_server_external_id'],
                    'external_user_id' => (int) $reservation['external_user_id'],
                    'user_external_id' => (string) $reservation['user_external_id'],
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

            app(UpgradeProvisionerIdentityService::class)
                ->assertCurrent($upgrade);
            $provisionerMode = data_get(
                $upgrade->target_snapshot,
                'provisioner.mode'
            );
            if ($provisionerMode === 'external') {
                $remoteAttempted = true;
                $result = ExtensionHelper::upgradeServer(
                    $upgrade->service,
                    $upgrade->product,
                    $properties
                );
                if ($result === false) {
                    throw new PermanentProvisioningException(
                        'The server provisioner rejected the upgrade without applying it.'
                    );
                }
            } elseif ($provisionerMode !== 'serverless') {
                throw new PermanentProvisioningException(
                    'The signed upgrade has no valid provisioner mode.'
                );
            }

            $upgrades->complete($upgrade, $leaseId);
        } catch (PermanentProvisioningException $exception) {
            $upgrades->recordFailure($upgrade, $exception, true, $leaseId);
            $this->fail($exception);

            return;
        } catch (\Throwable $exception) {
            if (
                ($remoteAttempted && !$dynamic)
                || $this->isPermanentClientFailure($exception)
            ) {
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
        return app(CapacityUpgradeReservationIdentity::class)
            ->requiresCoordinator($upgrade);
    }

    private function capacityService(): object
    {
        $class = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        if (!class_exists($class)) {
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
            && !in_array($status, [408, 409, 425, 429], true);
    }
}
