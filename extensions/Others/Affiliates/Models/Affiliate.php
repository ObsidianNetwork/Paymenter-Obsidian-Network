<?php

namespace Paymenter\Extensions\Others\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardCalculator;

class Affiliate extends Model
{
    protected $table = 'ext_affiliates';

    protected $fillable = [
        'user_id',
        'code',
        'visitors',
        'reward',
        'discount',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(AffiliateOrder::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(AffiliateReward::class);
    }

    /**
     * Get the immutable earnings credited to this affiliate.
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
