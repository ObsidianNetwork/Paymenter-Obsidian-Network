<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\Service\CapacityConfigurationMutationGuard;
use Illuminate\Database\Eloquent\Relations\Pivot;
use OwenIt\Auditing\Contracts\Auditable;

class ConfigOptionProduct extends Pivot implements Auditable
{
    use SerializesCapacityConfigurationMutations, Traits\Auditable;

    protected $table = 'config_option_products';

    public $incrementing = true;

    protected $fillable = [
        'config_option_id',
        'product_id',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (ConfigOptionProduct $pivot): void {
            $guard = app(CapacityConfigurationMutationGuard::class);
            $guard->assertProductsMutable(
                array_filter([
                    $pivot->product_id,
                    $pivot->getOriginal('product_id'),
                ]),
                'configuration-option product assignment',
                destructive: $pivot->exists
                    && $pivot->isDirty([
                        'product_id',
                        'config_option_id',
                    ])
            );
            if (!$pivot->exists) {
                $guard->assertDynamicResourceAttachmentSafe(
                    (int) $pivot->config_option_id,
                    (int) $pivot->product_id
                );
            }
        });
        static::deleting(fn (ConfigOptionProduct $pivot) => app(CapacityConfigurationMutationGuard::class)
            ->assertProductsMutable(
                array_filter([
                    $pivot->product_id,
                    $pivot->getOriginal('product_id'),
                ]),
                'configuration-option product assignment',
                destructive: true
            )
        );
    }

    /**
     * Get the option of the product.
     */
    public function configOption()
    {
        return $this->belongsTo(ConfigOption::class);
    }

    /**
     * Get the product of the option.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
