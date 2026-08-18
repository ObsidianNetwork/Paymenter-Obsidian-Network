<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\ServiceUpgrade\CouponUpgradeMutationGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use HasFactory, SerializesCapacityConfigurationMutations, Traits\Auditable;

    protected $fillable = [
        'type',
        'applies_to',
        'time',
        'code',
        'value',
        'max_uses',
        'max_uses_per_user',
        'starts_at',
        'expires_at',
        'recurring',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'value' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Coupon $coupon): void {
            if ($coupon->exists) {
                app(CouponUpgradeMutationGuard::class)
                    ->assertMutable((int) $coupon->id);
            }
        });
        static::deleting(function (Coupon $coupon): void {
            app(CouponUpgradeMutationGuard::class)
                ->assertMutable((int) $coupon->id);
            if ($coupon->services()->exists()) {
                throw new \RuntimeException(
                    'Coupons referenced by service billing history cannot be deleted. Disable or expire the coupon instead.'
                );
            }
        });
    }

    /**
     * Get the products that belong to the option.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_products')
            ->using(CouponProduct::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Check if the user has exceeded the maximum allowed uses of this coupon
     *
     * @param  int  $userId
     */
    public function hasExceededMaxUsesPerUser($userId): bool
    {
        if (empty($this->max_uses_per_user)) {
            return false;
        }

        return $this->services()
            ->where('user_id', $userId)
            ->count() >= $this->max_uses_per_user;
    }

    public function calculateDiscount($price, $type = 'price')
    {
        if (!in_array($type, ['price', 'setup_fee'])) {
            throw new \InvalidArgumentException('Invalid type for coupon discount calculation');
        }
        if (!in_array($this->applies_to, ['all', $type])) {
            return 0;
        }

        $discount = 0;
        if ($this->type === 'percentage') {
            $discount = $price * $this->value / 100;
        } elseif ($this->type === 'fixed') {
            $discount = $this->value;
        }
        if ($price < $discount) {
            $discount = $price;
        }

        return $discount;
    }
}
