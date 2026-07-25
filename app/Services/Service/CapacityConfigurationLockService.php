<?php

namespace App\Services\Service;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Freeze every database row used to derive a dynamic configuration and its
 * price. Product is the shared first lock for both readers and writers.
 */
class CapacityConfigurationLockService
{
    public function lockProduct(Product|int $product): Product
    {
        $productId = $product instanceof Product
            ? (int) $product->id
            : $product;

        $locked = $this->lockProducts([$productId])->get($productId);
        if ($locked === null) {
            throw new \RuntimeException(
                'The product disappeared while its capacity configuration was being locked.'
            );
        }

        return $locked;
    }

    /**
     * @param  array<int, int>|list<int>  $products
     * @return Collection<int, Product>
     */
    public function lockProducts(array $products): Collection
    {
        $productIds = collect($products)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($productIds === []) {
            throw new \InvalidArgumentException(
                'At least one product is required for a capacity configuration lock.'
            );
        }

        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Capacity configuration locks must be acquired inside the transaction that consumes the snapshot.'
            );
        }

        return $this->lockNow($productIds);
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, Product>
     */
    private function lockNow(array $productIds): Collection
    {
        $products = Product::query()
            ->whereKey($productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($products->count() !== count($productIds)) {
            throw new \RuntimeException(
                'A product disappeared while its capacity configuration was being locked.'
            );
        }

        $pivots = DB::table('config_option_products')
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('config_option_id')
            ->lockForUpdate()
            ->get(['product_id', 'config_option_id']);
        $rootOptionIds = $pivots
            ->pluck('config_option_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $optionIds = ConfigOption::query()
            ->where(function ($query) use ($rootOptionIds): void {
                $query->whereKey($rootOptionIds->all())
                    ->orWhereIn('parent_id', $rootOptionIds->all());
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $productMorph = (new Product())->getMorphClass();
        $configOptionMorph = (new ConfigOption())->getMorphClass();
        $planIds = Plan::query()
            ->where(function ($query) use (
                $productIds,
                $productMorph,
                $optionIds,
                $configOptionMorph
            ): void {
                $query->where(function ($query) use (
                    $productIds,
                    $productMorph
                ): void {
                    $query->where('priceable_type', $productMorph)
                        ->whereIn('priceable_id', $productIds);
                })->orWhere(function ($query) use (
                    $optionIds,
                    $configOptionMorph
                ): void {
                    $query->where('priceable_type', $configOptionMorph)
                        ->whereIn('priceable_id', $optionIds->all());
                });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        Price::query()
            ->whereIn('plan_id', $planIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($products as $product) {
            $product->unsetRelations();
            $product->load([
                'configOptions.children.plans.prices',
                'plans.prices',
                'server.settings',
                'settings',
                'upgradableConfigOptions.children.plans.prices',
            ]);
        }

        return $products->keyBy('id');
    }
}
