<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class InvoicePaymentInitiation extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const STATUS_INITIATING = 'initiating';

    public const STATUS_PROVIDER_PENDING = 'provider_pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    protected $fillable = [
        'invoice_id',
        'active_invoice_id',
        'generation',
        'gateway_id',
        'gateway_snapshot_id',
        'gateway_extension',
        'amount',
        'currency_code',
        'idempotency_key',
        'status',
        'attempt_count',
        'last_attempt_at',
        'last_error',
        'reconciliation_lease_token',
        'reconciliation_lease_expires_at',
        'next_reconcile_at',
        'reconciliation_attempt_count',
        'provider_reference',
        'provider_transaction_id',
        'provider_status',
        'initiated_at',
        'settled_at',
        'failed_at',
        'released_at',
        'release_reason',
        'attention_reason',
        'attention_reconcilable',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'generation' => 'integer',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'reconciliation_lease_expires_at' => 'datetime',
        'next_reconcile_at' => 'datetime',
        'reconciliation_attempt_count' => 'integer',
        'initiated_at' => 'datetime',
        'settled_at' => 'datetime',
        'failed_at' => 'datetime',
        'released_at' => 'datetime',
        'attention_reconcilable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(
            function (InvoicePaymentInitiation $initiation): void {
                if ($initiation->isDirty([
                    'invoice_id',
                    'generation',
                    'gateway_snapshot_id',
                    'gateway_extension',
                    'amount',
                    'currency_code',
                    'idempotency_key',
                ])) {
                    throw new \RuntimeException(
                        'Payment initiation ownership, gateway, amount, currency, and idempotency key are immutable.'
                    );
                }
                if (
                    $initiation->isDirty('active_invoice_id')
                    && !(
                        (int) $initiation->getRawOriginal(
                            'active_invoice_id'
                        ) === (int) $initiation->invoice_id
                        && $initiation->active_invoice_id === null
                    )
                ) {
                    throw new \RuntimeException(
                        'A provider payment generation may only release its own active invoice slot.'
                    );
                }
                if (
                    $initiation->isDirty('provider_reference')
                    && $initiation->getRawOriginal(
                        'provider_reference'
                    ) !== null
                ) {
                    throw new \RuntimeException(
                        'A frozen provider payment identity is immutable.'
                    );
                }
                if (
                    $initiation->isDirty(
                        'provider_transaction_id'
                    )
                    && $initiation->getRawOriginal(
                        'provider_transaction_id'
                    ) !== null
                ) {
                    throw new \RuntimeException(
                        'A frozen provider transaction identity is immutable.'
                    );
                }
            }
        );
        static::deleting(function (): void {
            throw new \RuntimeException(
                'Payment initiations are durable provider evidence and cannot be deleted.'
            );
        });
    }

    /**
     * @return list<string>
     */
    public static function unresolvedStatuses(): array
    {
        return [
            self::STATUS_INITIATING,
            self::STATUS_PROVIDER_PENDING,
            self::STATUS_NEEDS_ATTENTION,
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function activeInvoice()
    {
        return $this->belongsTo(
            Invoice::class,
            'active_invoice_id'
        );
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class)
            ->withTrashed();
    }
}
