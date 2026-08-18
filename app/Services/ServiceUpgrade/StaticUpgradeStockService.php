<?php

namespace App\Services\ServiceUpgrade;

use App\Exceptions\DisplayException;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Reserves finite catalogue stock for ordinary product upgrades.
 *
 * Dynamic resource upgrades have their own signed capacity reservation. This
 * coordinator covers only a change from one static product to another.
 */
class StaticUpgradeStockService
{
    public function assertProductChangeAuthorized(
        Service $service,
        Product $targetProduct
    ): void {
        $this->assertTransaction();

        if ((int) $service->product_id === (int) $targetProduct->id) {
            return;
        }

        app(UpgradeProvisionerIdentityService::class)->identity(
            $service->product,
            $targetProduct,
            $service
        );

        $allowed = DB::table('product_upgrades')
            ->where('product_id', $service->product_id)
            ->where('upgrade_id', $targetProduct->id)
            ->lockForUpdate()
            ->exists();
        if (!$allowed) {
            throw new DisplayException(
                'The selected product is no longer an authorized upgrade.'
            );
        }
    }

    public function reserve(
        ServiceUpgrade $upgrade,
        Service $service
    ): void {
        $this->assertTransaction();
        $this->assertUpgradeService($upgrade, $service);
        $this->assertQuantityOne($service);
        $this->assertStaticMode($upgrade, allowUnbound: true);
        $this->assertSnapshotBindings($upgrade);

        if ((int) $service->product_id === (int) $upgrade->product_id) {
            if ($upgrade->capacity_mode === null) {
                ServiceUpgradeMutationCoordinator::run(
                    $upgrade,
                    function () use ($upgrade): void {
                        $upgrade->capacity_mode =
                            ServiceUpgrade::CAPACITY_MODE_STATIC;
                        $upgrade->save();
                    }
                );
            }

            return;
        }
        if ($upgrade->target_stock_consumed_at !== null) {
            throw new \RuntimeException(
                'The target product stock reservation was already consumed.'
            );
        }
        if ($upgrade->target_stock_released_at !== null) {
            throw new \RuntimeException(
                'The target product stock reservation was already released.'
            );
        }
        if ($upgrade->target_stock_reserved_at !== null) {
            $this->assertReserved($upgrade, $service);

            return;
        }

        $targetProduct = Product::query()
            ->whereKey($upgrade->product_id)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertProductChangeAuthorized($service, $targetProduct);

        $quantity = 1;
        $reservedQuantity = 0;
        if ($targetProduct->stock !== null) {
            if ((int) $targetProduct->stock < $quantity) {
                throw new DisplayException(
                    'The selected upgrade product is out of stock.'
                );
            }

            $targetProduct->stock = (int) $targetProduct->stock - $quantity;
            $targetProduct->save();
            $reservedQuantity = $quantity;
        }

        ServiceUpgradeMutationCoordinator::run(
            $upgrade,
            function () use ($upgrade, $reservedQuantity): void {
                $upgrade->forceFill([
                    'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
                    'target_stock_reserved_quantity' => $reservedQuantity,
                    'target_stock_reserved_at' => now(),
                    'target_stock_released_at' => null,
                    'target_stock_consumed_at' => null,
                ]);
                $upgrade->target_stock_fingerprint =
                    $this->stockFingerprint(
                        $upgrade,
                        $reservedQuantity
                    );
                $upgrade->save();
            }
        );
    }

    public function assertReserved(
        ServiceUpgrade $upgrade,
        Service $service
    ): void {
        $ownsStock = $this->assertActiveOwnership(
            $upgrade,
            $service
        );
        $this->assertSnapshotBindings($upgrade);
        if (!$ownsStock) {
            return;
        }
    }

    public function assertActiveOwnership(
        ServiceUpgrade $upgrade,
        Service $service
    ): bool {
        $this->assertUpgradeService($upgrade, $service);
        $this->assertQuantityOne($service);
        $this->assertStaticMode($upgrade);

        if ((int) $service->product_id === (int) $upgrade->product_id) {
            return false;
        }
        if (
            $upgrade->target_stock_reserved_at === null
            || $upgrade->target_stock_released_at !== null
            || $upgrade->target_stock_consumed_at !== null
        ) {
            throw new \RuntimeException(
                'The paid upgrade does not own an active target product stock reservation.'
            );
        }
        $this->assertStockOwnership($upgrade);

        return true;
    }

    public function release(
        ServiceUpgrade $upgrade,
        Service $service
    ): bool {
        $this->assertTransaction();
        $this->assertUpgradeService($upgrade, $service);
        $this->assertStaticMode($upgrade);

        if (
            (int) $service->product_id === (int) $upgrade->product_id
            || $upgrade->target_stock_reserved_at === null
            || $upgrade->target_stock_released_at !== null
            || $upgrade->target_stock_consumed_at !== null
        ) {
            return false;
        }
        $this->assertStockOwnership($upgrade);

        $targetProduct = Product::query()
            ->whereKey($upgrade->product_id)
            ->lockForUpdate()
            ->firstOrFail();
        $quantity = (int) ($upgrade->target_stock_reserved_quantity ?? 0);
        if ($quantity > 0 && $targetProduct->stock !== null) {
            $targetProduct->increment('stock', $quantity);
        }

        ServiceUpgradeMutationCoordinator::run(
            $upgrade,
            function () use ($upgrade): void {
                $upgrade->forceFill([
                    'target_stock_released_at' => now(),
                ])->save();
            }
        );

        return true;
    }

    public function consume(
        ServiceUpgrade $upgrade,
        Service $service
    ): void {
        $this->assertTransaction();
        $this->assertReserved($upgrade, $service);

        if ((int) $service->product_id === (int) $upgrade->product_id) {
            return;
        }

        $products = Product::query()
            ->whereKey([
                (int) $service->product_id,
                (int) $upgrade->product_id,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $sourceProduct = $products->get((int) $service->product_id);
        $targetProduct = $products->get((int) $upgrade->product_id);
        if ($sourceProduct === null || $targetProduct === null) {
            throw new \RuntimeException(
                'An upgrade product disappeared while stock was being consumed.'
            );
        }

        if ($sourceProduct->stock !== null) {
            $sourceProduct->increment('stock', (int) $service->quantity);
        }

        ServiceUpgradeMutationCoordinator::run(
            $upgrade,
            function () use ($upgrade): void {
                $upgrade->forceFill([
                    'target_stock_consumed_at' => now(),
                ])->save();
            }
        );
    }

    private function assertUpgradeService(
        ServiceUpgrade $upgrade,
        Service $service
    ): void {
        if ((int) $upgrade->service_id !== (int) $service->id) {
            throw new \RuntimeException(
                'The stock reservation does not belong to the locked service.'
            );
        }
    }

    private function assertQuantityOne(Service $service): void
    {
        if ((int) $service->quantity !== 1) {
            throw new DisplayException(
                'Product upgrades require a service quantity of one.'
            );
        }
    }

    private function assertStaticMode(
        ServiceUpgrade $upgrade,
        bool $allowUnbound = false
    ): void {
        if (
            $upgrade->capacity_mode
                === ServiceUpgrade::CAPACITY_MODE_STATIC
            || ($allowUnbound && $upgrade->capacity_mode === null)
        ) {
            return;
        }

        throw new \RuntimeException(
            'The upgrade is not durably bound to static stock accounting.'
        );
    }

    private function assertSnapshotBindings(
        ServiceUpgrade $upgrade
    ): void {
        $source = $upgrade->source_snapshot;
        $target = $upgrade->target_snapshot;
        if (
            !is_array($source)
            || !is_array($target)
            || !$upgrade->snapshotFingerprintsAreAuthentic()
            || (int) ($source['service_id'] ?? 0)
                !== (int) $upgrade->service_id
            || (int) ($target['service_id'] ?? 0)
                !== (int) $upgrade->service_id
            || (int) ($target['product_id'] ?? 0)
                !== (int) $upgrade->product_id
            || (int) ($target['plan_id'] ?? 0)
                !== (int) $upgrade->plan_id
            || (int) ($target['quantity'] ?? 0) !== 1
            || strtoupper((string) ($target['currency_code'] ?? ''))
                !== strtoupper((string) $upgrade->currency_code)
        ) {
            throw new \RuntimeException(
                'The static upgrade no longer matches its signed target identity.'
            );
        }
    }

    private function assertStockOwnership(
        ServiceUpgrade $upgrade
    ): void {
        $quantity = $upgrade->target_stock_reserved_quantity;
        if (
            !in_array($quantity, [0, 1], true)
            || !is_string($upgrade->target_stock_fingerprint)
            || !hash_equals(
                $upgrade->target_stock_fingerprint,
                $this->stockFingerprint($upgrade, $quantity)
            )
        ) {
            throw new \RuntimeException(
                'The static upgrade target-stock ownership proof is invalid.'
            );
        }
    }

    private function stockFingerprint(
        ServiceUpgrade $upgrade,
        int $reservedQuantity
    ): string {
        return hash('sha256', json_encode([
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
            'currency_code' => strtoupper(
                (string) $upgrade->currency_code
            ),
            'reserved_quantity' => $reservedQuantity,
            'service_id' => (int) $upgrade->service_id,
            'source_fingerprint' => (string) $upgrade->source_fingerprint,
            'target_fingerprint' => (string) $upgrade->target_fingerprint,
            'target_plan_id' => (int) $upgrade->plan_id,
            'target_product_id' => (int) $upgrade->product_id,
            'target_quantity' => 1,
            'upgrade_id' => (int) $upgrade->id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Static upgrade stock must be coordinated inside a database transaction.'
            );
        }
    }
}
