<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class BillingChargeAttempt extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const PURPOSE_AUTOMATIC_RENEWAL = 'automatic_renewal';

    public const PURPOSE_CUSTOMER_SAVED_METHOD =
        'customer_saved_method';

    public const STATUS_PENDING = 'pending';

    public const STATUS_LEASED = 'leased';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_PROVIDER_PENDING = 'provider_pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    protected $fillable = [
        'invoice_id',
        'billing_agreement_id',
        'billing_agreement_snapshot_id',
        'billing_agreement_reference',
        'gateway_id',
        'gateway_snapshot_id',
        'gateway_extension',
        'provider_customer_reference',
        'purpose',
        'amount',
        'currency_code',
        'idempotency_key',
        'status',
        'attempt_count',
        'available_at',
        'lease_token',
        'lease_expires_at',
        'last_attempt_at',
        'last_error',
        'provider_reference',
        'provider_transaction_id',
        'provider_status',
        'provider_payload',
        'settled_at',
        'failed_at',
        'metric_recorded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'attempt_count' => 'integer',
        'available_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'provider_payload' => 'array',
        'settled_at' => 'datetime',
        'failed_at' => 'datetime',
        'metric_recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (BillingChargeAttempt $attempt): void {
            if ($attempt->isDirty([
                'invoice_id',
                'billing_agreement_id',
                'billing_agreement_snapshot_id',
                'billing_agreement_reference',
                'gateway_id',
                'gateway_snapshot_id',
                'gateway_extension',
                'provider_customer_reference',
                'purpose',
                'amount',
                'currency_code',
                'idempotency_key',
            ])) {
                throw new \RuntimeException(
                    'Billing charge attempt ownership, amount, currency, payment method, gateway, and idempotency key are immutable.'
                );
            }
            if (
                $attempt->isDirty('provider_reference')
                && $attempt->getRawOriginal('provider_reference') !== null
            ) {
                throw new \RuntimeException(
                    'A frozen billing charge provider resource identity is immutable.'
                );
            }
            if (
                $attempt->isDirty('provider_transaction_id')
                && $attempt->getRawOriginal(
                    'provider_transaction_id'
                ) !== null
            ) {
                throw new \RuntimeException(
                    'A frozen billing charge provider transaction identity is immutable.'
                );
            }
        });
        static::deleting(function (): void {
            throw new \RuntimeException(
                'Billing charge attempts are durable payment evidence and cannot be deleted.'
            );
        });
    }

    /**
     * @return list<string>
     */
    public static function nonterminalStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_LEASED,
            self::STATUS_RETRYABLE,
            self::STATUS_PROVIDER_PENDING,
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function billingAgreement()
    {
        return $this->belongsTo(BillingAgreement::class)
            ->withTrashed();
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class)
            ->withTrashed();
    }
}
