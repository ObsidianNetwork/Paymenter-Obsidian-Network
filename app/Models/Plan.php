<?php

namespace App\Models;

use App\Classes\Price as PriceClass;
use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\Service\CapacityConfigurationMutationGuard;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Plan extends Model implements Auditable
{
    use HasFactory, SerializesCapacityConfigurationMutations, Traits\Auditable;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'billing_period',
        'billing_unit',
        'dynamic_slider_base_price',
        'sort',
    ];

    protected $casts = [
        'billing_period' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(
            fn (Plan $plan) => app(CapacityConfigurationMutationGuard::class)
                ->assertPlanMutable(
                    $plan,
                    $plan->exists && $plan->isDirty([
                        'priceable_type',
                        'priceable_id',
                        'type',
                        'billing_period',
                        'billing_unit',
                    ])
                )
        );
        static::deleting(
            fn (Plan $plan) => app(CapacityConfigurationMutationGuard::class)
                ->assertPlanMutable($plan, true)
        );
    }

    /**
     * Get the available prices of the plan.
     */
    public function prices()
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Get the priceable model of the plan.
     */
    public function priceable()
    {
        return $this->morphTo();
    }

    /**
     * Get the price of the plan.
     *
     * @param  string|null  $currency  Optional currency code to get the price for. If not provided, it will use the current session currency or default currency.
     */
    public function price($currency = null): PriceClass
    {
        if ($this->type === 'free') {
            return new PriceClass(['currency' => Currency::find($currency ?? session('currency', config('settings.default_currency')))], free: true);
        }
        $currency = $currency ?? session('currency', config('settings.default_currency'));
        $price = $this->prices->where('currency_code', $currency)->first();

        return new PriceClass((object) [
            'price' => $price,
            'setup_fee' => $price->setup_fee,
            'currency' => $price->currency,
        ]);
    }

    // Time between billing periods
    public function billingDuration(): Attribute
    {
        if ($this->type === 'free' || $this->type == 'one-time') {
            return Attribute::make(get: fn () => 0);
        }
        $diffInDays = match ($this->billing_unit) {
            'day' => 1,
            'week' => 7,
            'month' => 30,
            'year' => 365,
        };

        return Attribute::make(
            get: fn () => $diffInDays * $this->billing_period
        );
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The shared base price charged once per product for dynamic-slider products.
     * Stored in plans.dynamic_slider_base_price (nullable decimal 10,2).
     * Returns 0.0 when the column is null (no base price configured).
     */
    public function dynamicSliderBasePrice(): float
    {
        return (float) ($this->dynamic_slider_base_price ?? 0);
    }
}
