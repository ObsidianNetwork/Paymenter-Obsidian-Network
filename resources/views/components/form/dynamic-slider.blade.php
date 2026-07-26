@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $metadata = $config->metadata ?? [];
    $min = $metadata['min'] ?? 1024;
    $max = $metadata['max'] ?? 65536;
    $step = $metadata['step'] ?? 1024;
    $default = $metadata['default'] ?? $min;
    $unit = $metadata['unit'] ?? 'MB';
    $displayUnit = $metadata['display_unit'] ?? 'GB';
    $displayDivisor = max(1, (int) ($metadata['display_divisor'] ?? 1024));
    $resourceType = $metadata['resource_type'] ?? 'custom';
    $pricing = $metadata['pricing'] ?? [];
    $pricingModel = $pricing['model'] ?? 'linear';
    $ratePerUnit = $pricing['rate_per_unit'] ?? 0;
    $tiers = $pricing['tiers'] ?? [];
    $includedUnits = $pricing['included_units'] ?? 0;
    $overageRate = $pricing['overage_rate'] ?? 0;
    $billingPeriod = $plan->billing_period ?? 1;
    $billingUnit = $plan->billing_unit ?? 'month';
    $currencySymbol = config('settings.currency_sign', '$');
    $billingSuffix = '/ ' . ($billingPeriod > 1 ? $billingPeriod . ' ' : '') . $billingUnit . ($billingPeriod > 1 ? 's' : '');
    $sliderErrorId = 'slider-error-' . $config->id;
    $sliderDescribedBy = 'slider-price-' . $config->id . ' slider-hint-' . $config->id;
    if ($errors->has($name)) {
        $sliderDescribedBy .= ' ' . $sliderErrorId;
    }
@endphp
<div x-data="{
    value: $wire.entangle('{{ $name }}').live,
    optionId: {{ (int) $config->id }},
    min: {{ $min }},
    max: {{ $max }},
    configuredMax: {{ $max }},
    step: {{ $step }},
    defaultValue: {{ $default }},
    displayDivisor: {{ $displayDivisor }},
    displayUnit: @js($displayUnit),
    resourceType: @js($resourceType),
    pricingModel: @js($pricingModel),
    ratePerUnit: {{ $ratePerUnit }},
    // Slider badges show this option's marginal charge. The one shared base
    // is rendered from Plan::dynamicSliderBasePrice() in the order summary.
    basePrice: 0,
    tiers: @js($tiers),
    includedUnits: {{ $includedUnits }},
    overageRate: {{ $overageRate }},
    billingPeriod: {{ $billingPeriod }},
    billingUnit: @js($billingUnit),
    billingSuffix: @js($billingSuffix),
    currencySymbol: @js($currencySymbol),
    progressPercent: '0%',
    stockManaged: {{ in_array(strtolower((string) $config->getMetadata('resource_type', '')), ['memory', 'cpu', 'disk'], true) ? 'true' : 'false' }},
    stockDisabled: false,
    displayPrice: null,

    init() {
        if (this.value == null || this.value < this.min || this.value > this.max) {
            this.value = this.defaultValue;
        }

        this.displayPrice = this.calculatePrice();
        this.updateProgress();
        this.$nextTick(() => this.$dispatch('slider-change', { resourceType: this.resourceType, value: this.numericValue, initialize: true }));

        $watch('value', Alpine.debounce(() => {
            this.updateProgress();
            this.displayPrice = this.calculatePrice();
        }, 300));
    },

    get numericValue() {
        const numericValue = Number(this.value);

        return Number.isFinite(numericValue) ? numericValue : this.defaultValue;
    },

    get formattedValue() {
        return this.formatValueForDisplay(this.numericValue);
    },

    get formattedPrice() {
        return `${this.currencySymbol}${this.displayPrice ?? this.calculatePrice()} ${this.billingSuffix}`.trim();
    },

    updateProgress() {
        const range = this.max - this.min;
        const current = this.numericValue - this.min;
        this.progressPercent = range > 0 ? `${(current / range) * 100}%` : '0%';
    },

    formatValueForDisplay(value) {
        if (!Number.isFinite(value)) {
            return String(this.value);
        }

        if (this.resourceType === 'cpu') {
            const cores = value / 100;
            return cores + ' ' + (cores === 1 ? 'core' : 'cores');
        }

        const displayValue = value / this.displayDivisor;
        const formatted = displayValue === Math.floor(displayValue)
            ? Math.floor(displayValue)
            : displayValue.toFixed(1);

        return formatted + ' ' + this.displayUnit;
    },

    calculatePrice() {
        let monthlyPrice;

        switch (this.pricingModel) {
            case 'tiered':
                monthlyPrice = this.calculateTieredPrice();
                break;
            case 'base_addon':
                monthlyPrice = this.calculateBaseAddonPrice();
                break;
            default:
                monthlyPrice = this.calculateLinearPrice();
        }

        return (monthlyPrice * this.getBillingMultiplier()).toFixed(2);
    },

    calculateLinearPrice() {
        const displayValue = this.numericValue / this.displayDivisor;
        return this.basePrice + (displayValue * this.ratePerUnit);
    },

    calculateTieredPrice() {
        let remainingUnits = this.numericValue / this.displayDivisor;
        let total = this.basePrice;
        let previousLimit = 0;

        for (const tier of this.tiers) {
            if (remainingUnits <= 0) break;

            const tierLimit = tier.up_to ? parseFloat(tier.up_to) : Infinity;
            const tierSize = tierLimit - previousLimit;
            const unitsInTier = Math.min(remainingUnits, tierSize);

            total += unitsInTier * (parseFloat(tier.rate) || 0);
            remainingUnits -= unitsInTier;
            previousLimit = tierLimit;
        }

        return total;
    },

    calculateBaseAddonPrice() {
        const displayValue = this.numericValue / this.displayDivisor;
        const overageUnits = Math.max(0, displayValue - this.includedUnits);
        return this.basePrice + (overageUnits * this.overageRate);
    },

    getBillingMultiplier() {
        switch (this.billingUnit) {
            case 'day': return this.billingPeriod / 30;
            case 'week': return this.billingPeriod / 4;
            case 'year': return this.billingPeriod * 12;
            default: return this.billingPeriod;
        }
    },

    handleInput() {
        this.updateProgress();
    },

    applyKeyboardValue(nextValue) {
        const clamped = Math.min(this.max, Math.max(this.min, nextValue));

        if (this.numericValue === clamped) {
            return;
        }

        this.value = clamped;
        this.handleInput();
        this.$dispatch('slider-change', {
            resourceType: this.resourceType,
            value: this.numericValue,
        });
    },

    applyCapacityQuote(quote) {
        const bounds = Object.values(quote?.bounds || {});
        const bound = bounds.find((candidate) => Number(candidate?.config_option_id) === this.optionId);

        if (!bound) {
            return;
        }

        const nextMin = Number(bound.min);
        const nextMax = Math.min(this.configuredMax, Number(bound.max));
        const nextStep = Number(bound.step);

        if (!Number.isSafeInteger(nextMin)
            || !Number.isSafeInteger(nextMax)
            || !Number.isSafeInteger(nextStep)
            || nextStep <= 0
            || nextMax < nextMin
        ) {
            this.stockDisabled = true;
            return;
        }

        this.min = nextMin;
        this.max = nextMax;
        this.step = nextStep;

        const selected = quote?.selection?.[this.resourceType];
        const hasSelectedValue = selected !== null
            && selected !== undefined
            && selected !== ''
            && Number.isSafeInteger(Number(selected));
        const candidate = hasSelectedValue ? Number(selected) : this.numericValue;
        const clamped = this.min + Math.floor((Math.min(this.max, Math.max(this.min, candidate)) - this.min) / this.step) * this.step;

        if (this.numericValue !== clamped) {
            this.value = clamped;
        }

        this.stockDisabled = false;
        this.updateProgress();
    }
}"
    x-on:dynamic-capacity-loading.window="if (stockManaged) stockDisabled = false"
    x-on:dynamic-capacity-updated.window="applyCapacityQuote($event.detail)"
    x-on:dynamic-capacity-failed.window="if (stockManaged) stockDisabled = false"
    class="flex flex-col gap-1 relative"
>
    <label id="slider-label-{{ $config->id }}" for="{{ $name }}" class="mb-1 text-sm text-primary-100">
        {{ $config->label ?? $config->name }}
    </label>
    <div class="relative flex items-center" :style="`--progress:${progressPercent}`" wire:ignore>
        <div class="
            absolute left-2.5 right-2.5 h-1.5 bg-background-secondary rounded-full overflow-hidden transition-all duration-300 ease-out
            before:absolute before:inset-0 before:bg-primary
            before:[mask-image:_linear-gradient(to_right,theme(colors.white),theme(colors.white)_var(--progress),transparent_var(--progress))]
        " aria-hidden="true"></div>
        <input class="
            relative appearance-none cursor-pointer w-full bg-transparent focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary transition-all duration-300 ease-out
            [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:w-5
            [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:shadow-none
            [&::-webkit-slider-thumb]:focus:ring-0 [&::-moz-range-thumb]:h-5 [&::-moz-range-thumb]:w-5
            [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-base [&::-moz-range-thumb]:border-none
            [&::-moz-range-thumb]:shadow-none [&::-moz-range-thumb]:focus:ring-0 dynamic-slider-input
        " type="range"
            :min="min"
            :max="max"
            :step="step"
            :disabled="stockDisabled"
            x-model="value"
            @input="handleInput(); $dispatch('slider-change', { resourceType, value: numericValue })"
            x-on:keydown.page-up.prevent="applyKeyboardValue(numericValue + step * 10)"
            x-on:keydown.page-down.prevent="applyKeyboardValue(numericValue - step * 10)"
            x-on:keydown.home.prevent="applyKeyboardValue(min)"
            x-on:keydown.end.prevent="applyKeyboardValue(max)"
            role="slider"
            :aria-valuemin="min"
            :aria-valuemax="max"
            :aria-valuenow="value"
            :aria-valuetext="formattedValue"
            aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
            @if ($errors->has($name))
                aria-errormessage="{{ $sliderErrorId }}"
            @endif
            aria-labelledby="slider-label-{{ $config->id }}"
            aria-describedby="{{ $sliderDescribedBy }}"
            name="{{ $name }}"
            id="{{ $name }}" />
    </div>
    <output id="slider-price-{{ $config->id }}" role="status" aria-live="polite" aria-atomic="true" class="sr-only" x-text="formattedPrice" wire:ignore></output>
    <span id="slider-hint-{{ $config->id }}" class="sr-only" wire:ignore>Use arrow keys to adjust, Page Up/Down for larger steps, Home and End for minimum and maximum.</span>
    @error($name)
        <p id="{{ $sliderErrorId }}" role="alert" class="text-xs text-red-500">{{ $message }}</p>
    @enderror
    <!-- Value and Price Display -->
    <div class="flex justify-between items-center mt-2 px-2.5">
        <div class="flex items-center gap-2">
            <span class="text-sm font-semibold text-primary-100" x-text="formattedValue"></span>
            <span
                class="text-xs text-primary-500"
                x-text="`(${formatValueForDisplay(min)} - ${formatValueForDisplay(max)})`"
            ></span>
        </div>
        @if($showPriceTag ?? true)
            <span class="text-sm font-semibold text-primary">
                <span x-text="currencySymbol + (displayPrice ?? calculatePrice())"></span>
                <span class="text-xs text-primary-500">{{ $billingSuffix }}</span>
            </span>
        @endif
    </div>
    <style>
        /* Expand touch target without changing visual size (WCAG 2.5.8) */
        /* transparent border extends hit area to ~44px; content box stays 20px */
        /* Scoped to .dynamic-slider-input to avoid affecting other range inputs */
        input.dynamic-slider-input::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border: 12px solid transparent;
            background-clip: content-box;
            border-radius: 50%;
            cursor: pointer;
        }

        input.dynamic-slider-input::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border: 12px solid transparent;
            background-clip: content-box;
            box-sizing: content-box;
            border-radius: 50%;
            cursor: pointer;
        }

        @media (max-width: 320px) {
            input.dynamic-slider-input {
                max-width: 100%;
            }
        }
    </style>
</div>
