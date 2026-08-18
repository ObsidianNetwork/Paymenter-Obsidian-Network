<?php

namespace App\Models;

use App\Models\Traits\HasProperties;
use App\Services\Invoice\BillingChargeAttemptService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

class BillingAgreement extends Model implements Auditable
{
    use HasProperties, HasUlids, SoftDeletes, Traits\Auditable;

    /** @var array<int, int> */
    private static array $coordinatedDeleteDepth = [];

    protected $fillable = [
        'ulid',
        'user_id',
        'gateway_id',
        'name', // e.g. Visa **** 4242
        'external_reference', // e.g. Stripe card id, PayPal billing agreement id
        'type', // e.g. card type
        'expiry',
    ];

    protected static function booted(): void
    {
        static::updating(function (
            BillingAgreement $billingAgreement
        ): void {
            if ($billingAgreement->isDirty([
                'user_id',
                'gateway_id',
                'external_reference',
            ])) {
                app(BillingChargeAttemptService::class)
                    ->assertBillingAgreementMutable($billingAgreement);
            }
        });
        static::deleting(function (
            BillingAgreement $billingAgreement
        ): void {
            app(BillingChargeAttemptService::class)
                ->assertBillingAgreementMutable($billingAgreement);
            $billingAgreement->services()->update([
                'billing_agreement_id' => null,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Pin the saved method while its attempt guard and service detachment are
     * checked. Model delete events alone otherwise leave a race between the
     * guard query and the soft-delete write.
     */
    public function delete()
    {
        $agreementId = (int) $this->getKey();
        $forceDelete = $this->isForceDeleting();
        if (
            $agreementId <= 0
            || isset(self::$coordinatedDeleteDepth[$agreementId])
        ) {
            return parent::delete();
        }

        return DB::transaction(function () use (
            $agreementId,
            $forceDelete
        ) {
            $agreement = static::withTrashed()
                ->whereKey($agreementId)
                ->lockForUpdate()
                ->first();
            if (
                $agreement === null
                || ($agreement->trashed() && !$forceDelete)
            ) {
                return true;
            }

            self::$coordinatedDeleteDepth[$agreementId] =
                (self::$coordinatedDeleteDepth[$agreementId] ?? 0) + 1;
            try {
                $agreement->forceDeleting = $forceDelete;

                return $agreement->delete();
            } finally {
                self::$coordinatedDeleteDepth[$agreementId]--;
                if (
                    self::$coordinatedDeleteDepth[$agreementId] === 0
                ) {
                    unset(self::$coordinatedDeleteDepth[$agreementId]);
                }
            }
        }, 5);
    }

    public function uniqueIds()
    {
        return [
            'ulid',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'billing_agreement_id');
    }

    public function chargeAttempts()
    {
        return $this->hasMany(BillingChargeAttempt::class);
    }
}
