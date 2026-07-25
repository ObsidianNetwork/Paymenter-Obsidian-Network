<?php

namespace App\Services\Service;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serialize quote inputs with configuration writers. Immutable reservation
 * snapshots let ordinary metadata and prices evolve for future orders; only
 * destructive identity mutations remain blocked by live commitments.
 */
class CapacityConfigurationMutationGuard
{
    private const UNRESOLVED_STATUSES = ['pending', 'paid_committed'];

    public function assertDynamicResourceActivationSafe(
        ConfigOption $option
    ): void {
        if (
            ! $this->isDynamicResourceOption($option)
            || $this->isDynamicResourceOption($option, original: true)
        ) {
            return;
        }

        $optionIds = collect([
            $option->id,
            $option->getOriginal('id'),
        ])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($optionIds->isEmpty()) {
            return;
        }

        $this->assertProductsCanUseDynamicResources(
            DB::table('config_option_products')
                ->whereIn('config_option_id', $optionIds->all())
                ->pluck('product_id')
                ->all()
        );
    }

    public function assertDynamicResourceAttachmentSafe(
        int $configOptionId,
        int $productId
    ): void {
        $option = ConfigOption::query()
            ->whereKey($configOptionId)
            ->lockForUpdate()
            ->first();
        if (
            $option === null
            || ! $this->isDynamicResourceOption($option)
        ) {
            return;
        }

        $this->assertProductsCanUseDynamicResources([$productId]);
    }

    public function assertProductProvisionerActivationSafe(
        Product $product
    ): void {
        if (
            ! $product->exists
            || ! $product->isDirty('server_id')
            || ! Schema::hasTable('extensions')
            || DB::table('extensions')
                ->where('id', (int) $product->server_id)
                ->where('type', 'server')
                ->whereNull('deleted_at')
                ->value('extension') !== 'Pterodactyl'
        ) {
            return;
        }

        $hasDynamicResource = ConfigOption::query()
            ->whereHas(
                'products',
                fn ($query) => $query->whereKey($product->id)
            )
            ->where('type', 'dynamic_slider')
            ->whereNull('parent_id')
            ->where('hidden', false)
            ->get()
            ->contains(
                fn (ConfigOption $option): bool =>
                    $this->isDynamicResourceOption($option)
            );
        if ($hasDynamicResource) {
            $this->assertProductsCanUseDynamicResources(
                [(int) $product->id],
                requirePterodactyl: false
            );
        }
    }

    /**
     * A product cannot silently reinterpret legacy services as
     * reservation-backed dynamic services. Existing non-cancelled services
     * must first have a confirmed checkout reservation created by an explicit
     * migration.
     *
     * @param  array<int, int|string>  $productIds
     */
    public function assertProductsCanUseDynamicResources(
        array $productIds,
        bool $requirePterodactyl = true
    ): void {
        $productIds = collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if (
            $productIds->isEmpty()
            || ! Schema::hasTable('products')
            || ! Schema::hasTable('services')
        ) {
            return;
        }

        Product::query()
            ->whereKey($productIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($requirePterodactyl) {
            if (! Schema::hasTable('extensions')) {
                return;
            }
            $productIds = DB::table('products')
                ->join(
                    'extensions',
                    'extensions.id',
                    '=',
                    'products.server_id'
                )
                ->whereIn('products.id', $productIds->all())
                ->where('extensions.type', 'server')
                ->where('extensions.extension', 'Pterodactyl')
                ->whereNull('extensions.deleted_at')
                ->pluck('products.id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values();
            if ($productIds->isEmpty()) {
                return;
            }
        }

        $legacyServices = DB::table('services as service')
            ->whereIn('service.product_id', $productIds->all())
            ->where(function ($query): void {
                $query->whereNull('service.status')
                    ->orWhere('service.status', '!=', 'cancelled');
            });

        if (
            Schema::hasTable('ptero_resource_reservations')
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'service_id'
            )
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'purpose'
            )
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'status'
            )
        ) {
            $legacyServices->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ptero_resource_reservations as reservation')
                    ->whereColumn(
                        'reservation.service_id',
                        'service.id'
                    )
                    ->where('reservation.purpose', 'checkout')
                    ->where('reservation.status', 'confirmed');
            });
        }

        $legacyServiceId = $legacyServices
            ->orderBy('service.id')
            ->value('service.id');
        if ($legacyServiceId !== null) {
            throw new \RuntimeException(
                "Dynamic resource stock cannot be enabled while service {$legacyServiceId} has no confirmed checkout reservation. Explicitly migrate or cancel every non-cancelled legacy service first."
            );
        }
    }

    public function assertConfigOptionMutable(
        ConfigOption $option,
        bool $destructive = false
    ): void
    {
        $rootIds = collect([
            $option->parent_id ?: $option->id,
            $option->getOriginal('parent_id')
                ?: $option->getOriginal('id'),
        ])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($rootIds->isEmpty()) {
            return;
        }

        $this->assertProductsMutable(
            DB::table('config_option_products')
                ->whereIn('config_option_id', $rootIds->all())
                ->pluck('product_id')
                ->all(),
            'configuration option',
            destructive: $destructive
        );
    }

    public function assertPlanMutable(
        Plan $plan,
        bool $destructive = false
    ): void
    {
        $planIds = collect([$plan->id, $plan->getOriginal('id')])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $productIds = collect();

        foreach ([
            [
                $plan->priceable_type,
                $plan->priceable_id,
            ],
            [
                $plan->getOriginal('priceable_type'),
                $plan->getOriginal('priceable_id'),
            ],
        ] as [$type, $id]) {
            if ($type === (new Product())->getMorphClass() && $id !== null) {
                $productIds->push((int) $id);
            }
            if (
                $type === (new ConfigOption())->getMorphClass()
                && $id !== null
            ) {
                $option = ConfigOption::query()->find((int) $id);
                if ($option !== null) {
                    $rootId = (int) ($option->parent_id ?: $option->id);
                    $productIds = $productIds->merge(
                        DB::table('config_option_products')
                            ->where('config_option_id', $rootId)
                            ->pluck('product_id')
                    );
                }
            }
        }

        $this->assertProductsMutable(
            $productIds->all(),
            'billing plan',
            $planIds->all(),
            $destructive
        );
    }

    public function assertPriceMutable(
        Price $price,
        bool $destructive = false
    ): void
    {
        $planIds = collect([$price->plan_id, $price->getOriginal('plan_id')])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($planIds as $planId) {
            $plan = Plan::query()->find($planId);
            if ($plan !== null) {
                $this->assertPlanMutable($plan, $destructive);
            }
        }
    }

    private function isDynamicResourceOption(
        ConfigOption $option,
        bool $original = false
    ): bool {
        if ($original && ! $option->exists) {
            return false;
        }

        $metadata = $original
            ? json_decode(
                (string) $option->getRawOriginal('metadata'),
                true
            )
            : (array) ($option->metadata ?? []);
        $metadata = is_array($metadata) ? $metadata : [];
        $type = $original
            ? $option->getOriginal('type')
            : $option->type;
        $parentId = $original
            ? $option->getOriginal('parent_id')
            : $option->parent_id;
        $hidden = $original
            ? $option->getOriginal('hidden')
            : $option->hidden;
        $resource = strtolower(
            trim((string) ($metadata['resource_type'] ?? ''))
        );

        return $type === 'dynamic_slider'
            && $parentId === null
            && ! (bool) $hidden
            && in_array($resource, ['memory', 'cpu', 'disk'], true);
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @param  array<int, int|string>  $planIds
     */
    public function assertProductsMutable(
        array $productIds,
        string $subject = 'dynamic configuration',
        array $planIds = [],
        bool $destructive = false
    ): void {
        if (
            ! Schema::hasTable('ptero_resource_reservations')
            || ! Schema::hasColumn(
                'ptero_resource_reservations',
                'product_id'
            )
        ) {
            return;
        }

        $productIds = collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $planIds = collect($planIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if ($productIds->isEmpty() && $planIds->isEmpty()) {
            return;
        }

        // This lock is shared with every quote/reservation snapshot. Model
        // mutations are transaction-wrapped, so it remains held through the
        // actual INSERT/UPDATE/DELETE rather than only through this check.
        Product::query()
            ->whereKey($productIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if (! $destructive) {
            return;
        }

        $active = DB::table(
            'ptero_resource_reservations as reservation'
        )
            ->leftJoin(
                'services as service',
                'service.id',
                '=',
                'reservation.service_id'
            )
            ->where(function ($query): void {
                $query->whereIn(
                    'reservation.status',
                    self::UNRESOLVED_STATUSES
                )->orWhere(function ($query): void {
                    $query->where('reservation.status', 'confirmed')
                        ->where(function ($query): void {
                            $query->whereNull('service.status')
                                ->orWhere(
                                    'service.status',
                                    '!=',
                                    'cancelled'
                                );
                        });
                });
            })
            ->where(function ($query) use ($productIds, $planIds): void {
                if ($productIds->isNotEmpty()) {
                    $query->whereIn(
                        'reservation.product_id',
                        $productIds->all()
                    );
                }
                if ($planIds->isNotEmpty()) {
                    $method = $productIds->isNotEmpty()
                        ? 'orWhereIn'
                        : 'whereIn';
                    $query->{$method}(
                        'reservation.plan_id',
                        $planIds->all()
                    );
                }
            })
            ->exists();
        if ($active) {
            throw new \RuntimeException(
                "This {$subject} identity is required by an unresolved or active capacity commitment. Cancel, expire, or fully cancel the service before removing or reassigning it."
            );
        }
    }
}
