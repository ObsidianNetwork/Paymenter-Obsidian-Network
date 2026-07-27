@php
    $usesDynamicStock = $this->hasDynamicSliderOptions();
    $dynamicStockOptionIds = $usesDynamicStock
        ? $product->configOptions
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
        'enabled' => $usesDynamicStock,
        'endpoint' => $usesDynamicStock
            ? url('/api/dynamic-pterodactyl/products/' . $product->id . '/resource-quote')
            : null,
        'cartItemId' => $cartProductKey,
        'expectedBoundIds' => $dynamicStockOptionIds,
    ];
    $hasDynamicSliderPricing = $product->configOptions
        ->contains(fn ($option) => $option->isDynamicSlider());
    $sharedDynamicSliderBasePrice = $hasDynamicSliderPricing
        ? $plan->dynamicSliderBasePrice()
        : 0.0;
@endphp
<div
    class="container mt-14 flex flex-col md:grid md:grid-cols-4 gap-6"
    x-data="dynamicResourceStock(@js($dynamicStockConfig))"
    x-on:slider-change="queueQuote()"
    x-on:change="queueQuote()"
    x-on:dynamic-stock-refresh-required.window="retryQuote()"
>
    <div class="flex flex-col gap-4 w-full col-span-3">
        <h1 class="text-3xl font-bold">{{ $product->name }}</h1>
        <div class="flex flex-row w-full gap-4">
            @if ($product->image)
                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="max-w-40">
            @endif
            <div class="max-h-28 overflow-y-auto w-full">
                <article class="prose dark:prose-invert prose-sm">
                    {!! $product->description !!}
                </article>
            </div>
        </div>
        @if ($product->availablePlans()->count() > 1)
            <x-form.select wire:model.live="plan_id" name="plan_id" label="Select a plan">
                @foreach ($product->availablePlans() as $availablePlan)
                    <option value="{{ $availablePlan->id }}">
                        {{ $availablePlan->name }} -
                        {{ $availablePlan->price()->formatted->price }}
                        @if ($availablePlan->price()->has_setup_fee)
                            + {{ $availablePlan->price()->formatted->setup_fee }} {{ __('product.setup_fee') }}
                        @endif
                    </option>
                @endforeach
            </x-form.select>
        @endif

        @foreach ($product->configOptions as $configOption)
            @php
                $showPriceTag = $configOption->availableChildren->filter(fn ($value) => !$value->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->is_free)->count() > 0;
            @endphp
            <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$plan">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->availableChildren as $configOptionValue)
                        <option value="{{ $configOptionValue->id }}">
                            {{ $configOptionValue->name }}
                            {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->availableChildren as $configOptionValue)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue->id }}" name="{{ $configOption->id }}"
                                wire:model.live="configOptions.{{ $configOption->id }}"
                                value="{{ $configOptionValue->id }}" />
                            <label for="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}
                                {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach
        @foreach ($this->getCheckoutConfig() as $configOption)
            @php $configOption = (object) $configOption; @endphp
            <x-form.configoption :config="$configOption" :name="'checkoutConfig.' . $configOption->name">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <option value="{{ $configOptionValue }}">
                            {{ $configOptionValueName }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue }}" name="{{ $configOption->name }}"
                                wire:model.live="checkoutConfig.{{ $configOption->name }}"
                                value="{{ $configOptionValue }}" />
                            <label for="{{ $configOptionValue }}">
                                {{ $configOptionValueName }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach
        @if ($this->hasDynamicSliderOptions())
            <div
                x-show="quoteState === 'loading'"
                id="dynamic-resource-stock-status"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                class="text-sm text-primary-500"
            >
                Checking live resource availability…
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
                    x-ref="retryResourceQuote"
                    x-bind:disabled="!canRetry"
                    x-bind:aria-disabled="(!canRetry).toString()"
                    x-on:click="
                        retryQuote();
                        $nextTick(() => $root.querySelector('.dynamic-slider-input')?.focus());
                    "
                    aria-controls="dynamic-resource-stock-status"
                    class="rounded underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
                >
                    <span x-show="canRetry">Retry availability check</span>
                    <span
                        x-show="!canRetry"
                        x-text="`Retry available in ${retryWaitSeconds} seconds`"
                    ></span>
                </button>
            </div>
        @endif
    </div>
    <div class="flex flex-col gap-2 w-full col-span-1 bg-background-secondary p-3 rounded-md h-fit">
        <h2 class="text-2xl font-semibold  mb-2">
            {{ __('product.order_summary') }}
        </h2>
        @if ($sharedDynamicSliderBasePrice > 0)
            <div class="font-semibold flex justify-between">
                <h4>Dynamic resource base:</h4>
                {{ $total->format($sharedDynamicSliderBasePrice) }}
            </div>
        @endif
        @if ($total->total_tax > 0)
            <div class="font-semibold flex justify-between">
                <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
            </div>
            <div class="font-semibold flex justify-between">
                <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->formatted->total_tax }}
            </div>
        @endif
        <div class="text-lg font-semibold flex justify-between">
            <h4>{{ __('product.total_today') }}:</h4> {{ $total }}
        </div>
        @if ($total->setup_fee > 0 && $plan->type == 'recurring')
            <div class="text- font-semibold flex justify-between ">
                <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                </h4> {{ $total->format($total->price) }}
            </div>
        @endif
        @if (($product->stock > 0 || !$product->stock) && $product->price()->available)
            <div>
                <x-button.primary
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                    x-bind:disabled="!canCheckout"
                    x-bind:aria-disabled="(!canCheckout).toString()"
                >
                    <x-loading target="checkout" />
                    <div wire:loading.remove wire:target="checkout">
                        {{ __('product.checkout') }}
                    </div>
                </x-button.primary>
            </div>
        @endif
    </div>
</div>
