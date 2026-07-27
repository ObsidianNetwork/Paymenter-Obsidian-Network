<?php

namespace Paymenter\Extensions\Others\Affiliates\Models;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReward extends Model
{
    protected $table = 'ext_affiliate_rewards';

    protected $fillable = [
        'invoice_id',
        'affiliate_order_id',
        'affiliate_id',
        'user_id',
        'currency_code',
        'reward_percentage',
        'amount',
    ];

    protected $casts = [
        'reward_percentage' => 'integer',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Affiliate reward evidence is immutable.'
            );
        });
        static::deleting(function (): never {
            throw new \RuntimeException(
                'Affiliate reward evidence is immutable.'
            );
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function affiliateOrder(): BelongsTo
    {
        return $this->belongsTo(AffiliateOrder::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(
            Currency::class,
            'currency_code',
            'code'
        );
    }
}
