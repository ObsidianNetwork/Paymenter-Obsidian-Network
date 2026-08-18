<?php

namespace App\Services\ServiceUpgrade;

use App\Classes\Price;
use App\Classes\Settings;
use App\Exceptions\DisplayException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Support\StrictDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolve the immutable monetary evidence used by an upgrade quote.
 *
 * Catalog prices describe the target being purchased. They are not evidence
 * of what a customer prepaid for the source service and therefore must never
 * be used as the basis of a downgrade credit.
 */
class ServiceUpgradePricingService
{
    /**
     * Resolve the effective recurring rate for the currently prepaid period.
     *
     * Checkout or renewal starts the durable period ledger. Each completed
     * upgrade advances current_period_price, while period_base_price retains
     * the recurring-only amount actually paid at the period boundary.
     *
     * @return array<string, mixed>
     */
    public function currentRecurringBasis(Service $service): array
    {
        $base = $this->currentPrepaidBasis($service);
        $amount = StrictDecimal::parseNonNegative(
            $service->current_period_price
        );
        if ($amount === null) {
            throw new \RuntimeException(
                'The service has no valid immutable current-period recurring basis.'
            );
        }

        return [
            'amount' => number_format($amount, 2, '.', ''),
            'source' => [
                'type' => 'service_period_ledger',
                'started_at' => $base['applied_at']?->toIso8601String(),
            ],
            'base' => $base,
        ];
    }

    /**
     * Resolve the unused actually-paid value still available for a refund.
     *
     * @return array<string, mixed>
     */
    public function remainingRefundableValue(Service $service): array
    {
        $base = $this->currentPrepaidBasis($service);
        $period = $this->periodProration($service);
        $expiresAt = $period['expires_at'];
        $remainingDays = $period['remaining_days'];
        // A perpetual/free/one-time service has no unused time interval to
        // refund. Preserve full-difference charging for upgrades, but never
        // mint downgrade credit without a finite paid coverage end.
        $baseFactor = $expiresAt === null
            ? 0
            : $period['factor'];
        $amount = (float) $base['amount'] * $baseFactor;
        $adjustments = [];

        foreach ($this->completedUpgradesSince(
            $service,
            $base['applied_at']
        ) as $upgrade) {
            $this->authenticTargetRecurring($upgrade);
            $appliedAt = $upgrade->completed_at;
            if ($appliedAt === null) {
                throw new \RuntimeException(
                    'A completed upgrade has no application timestamp.'
                );
            }
            $applicationRemainingDays = $expiresAt === null
                ? null
                : max(
                    0,
                    (int) $appliedAt
                        ->copy()
                        ->startOfDay()
                        ->diffInDays($expiresAt, false)
                );
            $factor = $expiresAt === null
                ? 0
                : (
                    $applicationRemainingDays > 0
                        ? min(
                            1,
                            $remainingDays / $applicationRemainingDays
                        )
                        : 0
                );
            $positiveCharge = max(
                0,
                (float) ($upgrade->quoted_amount ?? 0)
            );
            if ($positiveCharge > 0) {
                $this->assertPaidUpgradeCharge(
                    $upgrade,
                    $positiveCharge
                );
            }
            $appliedCredit = $upgrade->credit_applied_at === null
                ? 0
                : max(0, (float) $upgrade->credit_amount);
            $net = $positiveCharge - $appliedCredit;
            $amount += $net * $factor;
            $adjustments[] = [
                'upgrade_id' => (int) $upgrade->id,
                'completed_at' => $appliedAt->toIso8601String(),
                'positive_charge' => number_format(
                    $positiveCharge,
                    2,
                    '.',
                    ''
                ),
                'applied_credit' => number_format(
                    $appliedCredit,
                    2,
                    '.',
                    ''
                ),
                'remaining_factor' => number_format(
                    $factor,
                    8,
                    '.',
                    ''
                ),
            ];
        }

        return [
            'amount' => number_format(max(0, $amount), 2, '.', ''),
            'base' => $base,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * Use the service's established coverage dates rather than approximating
     * calendar months and years as 30/365 days.
     *
     * @return array{
     *     starts_at: CarbonInterface,
     *     expires_at: CarbonInterface|null,
     *     total_days: int|null,
     *     remaining_days: int|null,
     *     factor: float
     * }
     */
    public function periodProration(Service $service): array
    {
        $startsAt = $service->pricing_ledger_started_at;
        if (
            $startsAt === null
            || $service->pricing_ledger_verified_at === null
        ) {
            throw new DisplayException(
                'This service does not yet have a verified pricing-period start.'
            );
        }
        $startsAt = $startsAt->copy()->startOfDay();
        $expiresAt = $service->expires_at?->copy()->startOfDay();
        if ($expiresAt === null) {
            return [
                'starts_at' => $startsAt,
                'expires_at' => null,
                'total_days' => null,
                'remaining_days' => null,
                'factor' => 1,
            ];
        }

        $totalDays = (int) $startsAt->diffInDays(
            $expiresAt,
            false
        );
        if ($totalDays <= 0) {
            throw new DisplayException(
                'This service has an invalid recurring pricing-period interval.'
            );
        }
        $remainingDays = min(
            $totalDays,
            max(
                0,
                (int) now()
                    ->startOfDay()
                    ->diffInDays($expiresAt, false)
            )
        );

        return [
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'total_days' => $totalDays,
            'remaining_days' => $remainingDays,
            'factor' => $remainingDays / $totalDays,
        ];
    }

    /**
     * @return array{
     *     amount: string,
     *     source: 'service_period_ledger',
     *     invoice_id: int|null,
     *     invoice_item_id: int|null,
     *     applied_at: CarbonInterface|null
     * }
     */
    public function currentPrepaidBasis(Service $service): array
    {
        $amount = StrictDecimal::parseNonNegative(
            $service->period_base_price
        );
        if (
            $amount === null
            || $service->pricing_ledger_started_at === null
            || $service->pricing_ledger_verified_at === null
            || (int) $service->billing_cycles_completed <= 0
        ) {
            throw new DisplayException(
                'This service does not yet have verified recurring-period payment evidence. Complete a clean renewal before upgrading it.'
            );
        }
        if ($service->pricing_ledger_started_at->isFuture()) {
            throw new DisplayException(
                'This service was renewed early. Upgrades become available when its newly paid billing period begins on '
                . $service->pricing_ledger_started_at->toDateString()
                . '.'
            );
        }
        if (
            $service->plan->type === 'recurring'
            && (
                $service->expires_at === null
                || $service->expires_at
                    ->copy()
                    ->startOfDay()
                    ->lessThanOrEqualTo(now()->startOfDay())
            )
        ) {
            throw new DisplayException(
                'An expired recurring service must be renewed before it can be upgraded.'
            );
        }

        $invoiceQuery = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('items', function ($query) use ($service): void {
                $query
                    ->where('reference_type', Service::class)
                    ->where('reference_id', $service->id);
            })
            ->orderByDesc('id');
        if (DB::transactionLevel() > 0) {
            $invoiceQuery->lockForUpdate();
        }
        $invoice = $invoiceQuery->first();

        if ($invoice === null) {
            return [
                'amount' => number_format($amount, 2, '.', ''),
                'source' => 'service_period_ledger',
                'invoice_id' => null,
                'invoice_item_id' => null,
                'applied_at' => $service->pricing_ledger_started_at,
            ];
        }

        $lineQuery = $invoice->items()
            ->where('reference_type', Service::class)
            ->where('reference_id', $service->id)
            ->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $lineQuery->lockForUpdate();
        }
        $lines = $lineQuery->get();
        $line = $lines->first();
        $quantity = (int) ($line?->quantity ?? 0);
        $lineAmount = $line === null
            ? null
            : round((float) $line->price * $quantity, 2);

        if (
            $lines->count() !== 1
            || (int) $invoice->user_id !== (int) $service->user_id
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $service->currency_code)
            || $quantity !== (int) $service->quantity
            || $lineAmount === null
            || $lineAmount < 0
        ) {
            throw new \RuntimeException(
                'The latest paid service invoice does not match the immutable current-period billing obligation.'
            );
        }

        return [
            'amount' => number_format($amount, 2, '.', ''),
            'source' => 'service_period_ledger',
            'invoice_id' => (int) $invoice->id,
            'invoice_item_id' => (int) $line->id,
            'applied_at' => $service->pricing_ledger_started_at,
        ];
    }

    /**
     * Convert a tax-exclusive catalog amount to the exact customer obligation.
     *
     * @return array{
     *     amount: string,
     *     tax: array{
     *         enabled: bool,
     *         type: string,
     *         rate: string|null,
     *         tax_rate_id: int|null
     *     }
     * }
     */
    public function customerRecurringAmount(
        Service $service,
        float $catalogAmount
    ): array {
        if (!is_finite($catalogAmount) || $catalogAmount < 0) {
            throw new \RuntimeException(
                'The target recurring amount must be a non-negative finite number.'
            );
        }

        $tax = Settings::tax($service->user);
        $price = new Price([
            'price' => $catalogAmount,
            'currency' => $service->currency,
        ], apply_exclusive_tax: true, tax: $tax);

        return [
            'amount' => number_format((float) $price->price, 2, '.', ''),
            'tax' => [
                'enabled' => (bool) config('settings.tax_enabled', false),
                'type' => (string) config(
                    'settings.tax_type',
                    'inclusive'
                ),
                'rate' => $tax
                    ? number_format((float) $tax->rate, 2, '.', '')
                    : null,
                'tax_rate_id' => $tax ? (int) $tax->id : null,
            ],
        ];
    }

    /**
     * Lock and refresh the coupon whose disposition is signed into the quote.
     *
     * An empty coupon-products pivot means the coupon is globally applicable.
     * A restricted coupon follows the same product eligibility semantics as
     * checkout and is carried only when the target product is selected.
     */
    public function targetCoupon(
        Service $service,
        Product $targetProduct,
        bool $lock = false
    ): ?Coupon {
        $context = $this->couponContext(
            $service,
            $targetProduct,
            $lock
        );

        return $context['eligible'] ? $context['coupon'] : null;
    }

    /**
     * @return array{
     *     coupon_id: int|null,
     *     eligible_product_ids: list<int>,
     *     target_eligible: bool
     * }
     */
    public function couponEligibilityEvidence(
        Service $service,
        Product $targetProduct,
        bool $lock = false
    ): array {
        $context = $this->couponContext(
            $service,
            $targetProduct,
            $lock
        );

        return [
            'coupon_id' => $context['coupon']?->id,
            'eligible_product_ids' => $context['eligible_product_ids'],
            'target_eligible' => $context['eligible'],
        ];
    }

    /**
     * @return array{
     *     coupon: Coupon|null,
     *     eligible_product_ids: list<int>,
     *     eligible: bool
     * }
     */
    private function couponContext(
        Service $service,
        Product $targetProduct,
        bool $lock
    ): array {
        if ($service->coupon_id === null) {
            $service->setRelation('coupon', null);

            return [
                'coupon' => null,
                'eligible_product_ids' => [],
                'eligible' => false,
            ];
        }
        if ($lock && DB::transactionLevel() === 0) {
            throw new \LogicException(
                'An upgrade coupon can only be locked inside its quote transaction.'
            );
        }

        $couponQuery = Coupon::query()->whereKey($service->coupon_id);
        if ($lock) {
            $couponQuery->lockForUpdate();
        }
        $coupon = $couponQuery->first();
        if ($coupon === null) {
            throw new \RuntimeException(
                'The service coupon disappeared while its upgrade was being quoted.'
            );
        }

        $pivotQuery = DB::table('coupon_products')
            ->where('coupon_id', $coupon->id)
            ->orderBy('id');
        if ($lock) {
            $pivotQuery->lockForUpdate();
        }
        $eligibleProductIds = $pivotQuery
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();

        // Replace a possibly stale relation with the exact locked coupon row.
        $service->setRelation('coupon', $coupon);

        return [
            'coupon' => $coupon,
            'eligible_product_ids' => $eligibleProductIds->all(),
            'eligible' => $eligibleProductIds->isEmpty()
                || $eligibleProductIds->contains(
                    (int) $targetProduct->id
                ),
        ];
    }

    public function couponAppliesToNextCharge(
        Coupon $coupon,
        Service $service
    ): bool {
        $recurring = $coupon->recurring;

        // null is the first-cycle-only sentinel. Active services have already
        // consumed that first charge, so it must not behave like lifetime (0).
        if ($recurring === null) {
            return false;
        }
        if ((int) $recurring === 0) {
            return true;
        }

        $currentCycle = (int) $service->billing_cycles_completed;

        return $currentCycle > 0
            && $currentCycle <= (int) $recurring;
    }

    /**
     * @return Collection<int, ServiceUpgrade>
     */
    private function completedUpgradesSince(
        Service $service,
        ?CarbonInterface $appliedAt
    ) {
        $query = ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->where('status', ServiceUpgrade::STATUS_COMPLETED)
            ->when(
                $appliedAt !== null,
                fn ($query) => $query->where(
                    'completed_at',
                    '>=',
                    $appliedAt
                )
            )
            ->orderBy('completed_at')
            ->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function authenticTargetRecurring(
        ServiceUpgrade $upgrade
    ): float {
        if (
            !$upgrade->snapshotFingerprintsAreAuthentic()
            || (int) data_get(
                $upgrade->target_snapshot,
                'service_id'
            ) !== (int) $upgrade->service_id
            || strtoupper((string) data_get(
                $upgrade->target_snapshot,
                'currency_code'
            )) !== strtoupper((string) $upgrade->currency_code)
        ) {
            throw new \RuntimeException(
                'A completed upgrade has invalid signed pricing evidence.'
            );
        }

        $amount = StrictDecimal::parseNonNegative(
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
        if ($amount === null) {
            throw new \RuntimeException(
                'A completed upgrade has no valid target recurring price.'
            );
        }

        return $amount;
    }

    private function assertPaidUpgradeCharge(
        ServiceUpgrade $upgrade,
        float $amount
    ): void {
        $invoice = $upgrade->invoice;
        if (
            $invoice === null
            || $invoice->status !== Invoice::STATUS_PAID
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $upgrade->currency_code)
        ) {
            throw new \RuntimeException(
                'A completed paid upgrade has no matching paid invoice.'
            );
        }
        $lines = $invoice->items()
            ->where('reference_type', ServiceUpgrade::class)
            ->where('reference_id', $upgrade->id)
            ->orderBy('id')
            ->get();
        $line = $lines->first();
        if (
            $lines->count() !== 1
            || (int) $line->quantity !== 1
            || (int) round((float) $line->price * 100)
                !== (int) round($amount * 100)
        ) {
            throw new \RuntimeException(
                'A completed upgrade charge does not match its immutable invoice line.'
            );
        }
    }
}
