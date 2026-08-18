<?php

namespace App\Models;

use App\Events\Setting\Retrieved;
use App\Events\Setting\Saved;
use App\Events\Setting\Saving;
use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Redactors\RightRedactor;
use App\Services\Extensions\ExtensionLifecycleGuard;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\InvoicePaymentInitiationService;
use App\Services\Service\CapacityConfigurationMutationGuard;
use App\Services\Service\DurableFulfillmentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Setting extends Model implements Auditable
{
    use HasFactory, SerializesCapacityConfigurationMutations, Traits\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'encrypted',
        'settingable_id',
        'settingable_type',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    protected $dispatchesEvents = [
        'retrieved' => Retrieved::class,
        'saving' => Saving::class,
        'saved' => Saved::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (Setting $setting): void {
            if (
                !$setting->isDirty([
                    'key',
                    'value',
                    'settingable_id',
                    'settingable_type',
                ])
            ) {
                return;
            }

            self::assertFulfillmentSettingMutable($setting);
        });
        static::deleting(
            fn (Setting $setting) => self::assertFulfillmentSettingMutable($setting)
        );
    }

    private static function assertFulfillmentSettingMutable(
        Setting $setting
    ): void {
        $identities = collect([
            [
                'key' => $setting->key,
                'type' => $setting->settingable_type,
                'id' => $setting->settingable_id,
            ],
            [
                'key' => $setting->getOriginal('key'),
                'type' => $setting->getOriginal('settingable_type'),
                'id' => $setting->getOriginal('settingable_id'),
            ],
        ])->unique(
            fn (array $identity): string => implode(':', array_map('strval', $identity))
        );

        $gatewayIds = $identities
            ->filter(
                fn (array $identity): bool => in_array(
                    $identity['type'],
                    [Gateway::class, Extension::class],
                    true
                )
                    && (int) $identity['id'] > 0
            )
            ->map(
                fn (array $identity): int => (int) $identity['id']
            )
            ->unique()
            ->sort(SORT_NUMERIC)
            ->values()
            ->all();
        $lockedGatewayIds = $gatewayIds === []
            ? []
            : Extension::withTrashed()
                ->whereIn('id', $gatewayIds)
                ->where('type', 'gateway')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        foreach ($lockedGatewayIds as $gatewayId) {
            app(BillingChargeAttemptService::class)
                ->assertGatewayMutable($gatewayId);
            app(InvoicePaymentInitiationService::class)
                ->assertGatewayMutable($gatewayId);
        }

        foreach ($identities as $identity) {
            if (
                $identity['type'] === Product::class
                && (int) $identity['id'] > 0
            ) {
                app(CapacityConfigurationMutationGuard::class)
                    ->assertProductsMutable(
                        [(int) $identity['id']],
                        'product setting',
                        destructive: true
                    );
            }
            if (
                preg_match(
                    '/(^|_)(host|hostname|url|endpoint|domain|ip|port|server)(_|$)/i',
                    (string) $identity['key']
                ) === 1
                && in_array(
                    $identity['type'],
                    [Server::class, Extension::class],
                    true
                )
                && Extension::query()
                    ->whereKey($identity['id'])
                    ->where('type', 'server')
                    ->exists()
            ) {
                app(DurableFulfillmentService::class)
                    ->assertServerHostMutable((int) $identity['id']);
            }

            if (
                in_array(
                    $identity['key'],
                    ['pterodactyl_url', 'exclusive_provisioning_control'],
                    true
                )
                && in_array(
                    $identity['type'],
                    [Extension::class],
                    true
                )
                && Extension::query()
                    ->whereKey($identity['id'])
                    ->where('extension', ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL)
                    ->exists()
            ) {
                app(ExtensionLifecycleGuard::class)
                    ->assertCanDeactivate(
                        ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL
                    );
            }
        }
    }

    public function getAttributeModifiers(): array
    {
        if (!$this->encrypted) {
            return [];
        }

        return [
            'value' => RightRedactor::class,
        ];
    }
}
