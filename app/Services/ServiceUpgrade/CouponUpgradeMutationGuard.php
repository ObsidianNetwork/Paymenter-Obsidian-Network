<?php

namespace App\Services\ServiceUpgrade;

use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serialize coupon and coupon-product mutations with upgrade quote creation.
 */
class CouponUpgradeMutationGuard
{
    public function assertMutable(int $couponId): void
    {
        $this->assertEligibilityMutable($couponId, null);
    }

    public function assertEligibilityMutable(
        int $couponId,
        ?int $productId
    ): void {
        if ($couponId <= 0) {
            return;
        }
        if (
            !Schema::hasTable('coupons')
            || !Schema::hasTable('services')
            || !Schema::hasTable('service_upgrades')
        ) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Coupon pricing changes must run inside a database transaction.'
            );
        }

        // Match quote lock order: service -> upgrade -> coupon. A writer that
        // starts first holds the service rows until its coupon change commits;
        // a quote that starts first blocks the writer before the coupon proof.
        $serviceIds = DB::table('services')
            ->where('coupon_id', $couponId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $activeUpgrade = $serviceIds->isEmpty()
            ? null
            : DB::table('service_upgrades')
                ->whereIn('service_id', $serviceIds->all())
                ->whereIn('status', ServiceUpgrade::activeStatuses())
                ->orderBy('id')
                ->lockForUpdate()
                ->value('id');

        if ($productId !== null && $productId > 0) {
            DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first(['id']);
        }
        DB::table('coupons')
            ->where('id', $couponId)
            ->lockForUpdate()
            ->first(['id']);

        if ($activeUpgrade !== null) {
            throw new \RuntimeException(
                "Coupon {$couponId} cannot change while service upgrade {$activeUpgrade} has an active signed quote."
            );
        }
    }

    public function assertProductDeletionSafe(int $productId): void
    {
        if (
            $productId <= 0
            || !Schema::hasTable('coupon_products')
        ) {
            return;
        }
        $couponIds = DB::table('coupon_products')
            ->where('product_id', $productId)
            ->orderBy('coupon_id')
            ->pluck('coupon_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($couponIds as $couponId) {
            $this->assertEligibilityMutable(
                $couponId,
                $productId
            );
        }
        if ($couponIds->isEmpty()) {
            DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first(['id']);
        }

        // A pivot writer may have committed after the first unlocked identity
        // read but before this transaction acquired the product row. Once the
        // product is locked no new custom-pivot write can pass; close that
        // narrow window by guarding any newly observed coupons too.
        $newCouponIds = DB::table('coupon_products')
            ->where('product_id', $productId)
            ->orderBy('coupon_id')
            ->pluck('coupon_id')
            ->map(fn ($id): int => (int) $id)
            ->diff($couponIds)
            ->unique()
            ->values();
        foreach ($newCouponIds as $couponId) {
            $this->assertEligibilityMutable(
                $couponId,
                $productId
            );
        }
    }
}
