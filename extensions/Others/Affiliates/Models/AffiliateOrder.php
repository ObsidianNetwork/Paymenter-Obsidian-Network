<?php

namespace Paymenter\Extensions\Others\Affiliates\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardCalculator;

class AffiliateOrder extends Model
{
    protected $table = 'ext_affiliate_orders';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'affiliate_id',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(AffiliateReward::class);
    }

    /**
     * Get the immutable earnings credited for this order.
     */
    public function earnings(): Attribute
    {
        return Attribute::make(
            get: fn (): array => app(AffiliateRewardCalculator::class)
                ->summarize(
                    $this->rewards()
                        ->with('currency')
                        ->orderBy('currency_code')
                        ->orderBy('id')
                        ->get(['currency_code', 'amount'])
                ),
        );
    }
}
