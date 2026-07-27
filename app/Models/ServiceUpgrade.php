<?php

namespace App\Models;

use App\Classes\Price;
use App\Helpers\ExtensionHelper;
use App\Observers\ServiceUpgradeObserver;
use App\Services\ServiceUpgrade\ServiceUpgradePricingService;
use App\Services\ServiceUpgrade\UpgradeProvisionerIdentityService;
use App\Support\StrictInteger;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceUpgradeObserver::class])]
class ServiceUpgrade extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const CAPACITY_MODE_STATIC = 'static';

    public const CAPACITY_MODE_DYNAMIC = 'dynamic';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID_COMMITTED = 'paid_committed';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $guarded = [];

    private bool $targetCouponResolved = false;

    private ?Coupon $resolvedTargetCoupon = null;

    protected $casts = [
        'source_snapshot' => 'array',
        'target_snapshot' => 'array',
        'quoted_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_alerted_at' => 'datetime',
        'legacy_refund_only_at' => 'datetime',
        'completed_at' => 'datetime',
        'credit_applied_at' => 'datetime',
        'capacity_mode' => 'string',
        'target_stock_reserved_quantity' => 'integer',
        'target_stock_reserved_at' => 'datetime',
        'target_stock_released_at' => 'datetime',
        'target_stock_consumed_at' => 'datetime',
    ];

    public static function activeStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_PAID_COMMITTED,
            self::STATUS_PROVISIONING,
            self::STATUS_RETRYABLE_FAILED,
            self::STATUS_NEEDS_ATTENTION,
        ];
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function configs()
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    public function calculateProratedAmount($oldItem, $newItem): Price
    {
        if (
            !$newItem ||
            ($oldItem && (
                (method_exists($newItem, 'is') && $newItem->is($oldItem)) ||
                (isset($oldItem->id, $newItem->id) && $oldItem->id === $newItem->id)
            ))
        ) {
            return $this->makePrice();
        }

        $plan = $this->service->plan;
        $pricing = app(ServiceUpgradePricingService::class);
        $newPrice = $newItem->price(
            null,
            $plan->billing_period,
            $plan->billing_unit,
            $this->service->currency_code
        );
        if (!$newPrice->available) {
            throw new \RuntimeException(
                'The selected upgrade value is unavailable in the service currency.'
            );
        }
        $newGross = (float) $pricing->customerRecurringAmount(
            $this->service,
            (float) $newPrice->price
        )['amount'];
        $oldGross = (float) $pricing->customerRecurringAmount(
            $this->service,
            $this->resolveOldItemPrice($oldItem)
        )['amount'];
        $total = ($newGross - $oldGross)
            * $this->remainingPeriodFactor();
        if ($total < 0) {
            $total = max($total, -$this->getMaxRefundAmount());
        }

        return $this->makePrice($total);
    }

    public function calculatePrice(): Price
    {
        $pricing = app(ServiceUpgradePricingService::class);
        $sourceRecurring = $pricing->currentRecurringBasis(
            $this->service
        );
        $refundable = $pricing->remainingRefundableValue(
            $this->service
        );
        $target = $this->targetPricing($this->targetConfigs());
        $total = $this->proratedDifferenceAmount(
            (float) $sourceRecurring['amount'],
            (float) $target['amount'],
            (float) $refundable['amount']
        );

        return $this->makePrice($total);
    }

    private function recurringAmount(
        Plan $plan,
        $configs
    ): float {
        $planPrice = $plan->price($this->service->currency_code);
        if (!$planPrice->available) {
            throw new \RuntimeException(
                'The target billing plan is unavailable in the service currency.'
            );
        }
        $amount = (float) $planPrice->price;
        $hasDynamicValue = false;

        foreach ($configs as $config) {
            $option = $config->configOption;
            if ($option?->isDynamicSlider() && $config->slider_value !== null) {
                $hasDynamicValue = true;
                $amount += $option->calculateDynamicPriceDelta(
                    (float) $config->slider_value,
                    $plan->billing_period,
                    $plan->billing_unit
                );

                continue;
            }

            if ($config->configValue !== null) {
                $configPrice = $config->configValue->price(
                    null,
                    $plan->billing_period,
                    $plan->billing_unit,
                    $this->service->currency_code
                );
                if (!$configPrice->available) {
                    throw new \RuntimeException(
                        "The selected value for {$option->name} is unavailable in the service currency."
                    );
                }
                $amount += (float) $configPrice->price;
            }
        }

        if ($hasDynamicValue) {
            $amount += $plan->dynamicSliderBasePrice();
        }

        return max(0, $amount);
    }

    public function resolveTargetCoupon(?Coupon $coupon): void
    {
        $this->resolvedTargetCoupon = $coupon;
        $this->targetCouponResolved = true;
    }

    public function targetProperties(): array
    {
        $properties = collect(
            ExtensionHelper::getServiceProperties($this->service)
        )->mapWithKeys(fn ($value, $key) => [
            strtolower((string) $key) => $value,
        ]);
        $targetSettings = collect(
            ExtensionHelper::settingsToArray($this->product->settings)
        )->mapWithKeys(fn ($value, $key) => [
            strtolower((string) $key) => $value,
        ]);

        $sourceManagedKeys = collect($this->managedPropertyKeys(
            $this->service->product,
            $this->service->configs
        ));
        $targetManagedKeys = collect($this->managedPropertyKeys(
            $this->product,
            $this->configs
        ));

        // Preserve provider-owned identity fields, but never carry a
        // source-product configuration field into a different target schema.
        foreach ($sourceManagedKeys->diff($targetManagedKeys) as $key) {
            $properties->forget($key);
        }

        // Product settings are authoritative for the selected target. A stale
        // per-service property with the same key must not override them.
        foreach ($targetSettings->keys() as $key) {
            $properties->forget($key);
        }
        $properties = $properties->merge($targetSettings);

        foreach ($this->configs as $config) {
            $option = $config->configOption;
            if ($option === null) {
                throw new \RuntimeException(
                    'The target upgrade contains a configuration whose option no longer exists.'
                );
            }

            $key = $this->propertyKey($option);
            if ($option->isDynamicSlider()) {
                if ($config->slider_value !== null) {
                    $numericValue = StrictInteger::parseStoredDecimal(
                        $config->slider_value
                    );
                    if ($numericValue === null) {
                        throw new \RuntimeException(
                            "The target value for {$option->name} must be a whole number."
                        );
                    }

                    $properties[$key] = $option->normalizeDynamicSliderValue(
                        $numericValue
                    );
                }

                continue;
            }

            if ($config->configValue !== null) {
                $properties[$key] = $config->configValue->env_variable
                    ?: $config->configValue->name;
            }
        }

        return $properties->sortKeys()->all();
    }

    /**
     * @return array{memory: int, cpu: int, disk: int, location: int}
     */
    public function targetResources(): array
    {
        $properties = collect($this->targetProperties())
            ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value]);

        $resources = [];
        foreach (['memory', 'cpu', 'disk'] as $key) {
            $value = StrictInteger::parse($properties->get($key))
                ?? StrictInteger::parseStoredDecimal($properties->get($key));
            if ($value === null || $value <= 0) {
                throw new \RuntimeException(
                    "The target upgrade {$key} must be a positive whole number."
                );
            }
            $resources[$key] = $value;
        }

        $location = StrictInteger::parse($properties->get('location'))
            ?? StrictInteger::parseStoredDecimal($properties->get('location'));
        if ($location === null) {
            $locationIds = $properties->get('location_ids');
            if (is_string($locationIds)) {
                $decoded = json_decode($locationIds, true);
                $locationIds = json_last_error() === JSON_ERROR_NONE
                    && is_array($decoded)
                        ? $decoded
                        : [$locationIds];
            } elseif (!is_array($locationIds)) {
                $locationIds = [$locationIds];
            }
            if (!array_is_list($locationIds) || count($locationIds) !== 1) {
                throw new \RuntimeException(
                    'The target upgrade must resolve to exactly one location.'
                );
            }
            $location = StrictInteger::parse($locationIds[0])
                ?? StrictInteger::parseStoredDecimal($locationIds[0]);
        }
        if ($location === null || $location <= 0) {
            throw new \RuntimeException(
                'The target upgrade location must be a positive whole number.'
            );
        }

        return [
            ...$resources,
            'location' => $location,
        ];
    }

    public function captureSnapshots(): void
    {
        $this->loadMissing([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan',
            'service.configs.configOption',
            'service.configs.configValue',
            'product',
            'product.settings',
            'plan',
            'configs.configOption',
            'configs.configValue',
        ]);
        if (
            (string) $this->plan->type
                !== (string) $this->service->plan->type
        ) {
            throw new \RuntimeException(
                'Service upgrades must preserve the service billing type.'
            );
        }

        $sourceProperties = collect(array_merge(
            ExtensionHelper::settingsToArray($this->service->product->settings),
            ExtensionHelper::getServiceProperties($this->service)
        ))
            ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value])
            ->sortKeys()
            ->all();
        $sourceConfigs = $this->configVector($this->service->configs);
        $targetProperties = collect($this->targetProperties())
            ->mapWithKeys(fn ($value, $key) => [
                strtolower((string) $key) => $value,
            ])
            ->sortKeys()
            ->all();
        $targetConfigs = $this->targetConfigs();
        $targetConfigVector = $this->configVector($targetConfigs);
        $pricing = app(ServiceUpgradePricingService::class);
        $sourceRecurring = $pricing->currentRecurringBasis(
            $this->service
        );
        $refundable = $pricing->remainingRefundableValue(
            $this->service
        );
        $targetPricing = $this->targetPricing($targetConfigs);
        $upgradePrice = $this->proratedDifferenceAmount(
            (float) $sourceRecurring['amount'],
            (float) $targetPricing['amount'],
            (float) $refundable['amount']
        );
        $creditAmount = config(
            'settings.credits_on_downgrade',
            true
        )
            ? max(0, -$upgradePrice)
            : 0;
        $provisioner = app(
            UpgradeProvisionerIdentityService::class
        )->identity(
            $this->service->product,
            $this->product,
            $this->service
        );

        $source = [
            'service_id' => (int) $this->service_id,
            'user_id' => (int) $this->service->user_id,
            'product_id' => (int) $this->service->product_id,
            'plan_id' => (int) $this->service->plan_id,
            'plan_type' => (string) $this->service->plan->type,
            'quantity' => (int) $this->service->quantity,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => $sourceProperties,
            'configs' => $sourceConfigs,
            'managed_property_keys' => $this->managedPropertyKeys(
                $this->service->product,
                $this->service->configs
            ),
            'billing_anchor' => $this->billingAnchor($this->service),
            'provisioner' => $provisioner,
        ];
        $target = [
            'service_id' => (int) $this->service_id,
            'user_id' => (int) $this->service->user_id,
            'product_id' => (int) $this->product_id,
            'plan_id' => (int) $this->plan_id,
            'plan_type' => (string) $this->plan->type,
            'quantity' => 1,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => $targetProperties,
            'configs' => $targetConfigVector,
            'managed_property_keys' => $this->managedPropertyKeys(
                $this->product,
                $targetConfigs
            ),
            'recurring_price' => number_format(
                (float) $targetPricing['amount'],
                2,
                '.',
                ''
            ),
            'upgrade_price' => number_format(
                $upgradePrice,
                2,
                '.',
                ''
            ),
            'credit_amount' => number_format(
                $creditAmount,
                2,
                '.',
                ''
            ),
            'coupon_id' => $targetPricing['coupon_id'],
            'tax' => $targetPricing['tax'],
            'billing_anchor' => $this->billingAnchor($this->service),
            'provisioner' => $provisioner,
        ];

        $this->source_snapshot = $source;
        $this->target_snapshot = $target;
        $this->source_fingerprint = $this->fingerprint($source);
        $this->target_fingerprint = $this->fingerprint($target);
    }

    public function sourceStillMatches(): bool
    {
        try {
            $provisioner = app(
                UpgradeProvisionerIdentityService::class
            )->identity(
                $this->service->product,
                $this->product,
                $this->service
            );
        } catch (\Throwable) {
            return false;
        }
        $current = [
            'service_id' => (int) $this->service_id,
            'user_id' => (int) $this->service->user_id,
            'product_id' => (int) $this->service->product_id,
            'plan_id' => (int) $this->service->plan_id,
            'plan_type' => (string) $this->service->plan->type,
            'quantity' => (int) $this->service->quantity,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => collect(array_merge(
                ExtensionHelper::settingsToArray($this->service->product->settings),
                ExtensionHelper::getServiceProperties($this->service)
            ))
                ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value])
                ->sortKeys()
                ->all(),
            'configs' => $this->configVector($this->service->configs),
            'managed_property_keys' => $this->managedPropertyKeys(
                $this->service->product,
                $this->service->configs
            ),
            'billing_anchor' => $this->billingAnchor($this->service),
            'provisioner' => $provisioner,
        ];

        return $this->source_fingerprint !== null
            && hash_equals((string) $this->source_fingerprint, $this->fingerprint($current));
    }

    public function targetSemanticallyMatchesSource(): bool
    {
        if (!$this->snapshotFingerprintsAreAuthentic()) {
            return false;
        }

        $source = (array) $this->source_snapshot;
        $target = (array) $this->target_snapshot;
        foreach ([
            'product_id',
            'plan_id',
            'plan_type',
            'quantity',
            'currency_code',
            'properties',
            'configs',
            'managed_property_keys',
        ] as $key) {
            if (
                $this->canonicalizeValue($source[$key] ?? null)
                !== $this->canonicalizeValue($target[$key] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    public function snapshotFingerprintsAreAuthentic(): bool
    {
        return $this->snapshotFingerprintIsAuthentic(
            $this->source_snapshot,
            $this->source_fingerprint
        ) && $this->snapshotFingerprintIsAuthentic(
            $this->target_snapshot,
            $this->target_fingerprint
        );
    }

    public function signedUpgradePrice(): Price
    {
        if (!$this->snapshotFingerprintsAreAuthentic()) {
            throw new \RuntimeException(
                'The signed upgrade pricing snapshot is invalid.'
            );
        }
        $amount = data_get($this->target_snapshot, 'upgrade_price');
        if (
            !is_string($amount)
            || preg_match(
                '/^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D',
                $amount
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The signed upgrade amount is invalid.'
            );
        }

        return $this->makePrice((float) $amount);
    }

    public function signedCreditAmount(): float
    {
        if (!$this->snapshotFingerprintsAreAuthentic()) {
            throw new \RuntimeException(
                'The signed upgrade pricing snapshot is invalid.'
            );
        }
        $amount = data_get($this->target_snapshot, 'credit_amount');
        if (
            !is_string($amount)
            || preg_match(
                '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D',
                $amount
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The signed upgrade credit amount is invalid.'
            );
        }

        return (float) $amount;
    }

    private function proratedDifferenceAmount(
        float $sourceRecurring,
        float $targetRecurring,
        float $remainingRefundable
    ): float {
        $factor = $this->remainingPeriodFactor();
        $total = ($targetRecurring - $sourceRecurring) * $factor;

        // A downgrade can never credit more than the unused portion of the
        // exact billing obligation the customer actually prepaid.
        return max($total, -max(0, $remainingRefundable));
    }

    private function remainingPeriodFactor(): float
    {
        return app(ServiceUpgradePricingService::class)
            ->periodProration($this->service)['factor'];
    }

    private function targetConfigs()
    {
        return (int) $this->product_id
            === (int) $this->service->product_id
                ? $this->configs
                    ->keyBy('config_option_id')
                    ->union(
                        $this->service->configs->keyBy('config_option_id')
                    )
                    ->values()
                : $this->configs;
    }

    /**
     * @return array{
     *     amount: string,
     *     coupon_id: int|null,
     *     tax: array<string, mixed>
     * }
     */
    private function targetPricing($targetConfigs): array
    {
        $pricing = app(ServiceUpgradePricingService::class);
        $coupon = $this->targetCouponResolved
            ? $this->resolvedTargetCoupon
            : $pricing->targetCoupon(
                $this->service,
                $this->product
            );
        $catalogAmount = $this->recurringAmount(
            $this->plan,
            $targetConfigs
        );
        $recurring = $pricing->customerRecurringAmount(
            $this->service,
            $catalogAmount
        );
        if (
            $coupon !== null
            && $pricing->couponAppliesToNextCharge(
                $coupon,
                $this->service
            )
        ) {
            // Checkout applies fixed and percentage discounts to the
            // customer-facing amount after exclusive tax has been added.
            // Preserve that same order here so an upgrade cannot create a
            // different recurring obligation for the identical target.
            $recurring['amount'] = number_format(
                max(
                    0,
                    (float) $recurring['amount']
                        - $coupon->calculateDiscount(
                            (float) $recurring['amount']
                        )
                ),
                2,
                '.',
                ''
            );
        }

        return [
            ...$recurring,
            'coupon_id' => $coupon?->id,
        ];
    }

    /**
     * Freeze the renewal/proration basis. A renewal, coupon mutation, stored
     * recurring-price change, or service-invoice transition requires a fresh
     * quote instead of applying a pre-renewal delta after the boundary moved.
     *
     * @return array<string, mixed>
     */
    private function billingAnchor(Service $service): array
    {
        $service->loadMissing('coupon');
        $pricing = app(ServiceUpgradePricingService::class);
        $latestInvoice = $service->invoices()
            ->orderByDesc('invoices.id')
            ->first();
        $latestLine = $latestInvoice?->items()
            ->where('reference_type', Service::class)
            ->where('reference_id', $service->id)
            ->orderBy('id')
            ->first();
        $coupon = $service->coupon;

        return [
            'expires_at' => $service->expires_at?->toDateString(),
            'stored_recurring_price' => (string) $service->price,
            'billing_cycles_completed' => (int) $service->billing_cycles_completed,
            'coupon' => $coupon === null ? null : [
                'id' => (int) $coupon->id,
                'type' => (string) $coupon->type,
                'value' => (string) $coupon->value,
                'recurring' => $coupon->recurring === null
                    ? null
                    : (int) $coupon->recurring,
                'applies_to' => (string) $coupon->applies_to,
                'updated_at' => $coupon->updated_at?->toIso8601String(),
            ],
            'paid_service_invoice_count' => $service->invoices()
                ->where('status', Invoice::STATUS_PAID)
                ->count(),
            'current_prepaid_basis' => $pricing->currentPrepaidBasis($service),
            'current_recurring_basis' => $pricing->currentRecurringBasis($service),
            'target_coupon_eligibility' => $pricing->couponEligibilityEvidence(
                $service,
                $this->product
            ),
            'latest_service_invoice' => $latestInvoice === null ? null : [
                'id' => (int) $latestInvoice->id,
                'status' => (string) $latestInvoice->status,
                'due_at' => $latestInvoice->due_at?->toIso8601String(),
                'line_id' => $latestLine?->id,
                'line_price' => $latestLine === null
                    ? null
                    : (string) $latestLine->price,
                'line_quantity' => $latestLine?->quantity,
                'updated_at' => $latestInvoice->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
        ));
    }

    private function snapshotFingerprintIsAuthentic(
        mixed $snapshot,
        mixed $fingerprint
    ): bool {
        return is_array($snapshot)
            && is_string($fingerprint)
            && strlen($fingerprint) === 64
            && hash_equals($fingerprint, $this->fingerprint($snapshot));
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        return is_array($value) ? $this->canonicalize($value) : $value;
    }

    /**
     * @return list<array{
     *     config_option_id: int,
     *     option_type: string,
     *     property_key: string,
     *     config_value_id: ?int,
     *     slider_value: ?int
     * }>
     */
    private function configVector(iterable $configs): array
    {
        return collect($configs)
            ->map(function (ServiceConfig $config): array {
                $option = $config->configOption;
                if ($option === null) {
                    throw new \RuntimeException(
                        'An upgrade configuration references an option that no longer exists.'
                    );
                }

                $sliderValue = null;
                if ($option->isDynamicSlider()) {
                    $sliderValue = StrictInteger::parseStoredDecimal(
                        $config->slider_value
                    );
                    if ($sliderValue === null) {
                        throw new \RuntimeException(
                            "The stored value for {$option->name} must be a whole number."
                        );
                    }
                }

                return [
                    'config_option_id' => (int) $option->id,
                    'option_type' => (string) $option->type,
                    'property_key' => $this->propertyKey($option),
                    'config_value_id' => $config->config_value_id === null
                        ? null
                        : (int) $config->config_value_id,
                    'slider_value' => $sliderValue,
                ];
            })
            ->sortBy(fn (array $config): string => implode(':', [
                str_pad((string) $config['config_option_id'], 20, '0', STR_PAD_LEFT),
                (string) ($config['config_value_id'] ?? ''),
                (string) ($config['slider_value'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function managedPropertyKeys(Product $product, iterable $configs): array
    {
        $product->loadMissing('configOptions');
        $settingKeys = collect(
            ExtensionHelper::settingsToArray($product->settings)
        )->keys();
        $productConfigKeys = $product->configOptions
            ->map(fn (ConfigOption $option): string => $this->propertyKey($option));
        $configKeys = collect($configs)
            ->map(function (ServiceConfig $config): string {
                if ($config->configOption === null) {
                    throw new \RuntimeException(
                        'A managed configuration option no longer exists.'
                    );
                }

                return $this->propertyKey($config->configOption);
            });

        return $settingKeys
            ->merge($productConfigKeys)
            ->merge($configKeys)
            ->map(fn ($key): string => strtolower(trim((string) $key)))
            ->filter(fn (string $key): bool => $key !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function propertyKey(ConfigOption $option): string
    {
        $key = strtolower(trim((string) (
            $option->env_variable ?: $option->name
        )));
        if ($key === '') {
            throw new \RuntimeException(
                'A managed configuration option has no property key.'
            );
        }

        return $key;
    }

    protected function resolveOldItemPrice($oldItem): float
    {
        if (empty($oldItem)) {
            return 0;
        }

        $price = $oldItem->price(
            null,
            $this->service->plan->billing_period,
            $this->service->plan->billing_unit,
            $this->service->currency_code
        )->price ?? 0;

        return (float) $price;
    }

    protected function makePrice(float $amount = 0): Price
    {
        return new Price([
            'price' => $amount,
            'currency' => $this->service->currency,
        ]);
    }

    public function getMaxRefundAmount(): float
    {
        $refundable = app(ServiceUpgradePricingService::class)
            ->remainingRefundableValue($this->service);

        return (float) $refundable['amount'];
    }
}
