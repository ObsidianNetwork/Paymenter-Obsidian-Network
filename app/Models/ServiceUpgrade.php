<?php

namespace App\Models;

use App\Classes\Price;
use App\Classes\Settings;
use App\Helpers\ExtensionHelper;
use App\Observers\ServiceUpgradeObserver;
use App\Support\StrictInteger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceUpgradeObserver::class])]
class ServiceUpgrade extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID_COMMITTED = 'paid_committed';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $guarded = [];

    protected $casts = [
        'source_snapshot' => 'array',
        'target_snapshot' => 'array',
        'quoted_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'failed_at' => 'datetime',
        'failure_alerted_at' => 'datetime',
        'completed_at' => 'datetime',
        'credit_applied_at' => 'datetime',
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
        $newPrice = $newItem->price(null, $plan->billing_period, $plan->billing_unit, $this->service->currency_code)->price;

        if (!$this->service->expires_at) {
            return $this->makePrice($newPrice);
        }

        $billingPeriodDays = $this->getBillingPeriodDays();
        $remainingDays = $this->getRemainingDays();
        $priceDifference = $newPrice - $this->resolveOldItemPrice($oldItem);
        $total = $billingPeriodDays > 0 ? ($priceDifference / $billingPeriodDays) * $remainingDays : $priceDifference;

        return $this->makePrice($total);
    }

    public function calculatePrice(): Price
    {
        $oldRecurring = $this->recurringAmount(
            $this->service->plan,
            $this->service->configs
        );
        $targetConfigs = (int) $this->product_id === (int) $this->service->product_id
            ? $this->configs
                ->keyBy('config_option_id')
                ->union($this->service->configs->keyBy('config_option_id'))
                ->values()
            : $this->configs;
        $newRecurring = $this->recurringAmount($this->plan, $targetConfigs);
        $total = $this->calculateProratedDifference(
            $oldRecurring,
            $newRecurring
        )->price;

        // Cap refunds to what was actually paid when coupon exists
        if ($total < 0 && $this->service->coupon_id) {
            $total = max($total, -$this->getMaxRefundAmount());
        }

        return $this->makePrice($total);
    }

    private function recurringAmount(Plan $plan, $configs): float
    {
        $amount = (float) $plan->price($this->service->currency_code)->price;
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
                $amount += (float) $config->configValue->price(
                    null,
                    $plan->billing_period,
                    $plan->billing_unit,
                    $this->service->currency_code
                )->price;
            }
        }

        if ($hasDynamicValue) {
            $amount += $plan->dynamicSliderBasePrice();
        }

        $coupon = $this->service->coupon;
        if ($coupon !== null) {
            $renewalNumber = $this->service->invoices()
                ->where('status', Invoice::STATUS_PAID)
                ->count() + 1;
            if ($coupon->recurring == 0 || $renewalNumber <= $coupon->recurring) {
                $amount -= $coupon->calculateDiscount($amount);
            }
        }

        return max(0, $amount);
    }

    public function targetProperties(): array
    {
        $properties = array_merge(
            ExtensionHelper::settingsToArray($this->product->settings),
            ExtensionHelper::getServiceProperties($this->service)
        );

        foreach ($this->configs as $config) {
            $option = $config->configOption;
            if ($option === null) {
                continue;
            }

            $key = strtolower((string) ($option->env_variable ?: $option->name));
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

        return $properties;
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
            } elseif (! is_array($locationIds)) {
                $locationIds = [$locationIds];
            }
            if (! array_is_list($locationIds) || count($locationIds) !== 1) {
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

        $sourceProperties = collect(array_merge(
            ExtensionHelper::settingsToArray($this->service->product->settings),
            ExtensionHelper::getServiceProperties($this->service)
        ))
            ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value])
            ->sortKeys()
            ->all();
        $targetProperties = collect($this->targetProperties())
            ->mapWithKeys(fn ($value, $key) => [
                strtolower((string) $key) => $value,
            ])
            ->sortKeys()
            ->all();
        $targetConfigs = (int) $this->product_id
            === (int) $this->service->product_id
                ? $this->configs
                    ->keyBy('config_option_id')
                    ->union(
                        $this->service->configs->keyBy('config_option_id')
                    )
                    ->values()
                : $this->configs;
        $targetRecurring = new Price([
            'price' => $this->recurringAmount($this->plan, $targetConfigs),
            'currency' => $this->service->currency,
        ], apply_exclusive_tax: true, tax: Settings::tax(
            $this->service->user
        ));

        $source = [
            'service_id' => (int) $this->service_id,
            'product_id' => (int) $this->service->product_id,
            'plan_id' => (int) $this->service->plan_id,
            'quantity' => (int) $this->service->quantity,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => $sourceProperties,
            'billing_anchor' => $this->billingAnchor($this->service),
        ];
        $target = [
            'service_id' => (int) $this->service_id,
            'product_id' => (int) $this->product_id,
            'plan_id' => (int) $this->plan_id,
            'quantity' => 1,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => $targetProperties,
            'recurring_price' => number_format(
                (float) $targetRecurring->price,
                2,
                '.',
                ''
            ),
            'billing_anchor' => $this->billingAnchor($this->service),
        ];

        $this->source_snapshot = $source;
        $this->target_snapshot = $target;
        $this->source_fingerprint = $this->fingerprint($source);
        $this->target_fingerprint = $this->fingerprint($target);
    }

    public function sourceStillMatches(): bool
    {
        $current = [
            'service_id' => (int) $this->service_id,
            'product_id' => (int) $this->service->product_id,
            'plan_id' => (int) $this->service->plan_id,
            'quantity' => (int) $this->service->quantity,
            'currency_code' => strtoupper((string) $this->service->currency_code),
            'properties' => collect(array_merge(
                ExtensionHelper::settingsToArray($this->service->product->settings),
                ExtensionHelper::getServiceProperties($this->service)
            ))
                ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => $value])
                ->sortKeys()
                ->all(),
            'billing_anchor' => $this->billingAnchor($this->service),
        ];

        return $this->source_fingerprint !== null
            && hash_equals((string) $this->source_fingerprint, $this->fingerprint($current));
    }

    private function calculateProratedDifference(float $oldAmount, float $newAmount): Price
    {
        $difference = $newAmount - $oldAmount;

        if (! $this->service->expires_at) {
            return $this->makePrice($difference);
        }

        $billingPeriodDays = $this->getBillingPeriodDays();
        $remainingDays = $this->getRemainingDays();
        $total = $billingPeriodDays > 0
            ? ($difference / $billingPeriodDays) * $remainingDays
            : $difference;

        return $this->makePrice($total);
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

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
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

    protected function getBillingPeriodDays(): int
    {
        $plan = $this->service->plan;

        return match ($plan->billing_unit) {
            'day' => $plan->billing_period,
            'week' => $plan->billing_period * 7,
            'month' => $plan->billing_period * 30,
            'year' => $plan->billing_period * 365,
            default => 0,
        };
    }

    protected function getRemainingDays(): int
    {
        if (!$this->service->expires_at) {
            return 0;
        }
        $billingPeriodDays = $this->getBillingPeriodDays();

        return min($this->service->expires_at->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay(), true), $billingPeriodDays);
    }

    public function getMaxRefundAmount(): float
    {
        // We don't refund if service has no due date (one-time or free plans)
        if (!$this->service->expires_at) {
            return 0;
        }
        $billingPeriodDays = $this->getBillingPeriodDays();
        $remainingDays = $this->getRemainingDays();
        $paidAmount = (float) $this->service->calculatePrice();

        return $billingPeriodDays > 0 ? ($paidAmount / $billingPeriodDays) * $remainingDays : $paidAmount;
    }
}
