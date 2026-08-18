<?php

namespace App\Services\Service;

use App\Models\ConfigOption;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps Paymenter's local resource display and renewal source aligned with a
 * service's immutable capacity history.
 */
class CapacityServiceMutationGuard
{
    private const RESOURCE_KEYS = [
        'memory',
        'ram',
        'cpu',
        'disk',
        'disk_space',
        'location',
        'location_id',
        'location_ids',
        'node',
        'node_id',
        '_selected_node',
    ];

    public function assertPropertyMutable(Property $property): void
    {
        $keys = collect([
            $property->key,
            $property->getOriginal('key'),
        ])
            ->filter(fn ($key): bool => is_string($key))
            ->map(fn (string $key): string => strtolower(trim($key)));
        $resourceProperty =
            $keys->intersect(self::RESOURCE_KEYS)->isNotEmpty();

        foreach ($this->propertyServiceIds($property) as $serviceId) {
            $this->assertServiceMutable(
                $serviceId,
                $resourceProperty
            );
        }

        if ($keys->contains('enhance_orgid')) {
            foreach ($this->propertyUserIds($property) as $userId) {
                Service::query()
                    ->where('user_id', $userId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->each(
                        fn ($serviceId) => $this->assertServiceMutable(
                            (int) $serviceId,
                            false
                        )
                    );
            }
        }
    }

    public function assertConfigMutable(ServiceConfig $config): void
    {
        foreach ($this->configUpgradeIds($config) as $upgradeId) {
            $upgrade = ServiceUpgrade::query()->find($upgradeId);
            if (
                $upgrade !== null
                && (
                    $upgrade->source_fingerprint !== null
                    || $upgrade->target_fingerprint !== null
                )
                && !ServiceUpgradeMutationCoordinator::isCoordinating(
                    $upgrade
                )
            ) {
                throw new \RuntimeException(
                    'Signed upgrade configuration is immutable after the quote is created.'
                );
            }
        }

        $optionIds = collect([
            $config->config_option_id,
            $config->getOriginal('config_option_id'),
        ])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $resourceOption = ConfigOption::query()
            ->whereKey($optionIds->all())
            ->get()
            ->contains(function (ConfigOption $option): bool {
                $resourceType = strtolower(
                    (string) $option->getMetadata('resource_type', '')
                );
                $key = strtolower(
                    trim((string) ($option->env_variable ?: $option->name))
                );

                return in_array($resourceType, ['memory', 'cpu', 'disk'], true)
                    || in_array($key, self::RESOURCE_KEYS, true);
            });
        foreach ($this->configServiceIds($config) as $serviceId) {
            $this->assertServiceMutable(
                $serviceId,
                $resourceOption
            );
        }
    }

    private function assertServiceMutable(
        int $serviceId,
        bool $resourceIdentity
    ): void {
        $service = Service::query()
            ->whereKey($serviceId)
            ->lockForUpdate()
            ->first();
        if (
            $service === null
            || FulfillmentStatusTransitionService::isCoordinating($service)
        ) {
            return;
        }
        $activeUpgrade = ServiceUpgrade::query()
            ->where('service_id', $serviceId)
            ->whereIn('status', ServiceUpgrade::activeStatuses())
            ->orderBy('id')
            ->lockForUpdate()
            ->exists();
        if ($activeUpgrade) {
            throw new \RuntimeException(
                'Service properties and configuration are immutable while an upgrade is active.'
            );
        }
        if (
            !$resourceIdentity
            || !$this->hasCheckoutReservation($serviceId)
        ) {
            return;
        }

        throw new \RuntimeException(
            'Reservation-backed resource properties and configuration can only be changed by the capacity-aware fulfillment coordinator.'
        );
    }

    /** @return array<int, int> */
    private function propertyServiceIds(Property $property): array
    {
        return collect([
            [
                $property->model_type,
                $property->model_id,
            ],
            [
                $property->getOriginal('model_type'),
                $property->getOriginal('model_id'),
            ],
        ])
            ->filter(fn (array $owner): bool => $owner[0] === Service::class)
            ->pluck(1)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function propertyUserIds(Property $property): array
    {
        $userMorphs = array_unique([
            User::class,
            (new User)->getMorphClass(),
        ]);

        return collect([
            [
                $property->model_type,
                $property->model_id,
            ],
            [
                $property->getOriginal('model_type'),
                $property->getOriginal('model_id'),
            ],
        ])
            ->filter(
                fn (array $owner): bool => in_array($owner[0], $userMorphs, true)
            )
            ->pluck(1)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function configServiceIds(ServiceConfig $config): array
    {
        return collect([
            [
                $config->configurable_type,
                $config->configurable_id,
            ],
            [
                $config->getOriginal('configurable_type'),
                $config->getOriginal('configurable_id'),
            ],
        ])
            ->filter(fn (array $owner): bool => $owner[0] === Service::class)
            ->pluck(1)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function configUpgradeIds(ServiceConfig $config): array
    {
        return collect([
            [
                $config->configurable_type,
                $config->configurable_id,
            ],
            [
                $config->getOriginal('configurable_type'),
                $config->getOriginal('configurable_id'),
            ],
        ])
            ->filter(
                fn (array $owner): bool => $owner[0] === ServiceUpgrade::class
            )
            ->pluck(1)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function hasCheckoutReservation(int $serviceId): bool
    {
        if (!Schema::hasTable('ptero_resource_reservations')) {
            return false;
        }

        $query = DB::table('ptero_resource_reservations')
            ->where('service_id', $serviceId);
        if (Schema::hasColumn('ptero_resource_reservations', 'purpose')) {
            $query->where('purpose', 'checkout');
        }

        return $query->exists();
    }
}
