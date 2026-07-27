<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\Service\CapacityConfigurationMutationGuard;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductUpgrade extends Pivot
{
    use SerializesCapacityConfigurationMutations;

    protected $table = 'product_upgrades';

    public $incrementing = true;

    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'upgrade_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductUpgrade $pivot): void {
            app(CapacityConfigurationMutationGuard::class)
                ->assertProductsMutable(
                    array_filter([
                        $pivot->product_id,
                        $pivot->upgrade_id,
                        $pivot->getOriginal('product_id'),
                        $pivot->getOriginal('upgrade_id'),
                    ]),
                    'product-upgrade authorization',
                    destructive: true
                );
        });
        static::deleting(fn (ProductUpgrade $pivot) => app(CapacityConfigurationMutationGuard::class)
            ->assertProductsMutable(
                array_filter([
                    $pivot->product_id,
                    $pivot->upgrade_id,
                    $pivot->getOriginal('product_id'),
                    $pivot->getOriginal('upgrade_id'),
                ]),
                'product-upgrade authorization',
                destructive: true
            )
        );
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function upgrade()
    {
        return $this->belongsTo(Product::class, 'upgrade_id');
    }
}
