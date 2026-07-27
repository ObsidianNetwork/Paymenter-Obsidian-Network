<?php

namespace App\Livewire\Services;

use App\Classes\Price;
use App\Events\Invoice\Created as InvoiceCreated;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Rules\DynamicSliderValueRule;
use App\Services\Service\CapacityConfigurationLockService;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradePricingService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\StaticUpgradeStockService;
use App\Services\ServiceUpgrade\UpgradeGuaranteeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

class Upgrade extends Component
{
    private const SUPPORTED_CONFIG_TYPES = [
        'select',
        'radio',
        'dynamic_slider',
    ];

    public Service $service;

    public $upgrade;

    public Product $upgradeProduct;

    public int $step = 1;

    public $configOptions = [];

    public function mount(): mixed
    {
        $this->authorize('view', $this->service);
        $this->service->loadMissing([
            'product.upgrades.configOptions.children.plans.prices',
            'product.upgrades.plans.prices',
            'product.upgradableConfigOptions.children',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);

        if (!$this->service->upgradable) {
            $this->notify('This service is not upgradable.', 'error', true);

            return $this->redirect(route('services.show', $this->service), true);
        }

        $this->upgradeProduct = $this->service->product;
        $this->upgrade = $this->service->product_id;
        $this->totalToday();

        if ($this->selectableProductUpgrades()->isEmpty()) {
            $this->nextStep();
        }

        return null;
    }

    #[Computed]
    public function totalToday()
    {
        $plan = $this->upgradePlan();
        if ($plan === null) {
            return new Price([
                'price' => 0,
                'currency' => $this->service->currency,
            ]);
        }

        $upgrade = new ServiceUpgrade([
            'service_id' => $this->service->id,
            'product_id' => $this->upgradeProduct->id,
            'plan_id' => $plan->id,
        ]);
        $upgrade->setRelation('service', $this->service);
        $upgrade->setRelation('product', $this->upgradeProduct);
        $upgrade->setRelation('plan', $plan);
        $upgrade->setRelation('configs', $this->temporaryConfigs());

        return $upgrade->calculatePrice();
    }

    public function updatedUpgrade($upgrade): void
    {
        $upgradeId = (int) $upgrade;
        $allowed = $this->selectableProductUpgrades()->pluck('id')
            ->push($this->service->product_id)
            ->map(fn ($id) => (int) $id);

        if (!$allowed->containsStrict($upgradeId)) {
            $this->upgrade = $this->service->product_id;
            $this->upgradeProduct = $this->service->product;
            $this->notify('Invalid upgrade.', 'error');

            return;
        }

        $this->upgradeProduct = Product::query()
            ->with([
                'configOptions.children.plans.prices',
                'upgradableConfigOptions.children.plans.prices',
                'plans.prices',
            ])
            ->findOrFail($upgradeId);
        $this->configOptions = [];
    }

    public function nextStep(): void
    {
        $currentConfigs = $this->service->configs
            ->keyBy('config_option_id');

        $plan = $this->upgradePlan();
        if ($plan === null) {
            throw new DisplayException(
                'The selected upgrade has no plan in this service currency.'
            );
        }

        $this->configOptions = $this->upgradeConfigOptions()
            ->mapWithKeys(function ($option) use ($currentConfigs): array {
                $current = $currentConfigs->get($option->id);

                if ($option->isDynamicSlider()) {
                    return [
                        $option->id => $current?->slider_value !== null
                            ? (int) $current->slider_value
                            : (int) $option->getMetadata(
                                'default',
                                $option->getMetadata('min', 0)
                            ),
                    ];
                }

                return [
                    $option->id => $current?->config_value_id
                        ?? $this->configOptions[$option->id]
                        ?? $this->availableUpgradeValues($option)->value('id'),
                ];
            })
            ->toArray();

        $this->step = 2;
    }

    public function rules(): array
    {
        $rules = [
            'upgradeProduct.id' => [
                'required',
                function ($attribute, $value, $fail): void {
                    if (
                        $this->service->product->usesDynamicResources()
                        && (int) $value !== (int) $this->service->product_id
                    ) {
                        $fail('Dynamic resources can only be changed within the current product.');

                        return;
                    }

                    if ($this->upgradePlan() === null) {
                        $fail(__('Invalid upgrade.'));
                    }
                },
            ],
        ];

        foreach ($this->upgradeConfigOptions() as $option) {
            if ($option->isDynamicSlider()) {
                $rules["configOptions.{$option->id}"] = [
                    'required',
                    new DynamicSliderValueRule($option),
                ];
            } else {
                $rules["configOptions.{$option->id}"] = [
                    'required',
                    Rule::in(
                        $this->availableUpgradeValues($option)
                            ->pluck('id')
                            ->all()
                    ),
                ];
            }
        }

        return $rules;
    }

    public function doUpgrade(): mixed
    {
        $this->validate();

        $result = DB::transaction(function (): array {
            $service = Service::query()
                ->with([
                    'product.upgradableConfigOptions.children',
                    'plan.prices',
                    'configs.configOption',
                    'configs.configValue',
                ])
                ->lockForUpdate()
                ->findOrFail($this->service->id);

            if (
                $service->status !== Service::STATUS_ACTIVE
                || $service->upgrade()->whereIn('status', ServiceUpgrade::activeStatuses())->exists()
                || $service->invoices()
                    ->where('status', Invoice::STATUS_PENDING)
                    ->exists()
            ) {
                throw new DisplayException('This service is not currently upgradable.');
            }

            $lockedProducts = app(
                CapacityConfigurationLockService::class
            )->lockProducts([
                (int) $service->product_id,
                (int) $this->upgradeProduct->id,
            ]);
            $currentProduct = $lockedProducts->get(
                (int) $service->product_id
            );
            $targetProduct = $lockedProducts->get(
                (int) $this->upgradeProduct->id
            );
            if ($currentProduct === null || $targetProduct === null) {
                throw new DisplayException(
                    'The selected upgrade product is no longer available.'
                );
            }
            $currentPlan = $currentProduct->plans->firstWhere(
                'id',
                (int) $service->plan_id
            );
            if ($currentPlan === null) {
                throw new DisplayException(
                    'The service billing plan is no longer available.'
                );
            }
            $service->setRelation('product', $currentProduct);
            $service->setRelation('plan', $currentPlan);
            $service->load([
                'configs.configOption',
                'configs.configValue',
            ]);
            $dynamic = $service->product->usesDynamicResources()
                || $targetProduct->usesDynamicResources();
            $crossProduct = (int) $targetProduct->id
                !== (int) $service->product_id;
            if (
                $crossProduct
                && !DB::table('product_upgrades')
                    ->where('product_id', $service->product_id)
                    ->where('upgrade_id', $targetProduct->id)
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DisplayException(
                    'The selected product is not an authorized upgrade target.'
                );
            }

            if ($dynamic && $crossProduct) {
                throw new DisplayException(
                    'Dynamic resource upgrades must stay on the current product.'
                );
            }
            if ($dynamic && (int) $service->quantity !== 1) {
                throw new DisplayException(
                    'Dynamic resource upgrades require a service quantity of one.'
                );
            }
            if (!$dynamic && (int) $service->quantity !== 1) {
                throw new DisplayException(
                    'Product upgrades require a service quantity of one.'
                );
            }
            if (!$dynamic) {
                app(StaticUpgradeStockService::class)
                    ->assertProductChangeAuthorized(
                        $service,
                        $targetProduct
                    );
            }
            $targetCoupon = app(
                ServiceUpgradePricingService::class
            )->targetCoupon(
                $service,
                $targetProduct,
                lock: true
            );

            $targetPlan = !$crossProduct
                ? $service->plan
                : $targetProduct->availablePlans($service->currency_code)
                    ->where('type', $service->plan->type)
                    ->where('billing_period', $service->plan->billing_period)
                    ->where('billing_unit', $service->plan->billing_unit)
                    ->first();
            if ($targetPlan === null) {
                throw new DisplayException('The selected upgrade has no matching billing cycle.');
            }
            $targetConfigOptions = $this->filterUpgradeConfigOptions(
                $targetProduct,
                $dynamic
            );
            if (
                $crossProduct
                && $targetProduct->configOptions->contains(
                    fn (ConfigOption $option): bool => !$this->isSupportedConfigOption($option)
                )
            ) {
                throw new DisplayException(
                    'This product cannot be upgraded to automatically because it contains text, number, checkbox, or legacy configuration fields.'
                );
            }
            $allowedOptionIds = $targetConfigOptions
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);
            $unknownOptionIds = collect(array_keys($this->configOptions))
                ->map(fn ($id): int => (int) $id)
                ->diff($allowedOptionIds);
            if ($unknownOptionIds->isNotEmpty()) {
                throw new DisplayException(
                    $dynamic
                        ? 'Dynamic upgrades may change only RAM, CPU, and disk.'
                        : 'The upgrade contains an unknown configuration option.'
                );
            }

            $upgrade = ServiceUpgrade::create([
                'service_id' => $service->id,
                'product_id' => $targetProduct->id,
                'plan_id' => $targetPlan->id,
                'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                'active_service_guard_id' => $service->id,
                'currency_code' => strtoupper((string) $service->currency_code),
                'capacity_mode' => $dynamic
                    ? ServiceUpgrade::CAPACITY_MODE_DYNAMIC
                    : ServiceUpgrade::CAPACITY_MODE_STATIC,
            ]);
            $upgrade->resolveTargetCoupon($targetCoupon);

            $currentConfigs = $service->configs->keyBy('config_option_id');
            $optionsToPersist = $crossProduct
                ? $targetProduct->configOptions
                : $targetConfigOptions;
            foreach ($optionsToPersist as $option) {
                $copiedExistingValue = $crossProduct && !$option->upgradable;
                if ($copiedExistingValue) {
                    $current = $currentConfigs->get($option->id);
                    if ($current === null) {
                        throw new DisplayException(
                            "{$option->name} requires a target value, but it is not customer-upgradable and the current service has no transferable selection."
                        );
                    }
                    $value = $option->isDynamicSlider()
                        ? $current->slider_value
                        : $current->config_value_id;
                } else {
                    if (!array_key_exists($option->id, $this->configOptions)) {
                        throw new DisplayException(
                            "{$option->name} requires an explicit target selection."
                        );
                    }
                    $value = $this->configOptions[$option->id];
                }

                $normalized = $this->normalizedTargetConfig(
                    $option,
                    $value,
                    $targetPlan,
                    allowHiddenValue: $copiedExistingValue
                );
                $upgrade->configs()->create($normalized->getAttributes());
            }

            $upgrade->load([
                'service.product.server.settings',
                'service.plan.prices',
                'service.configs.configOption',
                'service.configs.configValue',
                'product',
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
            ]);
            $upgrade->captureSnapshots();

            if ($upgrade->targetSemanticallyMatchesSource()) {
                throw new DisplayException(
                    'You have not changed any product or resource configuration.'
                );
            }

            // The signed snapshot is the quote. Recalculating here could cross
            // a proration-day boundary and create an invoice that fails its
            // own immutable binding.
            $price = $upgrade->signedUpgradePrice();
            $upgrade->quoted_amount = round((float) $price->price, 2);
            $upgrade->credit_amount = $upgrade->signedCreditAmount();
            $upgrade->save();
            if (!$dynamic) {
                app(StaticUpgradeStockService::class)
                    ->reserve($upgrade, $service);
            }

            $dueAt = app(UpgradeGuaranteeService::class)
                ->deadline($service);
            $invoice = null;
            if ((float) $price->price > 0) {
                $invoice = Invoice::create([
                    'currency_code' => $service->currency_code,
                    'status' => Invoice::STATUS_PENDING,
                    'due_at' => $dueAt,
                    'user_id' => $service->user_id,
                ]);

                $invoice->items()->create([
                    'description' => 'Upgrade ' . $service->product->name,
                    'price' => $price->price,
                    'quantity' => 1,
                    'reference_id' => $upgrade->id,
                    'reference_type' => ServiceUpgrade::class,
                ]);

                // Assemble the line before binding the invoice back to the
                // upgrade. Once either durable reference exists, invoice-line
                // observers correctly make the obligation immutable.
                $upgrade->invoice_id = $invoice->id;
                ServiceUpgradeMutationCoordinator::save($upgrade);
            }

            if ($dynamic) {
                $this->reserveDynamicCapacity($upgrade, $dueAt);
            }

            if ((float) $price->price <= 0 && $dynamic) {
                $this->dynamicUpgradeReservation()->commitPaidUpgrade($upgrade, null);
            } elseif ((float) $price->price <= 0) {
                app(ServiceUpgradeService::class)->markPaidCommitted($upgrade);
            }

            return [
                'upgrade_id' => $upgrade->id,
                'invoice_id' => $invoice?->id,
                'dynamic' => $dynamic,
            ];
        }, 5);

        $upgrade = ServiceUpgrade::query()->findOrFail($result['upgrade_id']);
        if ($result['invoice_id'] !== null) {
            $invoice = Invoice::query()->findOrFail($result['invoice_id']);
            event(new InvoiceCreated($invoice));
            $this->notify(
                'The upgrade is reserved until its invoice due date. Complete payment before that deadline to apply it.',
                'success',
                true
            );

            return $this->redirect(route('invoices.show', $invoice));
        }

        $this->notify(
            'The upgrade has been committed and will be applied shortly.',
            'success',
            true
        );

        return $this->redirect(route('services.show', $this->service), true);
    }

    public function selectableProductUpgrades()
    {
        if ($this->service->product->usesDynamicResources()) {
            return collect();
        }

        return $this->service->productUpgrades()
            ->filter(function (Product $product): bool {
                $product->loadMissing([
                    'configOptions.children.plans.prices',
                    'plans.prices',
                ]);

                return $this->planForUpgradeProduct($product) !== null
                    && $this->crossProductConfigurationIsTransferable(
                        $product
                    );
            })
            ->values();
    }

    public function hasDynamicSliderOptions(): bool
    {
        return $this->upgradeProduct->usesDynamicResources()
            && $this->upgradeConfigOptions()
                ->contains(fn ($option): bool => $option->isDynamicSlider());
    }

    public function upgradeConfigOptions()
    {
        return $this->filterUpgradeConfigOptions(
            $this->upgradeProduct,
            $this->service->product->usesDynamicResources()
                || $this->upgradeProduct->usesDynamicResources()
        );
    }

    private function filterUpgradeConfigOptions(
        Product $product,
        bool $dynamic
    ) {
        if (!$dynamic) {
            return $product->upgradableConfigOptions
                ->filter(
                    fn (ConfigOption $option): bool => $this->isSupportedConfigOption($option)
                )
                ->values();
        }

        return $product->upgradableConfigOptions
            ->filter(fn ($option): bool => $option->isDynamicSlider()
                && in_array(
                    strtolower((string) $option->getMetadata(
                        'resource_type',
                        ''
                    )),
                    ['memory', 'cpu', 'disk'],
                    true
                ))
            ->values();
    }

    public function render()
    {
        return view('services.upgrade')->layoutData([
            'title' => 'Upgrade Service',
            'sidebar' => true,
        ]);
    }

    private function upgradePlan(): ?Plan
    {
        if (!isset($this->upgradeProduct)) {
            return null;
        }
        if (
            (int) $this->upgradeProduct->id
            === (int) $this->service->product_id
        ) {
            return $this->service->plan;
        }
        if (
            $this->service->product->usesDynamicResources()
            || $this->upgradeProduct->usesDynamicResources()
        ) {
            return null;
        }

        return $this->planForUpgradeProduct($this->upgradeProduct);
    }

    public function planForUpgradeProduct(Product $product): ?Plan
    {
        $product->loadMissing('plans.prices');

        return $product->availablePlans($this->service->currency_code)
            ->where('type', $this->service->plan->type)
            ->where('billing_period', $this->service->plan->billing_period)
            ->where('billing_unit', $this->service->plan->billing_unit)
            ->first();
    }

    private function temporaryConfigs()
    {
        $plan = $this->upgradePlan();
        if ($plan === null) {
            return collect();
        }

        $crossProduct = (int) $this->upgradeProduct->id
            !== (int) $this->service->product_id;
        $this->upgradeProduct->loadMissing(
            'configOptions.children.plans.prices'
        );
        $currentConfigs = $this->service->configs
            ->keyBy('config_option_id');
        $options = $crossProduct
            ? $this->upgradeProduct->configOptions
            : $this->upgradeConfigOptions();

        return $options->map(function (ConfigOption $option) use (
            $crossProduct,
            $currentConfigs,
            $plan
        ): ?ServiceConfig {
            $copiedExistingValue = $crossProduct && !$option->upgradable;
            $current = $currentConfigs->get($option->id);
            if ($copiedExistingValue && $current === null) {
                return null;
            }

            $value = $copiedExistingValue
                ? ($option->isDynamicSlider()
                    ? $current->slider_value
                    : $current->config_value_id)
                : ($this->configOptions[$option->id]
                    ?? $this->defaultOptionSelection($option, $current));
            if ($value === null) {
                return null;
            }

            try {
                return $this->normalizedTargetConfig(
                    $option,
                    $value,
                    $plan,
                    allowHiddenValue: $copiedExistingValue
                );
            } catch (\Throwable) {
                return null;
            }
        })->filter()->values();
    }

    public function availableUpgradeValues(ConfigOption $option)
    {
        $plan = $this->upgradePlan();
        if ($plan === null || !in_array($option->type, [
            'select',
            'radio',
        ], true)) {
            return collect();
        }

        $option->loadMissing('children.plans.prices');

        return $option->children
            ->where('hidden', false)
            ->filter(
                fn (ConfigOption $value): bool => $this->configValueAvailableForPlan($value, $plan)
            )
            ->values();
    }

    private function normalizedTargetConfig(
        ConfigOption $option,
        mixed $value,
        Plan $plan,
        bool $allowHiddenValue = false
    ): ServiceConfig {
        if (!$this->isSupportedConfigOption($option)) {
            throw new DisplayException(
                "{$option->name} uses a configuration type that automatic upgrades do not support."
            );
        }

        if ($option->isDynamicSlider()) {
            try {
                $normalized = $option->normalizeDynamicSliderValue($value);
            } catch (\InvalidArgumentException $exception) {
                throw new DisplayException(
                    "{$option->name} has an invalid target value."
                );
            }
            $config = new ServiceConfig([
                'config_option_id' => $option->id,
                'config_value_id' => null,
                'slider_value' => $normalized,
            ]);
            $config->setRelation('configOption', $option);

            return $config;
        }

        $option->loadMissing('children.plans.prices');
        $selected = $option->children->firstWhere('id', (int) $value);
        if (
            $selected === null
            || (!$allowHiddenValue && (bool) $selected->hidden)
            || !$this->configValueAvailableForPlan($selected, $plan)
        ) {
            throw new DisplayException(
                "{$option->name} has no valid price-backed selection in {$this->service->currency_code}."
            );
        }

        $config = new ServiceConfig([
            'config_option_id' => $option->id,
            'config_value_id' => $selected->id,
            'slider_value' => null,
        ]);
        $config->setRelation('configOption', $option);
        $config->setRelation('configValue', $selected);

        return $config;
    }

    private function configValueAvailableForPlan(
        ConfigOption $value,
        Plan $targetPlan
    ): bool {
        $value->loadMissing('plans.prices');

        return $value->plans->contains(function (Plan $plan) use (
            $targetPlan
        ): bool {
            if ($plan->type === 'free') {
                return true;
            }

            return (int) $plan->billing_period
                    === (int) $targetPlan->billing_period
                && (string) $plan->billing_unit
                    === (string) $targetPlan->billing_unit
                && $plan->prices->contains(
                    'currency_code',
                    $this->service->currency_code
                );
        });
    }

    private function crossProductConfigurationIsTransferable(
        Product $product
    ): bool {
        $plan = $this->planForUpgradeProduct($product);
        if ($plan === null) {
            return false;
        }

        $currentConfigs = $this->service->configs
            ->keyBy('config_option_id');
        foreach ($product->configOptions as $option) {
            if (!$this->isSupportedConfigOption($option)) {
                return false;
            }

            if ($option->upgradable) {
                if (
                    !$option->isDynamicSlider()
                    && $this->eligibleChildrenForPlan($option, $plan)
                        ->isEmpty()
                ) {
                    return false;
                }

                continue;
            }

            $current = $currentConfigs->get($option->id);
            if ($current === null) {
                return false;
            }
            try {
                $this->normalizedTargetConfig(
                    $option,
                    $option->isDynamicSlider()
                        ? $current->slider_value
                        : $current->config_value_id,
                    $plan,
                    allowHiddenValue: true
                );
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    private function eligibleChildrenForPlan(
        ConfigOption $option,
        Plan $plan
    ) {
        $option->loadMissing('children.plans.prices');

        return $option->children
            ->where('hidden', false)
            ->filter(
                fn (ConfigOption $value): bool => $this->configValueAvailableForPlan($value, $plan)
            )
            ->values();
    }

    private function defaultOptionSelection(
        ConfigOption $option,
        ?ServiceConfig $current
    ): mixed {
        if ($option->isDynamicSlider()) {
            return $current?->slider_value
                ?? $option->getMetadata(
                    'default',
                    $option->getMetadata('min')
                );
        }

        $available = $this->availableUpgradeValues($option);
        if (
            $current?->config_value_id !== null
            && $available->contains('id', $current->config_value_id)
        ) {
            return $current->config_value_id;
        }

        return $available->value('id');
    }

    private function isSupportedConfigOption(ConfigOption $option): bool
    {
        return in_array(
            (string) $option->type,
            self::SUPPORTED_CONFIG_TYPES,
            true
        );
    }

    private function reserveDynamicCapacity(
        ServiceUpgrade $upgrade,
        CarbonInterface $guaranteedUntil
    ): void {
        $this->dynamicUpgradeReservation()->reserveForUpgrade(
            $upgrade,
            $guaranteedUntil
        );
    }

    private function dynamicUpgradeReservation(): object
    {
        $class = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\UpgradeReservationService';
        if (!class_exists($class)) {
            throw new DisplayException(
                'Dynamic upgrade capacity reservations are unavailable. Contact support.'
            );
        }

        return app($class);
    }
}
