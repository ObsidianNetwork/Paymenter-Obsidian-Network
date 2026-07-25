<?php

namespace App\Livewire\Services;

use App\Events\Invoice\Created as InvoiceCreated;
use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Rules\DynamicSliderValueRule;
use App\Services\Service\CapacityConfigurationLockService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeGuaranteeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

class Upgrade extends Component
{
    public Service $service;

    public $upgrade;

    public Product $upgradeProduct;

    public int $step = 1;

    public $configOptions = [];

    public function mount(): mixed
    {
        $this->authorize('view', $this->service);
        $this->service->loadMissing([
            'product.upgrades',
            'product.upgradableConfigOptions.children',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);

        if (! $this->service->upgradable) {
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

        if (! $allowed->containsStrict($upgradeId)) {
            $this->upgrade = $this->service->product_id;
            $this->upgradeProduct = $this->service->product;
            $this->notify('Invalid upgrade.', 'error');

            return;
        }

        $this->upgradeProduct = Product::query()
            ->with(['upgradableConfigOptions.children', 'plans.prices'])
            ->findOrFail($upgradeId);
    }

    public function nextStep(): void
    {
        $currentConfigs = $this->service->configs
            ->keyBy('config_option_id');

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
                        ?? $option->availableChildren()->value('id'),
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
            } elseif (in_array($option->type, ['text', 'number'], true)) {
                $rules["configOptions.{$option->id}"] = ['required'];
            } elseif ($option->type !== 'checkbox') {
                $rules["configOptions.{$option->id}"] = [
                    'required',
                    Rule::in($option->availableChildren()->pluck('id')->all()),
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

            if ($dynamic && (int) $targetProduct->id !== (int) $service->product_id) {
                throw new DisplayException(
                    'Dynamic resource upgrades must stay on the current product.'
                );
            }
            if ($dynamic && (int) $service->quantity !== 1) {
                throw new DisplayException(
                    'Dynamic resource upgrades require a service quantity of one.'
                );
            }

            $targetPlan = $dynamic
                ? $service->plan
                : $targetProduct->availablePlans()
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
            ]);

            foreach ($targetConfigOptions as $option) {
                if (! array_key_exists($option->id, $this->configOptions)) {
                    continue;
                }

                if ($option->isDynamicSlider()) {
                    $value = $option->normalizeDynamicSliderValue(
                        $this->configOptions[$option->id]
                    );
                    $upgrade->configs()->create([
                        'config_option_id' => $option->id,
                        'config_value_id' => null,
                        'slider_value' => $value,
                    ]);

                    continue;
                }

                if (! $option->availableChildren()
                    ->whereKey($this->configOptions[$option->id])
                    ->exists()) {
                    throw new DisplayException("{$option->name} has an invalid selection.");
                }

                $upgrade->configs()->create([
                    'config_option_id' => $option->id,
                    'config_value_id' => $this->configOptions[$option->id],
                    'slider_value' => null,
                ]);
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

            if ($upgrade->source_snapshot === $upgrade->target_snapshot) {
                throw new DisplayException(
                    'You have not changed any product or resource configuration.'
                );
            }

            $price = $upgrade->calculatePrice();
            $upgrade->quoted_amount = round((float) $price->price, 2);
            $upgrade->credit_amount = config('settings.credits_on_downgrade', true)
                ? max(0, round(-(float) $price->price, 2))
                : 0;
            $upgrade->save();

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

                $upgrade->invoice_id = $invoice->id;
                $upgrade->save();

                $invoice->items()->create([
                    'description' => 'Upgrade '.$service->product->name,
                    'price' => $price->price,
                    'quantity' => 1,
                    'reference_id' => $upgrade->id,
                    'reference_type' => ServiceUpgrade::class,
                ]);
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

        return $this->service->productUpgrades();
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
        if (! $dynamic) {
            return $product->upgradableConfigOptions;
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
        if (! isset($this->upgradeProduct)) {
            return null;
        }
        if (
            $this->service->product->usesDynamicResources()
            || $this->upgradeProduct->usesDynamicResources()
        ) {
            return (int) $this->upgradeProduct->id
                === (int) $this->service->product_id
                    ? $this->service->plan
                    : null;
        }

        return $this->upgradeProduct->availablePlans()
            ->where('billing_period', $this->service->plan->billing_period)
            ->where('billing_unit', $this->service->plan->billing_unit)
            ->first();
    }

    private function temporaryConfigs()
    {
        return $this->upgradeConfigOptions()
            ->map(function ($option): ?ServiceConfig {
                if (! array_key_exists($option->id, $this->configOptions)) {
                    return null;
                }

                if ($option->isDynamicSlider()) {
                    try {
                        $value = $option->normalizeDynamicSliderValue(
                            $this->configOptions[$option->id]
                        );
                    } catch (\InvalidArgumentException) {
                        return null;
                    }

                    $config = new ServiceConfig([
                        'config_option_id' => $option->id,
                        'slider_value' => $value,
                    ]);
                } else {
                    $value = $this->configOptions[$option->id];
                    if (! $option->availableChildren()->whereKey($value)->exists()) {
                        return null;
                    }
                    $config = new ServiceConfig([
                        'config_option_id' => $option->id,
                        'config_value_id' => $value,
                    ]);
                }

                $config->setRelation('configOption', $option);
                if ($config->config_value_id !== null) {
                    $config->setRelation(
                        'configValue',
                        $option->children->firstWhere('id', $config->config_value_id)
                    );
                }

                return $config;
            })
            ->filter()
            ->values();
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
        if (! class_exists($class)) {
            throw new DisplayException(
                'Dynamic upgrade capacity reservations are unavailable. Contact support.'
            );
        }

        return app($class);
    }
}
