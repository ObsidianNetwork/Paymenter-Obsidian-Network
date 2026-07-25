<?php

namespace App\Models;

use App\Helpers\ExtensionHelper;
use App\Services\Extensions\ExtensionLifecycleGuard;
use App\Services\Service\DurableFulfillmentService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Extension extends Model implements Auditable
{
    use HasFactory, SoftDeletes, Traits\Auditable;

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
        static::updating(function (Extension $extension): void {
            if ($extension->isDirty('enabled') && ! (bool) $extension->enabled) {
                if ($extension->type === 'server') {
                    app(DurableFulfillmentService::class)
                        ->assertServerHostMutable((int) $extension->id);
                }
                app(ExtensionLifecycleGuard::class)
                    ->assertCanDeactivate($extension);
            }
        });

        static::deleting(function (Extension $extension): void {
            if ($extension->type === 'server') {
                app(DurableFulfillmentService::class)
                    ->assertServerHostMutable((int) $extension->id);
            }
            app(ExtensionLifecycleGuard::class)
                ->assertCanDeactivate($extension);
        });
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
