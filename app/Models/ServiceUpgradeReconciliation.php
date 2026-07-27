<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceUpgradeReconciliation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payment_evidence' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'Upgrade reconciliation evidence is append-only.'
            );
        });
        static::deleting(function (): never {
            throw new \RuntimeException(
                'Upgrade reconciliation evidence cannot be deleted.'
            );
        });
    }

    public function upgrade()
    {
        return $this->belongsTo(
            ServiceUpgrade::class,
            'service_upgrade_id'
        );
    }
}
