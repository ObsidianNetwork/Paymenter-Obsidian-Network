<?php

namespace App\Models;

use App\Models\Concerns\SerializesCapacityConfigurationMutations;
use App\Models\Traits\HasPlans;
use App\Rules\DynamicSliderValueRule;
use App\Services\Service\CapacityConfigurationMutationGuard;
use App\Support\StrictDecimal;
use App\Support\StrictInteger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class ConfigOption extends Model implements Auditable
{
    use HasFactory, HasPlans, SerializesCapacityConfigurationMutations, Traits\Auditable;

    protected $dontShowUnavailablePrice = true;

    protected $fillable = [
        'name',
        'description',
        'env_variable',
        'type',
        'sort',
        'hidden',
        'parent_id',
        'upgradable',
        'metadata',
    ];

    protected $casts = [
        'hidden' => 'boolean',
        'upgradable' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ConfigOption $option): void {
            $originalMetadata = json_decode(
                (string) $option->getRawOriginal('metadata'),
                true
            );
            $originalMetadata = is_array($originalMetadata)
                ? $originalMetadata
                : [];
            $currentMetadata = (array) ($option->metadata ?? []);
            $identityChanged = $option->exists
                && (
                    $option->isDirty([
                        'type',
                        'env_variable',
                        'parent_id',
                        'hidden',
                        'upgradable',
                    ])
                    || data_get($originalMetadata, 'resource_type')
                        !== data_get($currentMetadata, 'resource_type')
                    || data_get($originalMetadata, 'managed_location_id')
                        !== data_get(
                            $currentMetadata,
                            'managed_location_id'
                        )
                );

            $guard = app(CapacityConfigurationMutationGuard::class);
            $guard->assertDynamicResourceActivationSafe($option);
            $guard->assertConfigOptionMutable($option, $identityChanged);
        });
        static::deleting(
            fn (ConfigOption $option) =>
                app(CapacityConfigurationMutationGuard::class)
                    ->assertConfigOptionMutable($option, true)
        );
    }

    /**
     * Check if this is a dynamic slider type
     */
    public function isDynamicSlider(): bool
    {
        return $this->type === 'dynamic_slider';
    }

    /**
     * Get a metadata value with a default
     */
    public function getMetadata(string $key, $default = null)
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Validate and normalize a submitted slider value before it is priced,
     * reserved, or provisioned.
     */
    public function normalizeDynamicSliderValue(mixed $value): int
    {
        if (! $this->isDynamicSlider()) {
            throw new \InvalidArgumentException('Only dynamic sliders have numeric resource values.');
        }

        $errors = [];
        (new DynamicSliderValueRule($this))->validate(
            'resource',
            $value,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return (int) $value;
    }

    /**
     * Calculate the marginal (delta) price for a dynamic slider value.
     * Does NOT include the shared per-product base_price — that is handled
     * at the plan level via Plan::dynamicSliderBasePrice().
     */
    public function calculateDynamicPriceDelta(
        float $value,
        int $billingPeriod = 1,
        ?string $billingUnit = 'month'
    ): float
    {
        if (! $this->isDynamicSlider()) {
            return 0;
        }
        if (
            ! is_finite($value)
            || $value < 0
            || floor($value) !== $value
            || $value > StrictInteger::MAX_STORED_SLIDER_VALUE
        ) {
            throw new \InvalidArgumentException(
                'Dynamic-slider values must be finite whole internal resource units.'
            );
        }

        $pricing = $this->metadata['pricing'] ?? [];
        $model = $pricing['model'] ?? 'linear';

        $monthlyDelta = match ($model) {
            'linear'     => $this->calculateLinearDelta($value, $pricing),
            'tiered'     => $this->calculateTieredDelta($value, $pricing),
            'base_addon' => $this->calculateBaseAddonDelta($value, $pricing),
            default      => throw new \InvalidArgumentException("Unknown dynamic_slider pricing model: ".var_export($model, true)),
        };

        return $this->guardCalculatedPrice(
            $monthlyDelta * $this->getBillingMultiplier($billingPeriod, $billingUnit)
        );
    }

    /**
     * @deprecated Use calculateDynamicPriceDelta() for the marginal charge and add
     *             plan->dynamicSliderBasePrice() once per product for the shared base.
     *             This alias returns delta + sharedBase so existing callers see the
     *             same total they always did (base_price counted once, not per-slider).
     */
    public function calculateDynamicPrice(
        float $value,
        int $billingPeriod = 1,
        ?string $billingUnit = 'month'
    ): float
    {
        $pricing = $this->metadata['pricing'] ?? [];
        $sharedBase = $this->pricingDecimal(
            $pricing['base_price'] ?? 0,
            'base price',
            99_999_999.99
        );
        $multiplier = $this->getBillingMultiplier($billingPeriod, $billingUnit);

        return $this->guardCalculatedPrice(
            $this->calculateDynamicPriceDelta($value, $billingPeriod, $billingUnit)
                + ($sharedBase * $multiplier)
        );
    }

    /**
     * Calculate linear marginal price: (displayValue * rate_per_unit) — no base_price.
     */
    private function calculateLinearDelta(float $value, array $pricing): float
    {
        $displayDivisor = $this->displayDivisor();
        $displayValue = $value / $displayDivisor;
        $ratePerUnit = $this->pricingDecimal(
            $pricing['rate_per_unit'] ?? null,
            'rate per unit'
        );

        return $displayValue * $ratePerUnit;
    }

    /**
     * Calculate tiered marginal price — no base_price.
     */
    private function calculateTieredDelta(float $value, array $pricing): float
    {
        $displayDivisor = $this->displayDivisor();
        $remainingUnits = $value / $displayDivisor;

        $total = 0.0;
        $previousLimit = 0.0;

        foreach ($pricing['tiers'] ?? [] as $tier) {
            if (! is_array($tier)) {
                throw new \InvalidArgumentException(
                    'Tiered dynamic-slider pricing contains an invalid tier.'
                );
            }
            if ($remainingUnits <= 0) {
                break;
            }

            $rate = $this->pricingDecimal(
                $tier['rate'] ?? null,
                'tier rate'
            );
            $tierLimit = ($tier['up_to'] ?? null) === null
                ? $previousLimit + $remainingUnits
                : $this->pricingDecimal($tier['up_to'], 'tier upper bound');
            if ($tierLimit <= $previousLimit) {
                throw new \InvalidArgumentException(
                    'Tiered dynamic-slider pricing limits must increase.'
                );
            }

            $tierSize = $tierLimit - $previousLimit;
            $unitsInTier = min($remainingUnits, $tierSize);

            $total += $unitsInTier * $rate;
            $remainingUnits -= $unitsInTier;
            $previousLimit = $tierLimit;
        }

        if ($remainingUnits > 0.000001) {
            throw new \InvalidArgumentException(
                'Tiered dynamic-slider pricing does not cover the selected value.'
            );
        }

        return $total;
    }

    /**
     * Calculate base+addon marginal price — no base_price.
     */
    private function calculateBaseAddonDelta(float $value, array $pricing): float
    {
        $displayDivisor = $this->displayDivisor();
        $displayValue = $value / $displayDivisor;

        $includedUnits = $this->pricingDecimal(
            $pricing['included_units'] ?? null,
            'included units'
        );
        $overageRate = $this->pricingDecimal(
            $pricing['overage_rate'] ?? null,
            'overage rate'
        );

        $overageUnits = max(0, $displayValue - $includedUnits);

        return $overageUnits * $overageRate;
    }

    /**
     * Get billing period multiplier (assumes rates are monthly)
     */
    private function getBillingMultiplier(
        int $billingPeriod,
        ?string $billingUnit
    ): float
    {
        if ($billingPeriod < 1) {
            throw new \InvalidArgumentException(
                'Dynamic-slider billing periods must be positive.'
            );
        }

        return match ($billingUnit) {
            'day' => $billingPeriod / 30,
            'week' => $billingPeriod / 4,
            'month' => $billingPeriod,
            'year' => $billingPeriod * 12,
            null => 1.0,
            default => throw new \InvalidArgumentException(
                'Unknown dynamic-slider billing unit.'
            ),
        };
    }

    private function displayDivisor(): int
    {
        $divisor = StrictInteger::parse(
            $this->metadata['display_divisor'] ?? 1
        );
        if ($divisor === null || $divisor <= 0) {
            throw new \InvalidArgumentException(
                'Dynamic-slider display divisor must be a positive integer.'
            );
        }

        return $divisor;
    }

    private function pricingDecimal(
        mixed $value,
        string $field,
        float $maximum = StrictDecimal::MAX_VALUE
    ): float {
        $parsed = StrictDecimal::parseNonNegative($value, $maximum);
        if ($parsed === null) {
            throw new \InvalidArgumentException(
                "Dynamic-slider {$field} must be a finite non-negative decimal."
            );
        }

        return $parsed;
    }

    private function guardCalculatedPrice(float $price): float
    {
        // Leave an order of magnitude of headroom in the DECIMAL(17,2)
        // invoice columns for base prices, other sliders, taxes, and fees.
        if (
            ! is_finite($price)
            || $price < 0
            || $price > 99_999_999_999_999.99
        ) {
            throw new \InvalidArgumentException(
                'Dynamic-slider pricing exceeds the supported invoice range.'
            );
        }

        return $price;
    }

    /**
     * Format value for display (e.g., 4096 MB -> "4 GB")
     */
    public function formatValueForDisplay(float $value): string
    {
        $metadata = $this->metadata ?? [];
        $displayDivisor = $metadata['display_divisor'] ?? 1;
        $displayUnit = $metadata['display_unit'] ?? $metadata['unit'] ?? '';
        $resourceType = $metadata['resource_type'] ?? 'custom';

        // Handle CPU percentage special case
        if ($resourceType === 'cpu') {
            $cores = $value / 100;

            return $cores.' '.($cores == 1 ? 'core' : 'cores');
        }

        $displayValue = $value / $displayDivisor;

        // Format nicely - remove decimals if whole number
        $formatted = $displayValue == (int) $displayValue
            ? (int) $displayValue
            : number_format($displayValue, 1);

        return $formatted.' '.$displayUnit;
    }

    /**
     * Get the parent option.
     */
    public function parent()
    {
        return $this->belongsTo(ConfigOption::class, 'parent_id');
    }

    /**
     * Get the options that belong to the parent. (children or options)
     */
    public function children()
    {
        return $this->hasMany(ConfigOption::class, 'parent_id')->orderBy('sort');
    }

    /**
     * Customer-selectable children. Hidden values remain available through the
     * unfiltered relation so existing services keep their historical identity.
     */
    public function availableChildren()
    {
        return $this->children()->where('hidden', false);
    }

    /**
     * Get the products that belong to the option.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'config_option_products')
            ->using(ConfigOptionProduct::class);
    }

    /**
     * Get the service configs that belong to the option.
     */
    public function serviceConfigs()
    {
        return $this->hasMany(ServiceConfig::class, 'config_option_id');
    }
}
