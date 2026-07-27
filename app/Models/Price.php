<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\Service\CapacityConfigurationMutationGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable;

class Price extends Model implements Auditable
{
    use HasFactory, SerializesCapacityConfigurationMutations, Traits\Auditable;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(
            fn (Price $price) => app(CapacityConfigurationMutationGuard::class)
                ->assertPriceMutable(
                    $price,
                    $price->exists && $price->isDirty('plan_id')
                )
        );
        static::deleting(
            fn (Price $price) => app(CapacityConfigurationMutationGuard::class)
                ->assertPriceMutable($price)
        );
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
