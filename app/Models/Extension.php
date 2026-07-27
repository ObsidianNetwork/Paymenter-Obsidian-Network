<?php

namespace App\Models;

use App\Helpers\ExtensionHelper;
use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Services\Extensions\ExtensionLifecycleGuard;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\InvoicePaymentInitiationService;
use App\Services\Service\DurableFulfillmentService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Extension extends Model implements Auditable
{
    use HasFactory;
    use SerializesCapacityConfigurationMutations;
    use SoftDeletes;
    use Traits\Auditable;

    protected $fillable = [
        'name',
        'enabled',
        // Name of extension class (e.g. 'Stripe' or 'Paypal')
        'extension',
        'type',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Extension $extension): void {
            if ((bool) $extension->enabled) {
                app(ExtensionLifecycleGuard::class)
                    ->assertCanActivate($extension);
            }
        });

        static::updating(function (Extension $extension): void {
            if (
                $extension->isDirty([
                    'enabled',
                    'extension',
                    'type',
                ])
            ) {
                self::lockGatewayRowForMutation($extension);
            }
            if (
                $extension->isDirty(['extension', 'type'])
            ) {
                if (
                    $extension->type === 'gateway'
                    || $extension->getRawOriginal('type') === 'gateway'
                ) {
                    app(BillingChargeAttemptService::class)
                        ->assertGatewayMutable((int) $extension->id);
                    app(InvoicePaymentInitiationService::class)
                        ->assertGatewayMutable((int) $extension->id);
                }
                if (
                    $extension->type === 'server'
                    || $extension->getRawOriginal('type') === 'server'
                ) {
                    app(DurableFulfillmentService::class)
                        ->assertServerHostMutable(
                            (int) $extension->id
                        );
                }
            }
            if ($extension->isDirty('enabled')) {
                if ((bool) $extension->enabled) {
                    app(ExtensionLifecycleGuard::class)
                        ->assertCanActivate($extension);
                } else {
                    if ($extension->type === 'gateway') {
                        app(BillingChargeAttemptService::class)
                            ->assertGatewayMutable(
                                (int) $extension->id
                            );
                        app(InvoicePaymentInitiationService::class)
                            ->assertGatewayMutable(
                                (int) $extension->id
                            );
                    }
                    if ($extension->type === 'server') {
                        app(DurableFulfillmentService::class)
                            ->assertServerHostMutable((int) $extension->id);
                    }
                    app(ExtensionLifecycleGuard::class)
                        ->assertCanDeactivate($extension);
                }
            }
        });

        static::deleting(function (Extension $extension): void {
            self::lockGatewayRowForMutation($extension);
            if ($extension->type === 'gateway') {
                app(BillingChargeAttemptService::class)
                    ->assertGatewayMutable((int) $extension->id);
                app(InvoicePaymentInitiationService::class)
                    ->assertGatewayMutable((int) $extension->id);
            }
            if ($extension->type === 'server') {
                app(DurableFulfillmentService::class)
                    ->assertServerHostMutable((int) $extension->id);
            }
            app(ExtensionLifecycleGuard::class)
                ->assertCanDeactivate($extension);
        });
    }

    private static function lockGatewayRowForMutation(
        Extension $extension
    ): void {
        if (
            !$extension->exists
            || (int) $extension->id <= 0
            || !in_array(
                'gateway',
                [
                    (string) $extension->getRawOriginal('type'),
                    (string) $extension->type,
                ],
                true
            )
        ) {
            return;
        }

        Extension::withTrashed()
            ->whereKey((int) $extension->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->firstOrFail(['id']);
    }

    /**
     * Get the extension's settings.
     */
    public function settings()
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    public function path(): Attribute
    {
        return Attribute::make(
            get: fn () => ucfirst($this->type) . 's/' . ucfirst($this->extension)
        );
    }

    public function namespace(): Attribute
    {
        return Attribute::make(
            get: fn () => 'Paymenter\\Extensions\\' . ucfirst($this->type) . 's\\' . ucfirst($this->extension)
        );
    }

    public function meta(): Attribute
    {
        return Attribute::make(
            get: fn () => ExtensionHelper::getMeta($this->namespace . '\\' . ucfirst($this->extension))
        );
    }
}
