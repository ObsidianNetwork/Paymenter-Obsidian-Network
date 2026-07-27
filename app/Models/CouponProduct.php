<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\ServiceUpgrade\CouponUpgradeMutationGuard;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CouponProduct extends Pivot
{
    use SerializesCapacityConfigurationMutations;

    protected $table = 'coupon_products';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'product_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (CouponProduct $pivot): void {
            $couponIds = array_values(array_unique(array_filter([
                (int) $pivot->coupon_id,
                (int) $pivot->getOriginal('coupon_id'),
            ])));
            sort($couponIds);
            $productIds = array_values(array_unique(array_filter([
                (int) $pivot->product_id,
                (int) $pivot->getOriginal('product_id'),
            ])));
            sort($productIds);

            foreach ($couponIds as $couponId) {
                foreach ($productIds as $productId) {
                    app(CouponUpgradeMutationGuard::class)
                        ->assertEligibilityMutable(
                            $couponId,
                            $productId
                        );
                }
            }
        });
        static::deleting(function (CouponProduct $pivot): void {
            app(CouponUpgradeMutationGuard::class)
                ->assertEligibilityMutable(
                    (int) $pivot->coupon_id,
                    (int) $pivot->product_id
                );
        });
    }
}
