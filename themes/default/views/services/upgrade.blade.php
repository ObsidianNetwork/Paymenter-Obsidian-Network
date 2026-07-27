@php
    $usesDynamicStock = $this->hasDynamicSliderOptions();
    $dynamicStockOptionIds = $usesDynamicStock
        ? $this->upgradeConfigOptions()
            ->filter(fn ($option) => $option->isDynamicSlider()
                && in_array(
                    strtolower((string) $option->getMetadata('resource_type', '')),
                    ['memory', 'cpu', 'disk'],
                    true
                ))
            ->map(fn ($option) => (int) $option->id)
            ->values()
            ->all()
        : [];
    $dynamicStockConfig = [
        'endpoint' => $usesDynamicStock
            ? url('/api/dynamic-pterodactyl/services/' . $service->id . '/upgrade-quote')
            : null,
        'enabled' => $usesDynamicStock,
        'expectedBoundIds' => $dynamicStockOptionIds,
    ];
    $upgradeButtonAction = $this->upgradeConfigOptions()->count() > 0 && $step == 1
        ? 'nextStep'
        : 'doUpgrade';
    $upgradeButtonLabel = $upgradeProduct && $this->upgradeConfigOptions()->count() > 0 && $step == 1
        ? __('services.next_step')
        : __('services.upgrade');
    $dynamicUpgradeGate = $usesDynamicStock && $step > 1;
@endphp
<div
    class="container mt-14"
    @if ($usesDynamicStock)
        x-data="dynamicResourceStock(@js($dynamicStockConfig))"
        x-on:slider-change="queueQuote()"
        x-on:change="queueQuote()"
    @endif
>
    <h1 class="text-2xl font-bold">
        {{ __('services.upgrade_service', ['service' => $service->product->name]) }}
    </h1>

    <h2 class="text-lg font-semibold mt-4">
        @if($step == 1)
            {{ __('services.upgrade_choose_product') }}
        @else
            {{ __('services.upgrade_choose_config') }}
        @endif
    </h2>


    <div class="grid grid-cols-3 gap-6 mt-2">
        <div class="grid md:grid-cols-2 gap-6 col-span-2">
            @if($step == 1)
            {{-- Show current product, we also allow config upgrades so they can use that --}}
            <div>
                <input type="radio" name="upgrade" value="{{ $service->product->id }}" wire:model.live="upgrade"
                    class="hidden peer" id="product-{{ $service->product->id }}">
                <label for="product-{{ $service->product->id }}"
                    class="flex flex-col cursor-pointer bg-background-secondary hover:bg-background-secondary/80 border border-neutral peer-checked:border-secondary p-4 rounded-lg">
                    <div
                        class="rounded-full border border-background rounded-selector inline-flex items-center justify-center gap-2 align-middle bg-primary/60 w-fit px-2 py-0.5">
                        <p class="">{{ __('services.current_plan') }}</p>
                    </div>
                    @if(theme('small_images', false))
                    <div class="flex gap-x-3 items-center">
                        @endif
                        @if ($service->product->image)
                        <img src="{{ Storage::url($service->product->image) }}" alt="{{ $service->product->name }}"
                            class="rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'w-full object-cover object-center' }}">
                        @endif
                        <h2 class="text-xl font-bold">{{ $service->product->name }}</h2>
                        @if(theme('small_images', false))
                    </div>
                    @endif
                    <article class="prose dark:prose-invert">
                        {!! $service->product->description !!}
                    </article>
                    <h3 class="text-lg font-semibold mb-2">
                        @if($service->plan->type == 'recurring')
                        {{ __('services.price_every_period', [
                            'price' => $service->plan->price($service->currency_code),
                            'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                            'unit' => strtolower(trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit), $service->plan->billing_period))
                        ]) }}
                        @else
                        {{ __('services.price_one_time', [
                            'price' => $service->product->price(null, null, null, $service->currency_code),
                        ]) }}
                        @endif

                    </h3>
                </label>
            </div>
            @foreach ($this->selectableProductUpgrades() as $product)
            @php
                $upgradePlan = $this->planForUpgradeProduct($product);
            @endphp
            <div>
                <input type="radio" name="upgrade" value="{{ $product->id }}" wire:model.live="upgrade"
                    class="hidden peer" id="product-{{ $product->id }}">
                <label for="product-{{ $product->id }}"
                    class="flex flex-col cursor-pointer bg-background-secondary hover:bg-background-secondary/80 border border-neutral peer-checked:border-secondary p-4 rounded-lg">
                    @if($upgrade == $product->id)
                    <div
                        class="rounded-full border border-background rounded-selector inline-flex items-center justify-center gap-2 align-middle bg-primary w-fit px-2 py-0.5">
                        <p class="">{{ __('services.new_plan') }}</p>
                    </div>
                    @endif
                    @if(theme('small_images', false))
                    <div class="flex gap-x-3 items-center">
                        @endif
                        @if ($product->image)
                        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
                            class="rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'w-full object-cover object-center' }}">
                        @endif
                        <h2 class="text-xl font-bold">{{ $product->name }}</h2>
                        @if(theme('small_images', false))
                    </div>
                    @endif
                    <article class="prose dark:prose-invert">
                        {!! $product->description !!}
                    </article>
                    <h3 class="text-lg font-semibold mb-2">
                        @if($service->plan->type == 'recurring')
                        {{ __('services.price_every_period', [
                            'price' => $upgradePlan?->price($service->currency_code),
                            'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                            'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit), $service->plan->billing_period)
                        ]) }}
                        @else
                        {{ __('services.price_one_time', [
                            'price' => $upgradePlan?->price($service->currency_code),
                        ]) }}
                        @endif
                    </h3>
                </label>
            </div>
            @endforeach
            @else
            <div class="col-span-2 flex flex-col gap-4">
                @foreach ($this->upgradeConfigOptions() as $configOption)
                @php
                    $targetBillingPlan = (int) $upgradeProduct->id === (int) $service->product_id
                        ? $service->plan
                        : $this->planForUpgradeProduct($upgradeProduct);
                    $availableChildren = $this->availableUpgradeValues($configOption);
                    $showPriceTag = $availableChildren->filter(
                        fn ($value) => !$value->price(
                            billing_period: $targetBillingPlan->billing_period,
                            billing_unit: $targetBillingPlan->billing_unit,
                            currency: $service->currency_code
                        )->is_free
                    )->count() > 0;
                @endphp
                <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$targetBillingPlan">
                    {{-- If the config option is a select, show the options --}}
                    @if ($configOption->type == 'select')
                        @foreach ($availableChildren as $configOptionValue)
                            <option value="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}
                                {{ ($showPriceTag && $configOptionValue->price(billing_period: $targetBillingPlan->billing_period, billing_unit: $targetBillingPlan->billing_unit, currency: $service->currency_code)->available) ? ' - ' . $configOptionValue->price(billing_period: $targetBillingPlan->billing_period, billing_unit: $targetBillingPlan->billing_unit, currency: $service->currency_code) : '' }}
                            </option>
                        @endforeach
                    @elseif($configOption->type == 'radio')
                        @foreach ($availableChildren as $configOptionValue)
                            <div class="flex items-center gap-2">
                                <input type="radio" id="{{ $configOptionValue->id }}" name="{{ $configOption->id }}"
                                    wire:model.live="configOptions.{{ $configOption->id }}"
                                    value="{{ $configOptionValue->id }}" />
                                <label for="{{ $configOptionValue->id }}">
                                    {{ $configOptionValue->name }}
                                    {{ ($showPriceTag && $configOptionValue->price(billing_period: $targetBillingPlan->billing_period, billing_unit: $targetBillingPlan->billing_unit, currency: $service->currency_code)->available) ? ' - ' . $configOptionValue->price(billing_period: $targetBillingPlan->billing_period, billing_unit: $targetBillingPlan->billing_unit, currency: $service->currency_code) : '' }}
                                </label>
                            </div>
                        @endforeach
                    @endif
                </x-form.configoption>
                @endforeach
                @if ($this->hasDynamicSliderOptions())
                    <div
                        x-show="quoteState === 'loading'"
                        id="dynamic-upgrade-stock-status"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        class="text-sm text-primary-500"
                    >
                        Checking the current server node’s upgrade capacity…
                    </div>
                    <div
                        x-show="quoteState === 'error'"
                        role="alert"
                        aria-atomic="true"
                        class="flex flex-col items-start gap-2 rounded-md border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-500"
                    >
                        <span x-text="quoteError"></span>
                        <button
                            type="button"
                            x-bind:disabled="!canRetry"
                            x-bind:aria-disabled="(!canRetry).toString()"
                            x-on:click="
                                retryQuote();
                                $nextTick(() => $root.querySelector('.dynamic-slider-input')?.focus());
                            "
                            aria-controls="dynamic-upgrade-stock-status"
                            class="rounded underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
                        >
                            <span x-show="canRetry">
                                Retry upgrade availability check
                            </span>
                            <span
                                x-show="!canRetry"
                                x-text="`Retry available in ${retryWaitSeconds} seconds`"
                            ></span>
                        </button>
                    </div>
                @endif
            </div>
            @endif
        </div>
        <div class="flex flex-col gap-2 w-full col-span-1 bg-background-secondary p-3 rounded-md h-fit">
            <h4 class="text-lg font-semibold">{{ __('services.upgrade_summary') }}:</h4>

            <div class="flex items-center text-base">
                <span class="mr-2">{{ __('services.current_plan') }}:</span>
                <span class="text-base/50">{{ $service->product->name }}</span>
            </div>
            @if($upgrade != $service->product->id)
            <div class="flex items-center text-base">
                <span class="mr-2">{{ __('services.new_plan') }}:</span>
                <span class="text-base/50">{{ $upgradeProduct ? $upgradeProduct->name : __('general.select_plan') }}</span>
            </div>
            @endif

            {{--  Total today --}}
            <div class="flex items-center text-base">
                <span class="mr-2">{{ __('services.total_today') }}:</span>
                <span class="text-base/50">{{ $this->totalToday() }}</span>
            </div>

            <div class="flex flex-row justify-end gap-2 mt-2">
                @if ($dynamicUpgradeGate)
                    <x-button.primary
                        class="h-fit"
                        :wire:click="$upgradeButtonAction"
                        x-bind:disabled="!canCheckout"
                        x-bind:aria-disabled="(!canCheckout).toString()"
                    >
                        <span>{{ $upgradeButtonLabel }}</span>
                    </x-button.primary>
                @else
                    <x-button.primary
                        class="h-fit"
                        :wire:click="$upgradeButtonAction"
                    >
                        <span>{{ $upgradeButtonLabel }}</span>
                    </x-button.primary>
                @endif
            </div>
        </div>
    </div>
</div>
