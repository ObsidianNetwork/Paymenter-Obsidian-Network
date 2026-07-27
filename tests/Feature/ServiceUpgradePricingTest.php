<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradePricingService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Tests\TestCase;

class ServiceUpgradePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_is_the_source_prepaid_basis_after_catalog_drift(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        Carbon::setTestNow('2026-07-01 00:00:00');
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $line = $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Current paid service period',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        // Today's source catalog is not evidence of what was prepaid.
        $source->plan->prices()->update(['price' => '100.00']);

        $basis = DB::transaction(
            fn (): array => app(
                ServiceUpgradePricingService::class
            )->currentPrepaidBasis(
                Service::query()
                    ->lockForUpdate()
                    ->findOrFail($service->id)
            )
        );

        $this->assertSame('10.00', $basis['amount']);
        $this->assertSame('service_period_ledger', $basis['source']);
        $this->assertSame($invoice->id, $basis['invoice_id']);
        $this->assertSame($line->id, $basis['invoice_item_id']);

        $upgrade = $this->upgradeModel($service, $target);
        $this->assertSame(
            10.0,
            (float) $upgrade->calculatePrice()->price
        );
        $upgrade->captureSnapshots();
        Carbon::setTestNow('2026-07-02 00:00:01');
        $this->assertTrue(
            $upgrade->sourceStillMatches(),
            'A signed quote must not become source-corrupt at midnight.'
        );
    }

    public function test_exclusive_tax_is_added_to_target_recurring_obligation(): void
    {
        config([
            'settings.tax_enabled' => true,
            'settings.tax_type' => 'exclusive',
        ]);
        TaxRate::create([
            'name' => 'GST',
            'country' => 'all',
            'rate' => 20,
        ]);
        Once::flush();
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '200.00']);
        Carbon::setTestNow('2026-07-01 00:00:00');
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '120.00',
            'expires_at' => now()->addDays(30),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Tax-inclusive prepaid period',
            'quantity' => 1,
            'price' => '120.00',
        ]);
        $service->load(['user.properties', 'currency']);

        $pricing = app(ServiceUpgradePricingService::class)
            ->customerRecurringAmount($service, 200);

        $this->assertSame('240.00', $pricing['amount']);
        $this->assertSame('exclusive', $pricing['tax']['type']);
        $this->assertSame('20.00', $pricing['tax']['rate']);

        $upgrade = $this->upgradeModel($service, $target);
        $upgrade->captureSnapshots();
        $this->assertSame(
            '120.00',
            data_get($upgrade->target_snapshot, 'upgrade_price')
        );
        $this->assertSame(
            '240.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
        $this->assertSame(
            120.0,
            (float) $upgrade->calculatePrice()->price
        );
    }

    public function test_fixed_coupon_is_applied_after_exclusive_tax_like_checkout(): void
    {
        config([
            'settings.tax_enabled' => true,
            'settings.tax_type' => 'exclusive',
        ]);
        TaxRate::create([
            'name' => 'GST',
            'country' => 'all',
            'rate' => 20,
        ]);
        Once::flush();
        $source = $this->createProduct();
        $source->plan->prices()->update(['price' => '50.00']);
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '100.00']);
        $coupon = Coupon::forceCreate([
            'type' => 'fixed',
            'applies_to' => 'all',
            'recurring' => 0,
            'code' => 'TEN-AFTER-TAX',
            'value' => 10,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            // Checkout charges 50 * 1.20 - 10.
            'price' => '50.00',
            'period_base_price' => '50.00',
            'current_period_price' => '50.00',
            'expires_at' => now()->addDays(30),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Taxed source with fixed coupon',
            'quantity' => 1,
            'price' => '50.00',
        ]);

        $upgrade = $this->upgradeModel($service, $target);
        $upgrade->captureSnapshots();

        $this->assertSame(
            '110.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
        $this->assertSame(
            '60.00',
            data_get($upgrade->target_snapshot, 'upgrade_price')
        );
        $this->assertSame(
            60.0,
            (float) $upgrade->calculatePrice()->price
        );
    }

    public function test_source_only_coupon_is_not_eligible_for_target_product(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 0,
            'code' => 'SOURCE-ONLY',
            'value' => 50,
        ]);
        $coupon->products()->attach($source->product);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
            'expires_at' => now()->addDays(30),
        ]);

        $eligible = DB::transaction(function () use (
            $service,
            $target
        ): ?Coupon {
            $lockedService = Service::query()
                ->lockForUpdate()
                ->findOrFail($service->id);

            return app(ServiceUpgradePricingService::class)
                ->targetCoupon(
                    $lockedService,
                    $target->product,
                    lock: true
                );
        });

        $this->assertNull($eligible);

        $upgrade = $this->upgradeModel($service, $target);
        $upgrade->resolveTargetCoupon(null);
        $upgrade->captureSnapshots();
        $this->assertNull(
            data_get($upgrade->target_snapshot, 'coupon_id')
        );
        $this->assertSame(
            '10.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
    }

    public function test_null_recurring_coupon_is_not_treated_as_lifetime(): void
    {
        $source = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => null,
            'code' => 'FIRST-CYCLE',
            'value' => 50,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Discounted first cycle',
            'quantity' => 1,
            'price' => '5.00',
        ]);

        $this->assertFalse(
            app(ServiceUpgradePricingService::class)
                ->couponAppliesToNextCharge($coupon, $service)
        );
        $this->assertSame(
            '10.00',
            (string) $service->fresh([
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
                'coupon',
                'currency',
                'user',
            ])->calculatePrice()
        );

        $serviceWithoutInvoice = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
        ]);
        $this->assertFalse(
            app(ServiceUpgradePricingService::class)
                ->couponAppliesToNextCharge(
                    $coupon,
                    $serviceWithoutInvoice
                ),
            'A free first cycle still consumes a one-time coupon.'
        );
    }

    public function test_finite_coupon_applies_during_its_final_paid_period(): void
    {
        Carbon::setTestNow('2026-07-16 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 2,
            'code' => 'TWO-PAID-PERIODS',
            'value' => 50,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
            'period_base_price' => '5.00',
            'current_period_price' => '5.00',
            'pricing_ledger_started_at' => now()->subDays(15),
            'billing_cycles_completed' => 2,
            'expires_at' => now()->addDays(15),
        ]);
        foreach (range(1, 2) as $cycle) {
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'currency_code' => 'USD',
                'status' => Invoice::STATUS_PAID,
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $service->id,
                'description' => "Discounted period {$cycle}",
                'quantity' => 1,
                'price' => '5.00',
            ]);
        }

        $upgrade = $this->upgradeModel($service, $target);
        $this->assertSame(
            2.5,
            (float) $upgrade->calculatePrice()->price
        );
        $upgrade->captureSnapshots();
        $this->assertSame(
            '10.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
    }

    public function test_completed_upgrade_advances_basis_and_refund_ledger(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        $source = $this->createProduct();
        $firstTarget = $this->createProduct();
        $firstTarget->plan->prices()->update(['price' => '100.00']);
        $secondTarget = $this->createProduct();
        $secondTarget->plan->prices()->update(['price' => '50.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => now()->addDays(30),
        ]);
        $serviceInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $serviceInvoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Initial paid period',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        Carbon::setTestNow('2026-07-16 00:00:00');
        $firstUpgrade = $this->upgradeModel($service, $firstTarget);
        $firstUpgrade->status = ServiceUpgrade::STATUS_PROVISIONING;
        $firstUpgrade->active_service_guard_id = $service->id;
        $firstUpgrade->currency_code = 'USD';
        $firstUpgrade->captureSnapshots();
        $firstUpgrade->quoted_amount = '45.00';
        $firstUpgrade->save();
        $upgradeInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $upgradeInvoice->items()->create([
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $firstUpgrade->id,
            'description' => 'First upgrade delta',
            'quantity' => 1,
            'price' => '45.00',
        ]);
        ServiceUpgradeMutationCoordinator::run(
            $firstUpgrade,
            function () use ($firstUpgrade, $upgradeInvoice): void {
                $firstUpgrade->forceFill([
                    'invoice_id' => $upgradeInvoice->id,
                    'status' => ServiceUpgrade::STATUS_COMPLETED,
                    'active_service_guard_id' => null,
                    'completed_at' => now(),
                ])->save();
            }
        );
        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service, $firstTarget): void {
                $service->forceFill([
                    'product_id' => $firstTarget->product->id,
                    'plan_id' => $firstTarget->plan->id,
                    'price' => '100.00',
                    'current_period_price' => '100.00',
                ])->save();
            }
        );

        Carbon::setTestNow('2026-07-17 00:00:00');
        $service = $service->fresh([
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
            'user',
            'coupon',
        ]);
        $pricing = app(ServiceUpgradePricingService::class);
        $this->assertSame(
            '100.00',
            $pricing->currentRecurringBasis($service)['amount']
        );
        $this->assertSame(
            '46.67',
            $pricing->remainingRefundableValue($service)['amount']
        );

        $secondUpgrade = $this->upgradeModel(
            $service,
            $secondTarget
        );
        $this->assertSame(
            -23.33,
            round((float) $secondUpgrade->calculatePrice()->price, 2)
        );
    }

    public function test_calendar_month_proration_uses_exact_period_dates(): void
    {
        foreach ([
            [
                'now' => '2026-02-02 00:00:00',
                'start' => '2026-02-01 00:00:00',
                'expiry' => '2026-03-01',
                'expected' => 9.64,
            ],
            [
                'now' => '2026-05-02 00:00:00',
                'start' => '2026-05-01 00:00:00',
                'expiry' => '2026-06-01',
                'expected' => 9.68,
            ],
        ] as $scenario) {
            Carbon::setTestNow($scenario['now']);
            $source = $this->createProduct();
            $target = $this->createProduct();
            $target->plan->prices()->update(['price' => '20.00']);
            $service = Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $source->product->id,
                'plan_id' => $source->plan->id,
                'status' => Service::STATUS_ACTIVE,
                'currency_code' => 'USD',
                'quantity' => 1,
                'price' => '10.00',
                'period_base_price' => '10.00',
                'current_period_price' => '10.00',
                'pricing_ledger_started_at' => $scenario['start'],
                'expires_at' => $scenario['expiry'],
            ]);
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'currency_code' => 'USD',
                'status' => Invoice::STATUS_PAID,
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $service->id,
                'description' => 'Exact calendar period',
                'quantity' => 1,
                'price' => '10.00',
            ]);

            $upgrade = $this->upgradeModel($service, $target);
            $this->assertSame(
                $scenario['expected'],
                round((float) $upgrade->calculatePrice()->price, 2)
            );
            $this->assertSame(
                number_format(
                    $scenario['expected'],
                    2,
                    '.',
                    ''
                ),
                app(ServiceUpgradePricingService::class)
                    ->remainingRefundableValue(
                        $upgrade->service
                    )['amount']
            );
        }
    }

    public function test_upgrade_completed_exactly_at_period_start_remains_in_refund_ledger(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'period_base_price' => '10.00',
            'current_period_price' => '10.00',
            'pricing_ledger_started_at' => now(),
            'expires_at' => '2026-07-31',
        ]);
        $serviceInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $serviceInvoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Period boundary payment',
            'quantity' => 1,
            'price' => '10.00',
        ]);
        $upgrade = $this->upgradeModel($service, $target);
        $upgrade->status = ServiceUpgrade::STATUS_PROVISIONING;
        $upgrade->active_service_guard_id = $service->id;
        $upgrade->captureSnapshots();
        $upgrade->quoted_amount = '10.00';
        $upgrade->save();
        $upgradeInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $upgradeInvoice->items()->create([
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
            'description' => 'Boundary upgrade charge',
            'quantity' => 1,
            'price' => '10.00',
        ]);
        ServiceUpgradeMutationCoordinator::run(
            $upgrade,
            function () use ($upgrade, $upgradeInvoice): void {
                $upgrade->forceFill([
                    'invoice_id' => $upgradeInvoice->id,
                    'status' => ServiceUpgrade::STATUS_COMPLETED,
                    'active_service_guard_id' => null,
                    'completed_at' => now(),
                ])->save();
            }
        );
        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service, $target): void {
                $service->forceFill([
                    'product_id' => $target->product->id,
                    'plan_id' => $target->plan->id,
                    'price' => '20.00',
                    'current_period_price' => '20.00',
                ])->save();
            }
        );

        Carbon::setTestNow('2026-07-16 00:00:00');
        $this->assertSame(
            '10.00',
            app(ServiceUpgradePricingService::class)
                ->remainingRefundableValue(
                    $service->fresh([
                        'plan',
                        'user',
                    ])
                )['amount']
        );
    }

    public function test_coupon_and_product_membership_cannot_change_during_active_quote(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $unrelated = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 0,
            'code' => 'LOCKED',
            'value' => 50,
        ]);
        $coupon->products()->attach([
            $source->product->id,
            $unrelated->product->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
        ]);
        ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'active_service_guard_id' => $service->id,
            'currency_code' => 'USD',
        ]);

        try {
            $coupon->forceFill(['value' => 25])->save();
            $this->fail('Coupon pricing changed during an active quote.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot change',
                $exception->getMessage()
            );
        }
        try {
            $unrelated->product->delete();
            $this->fail(
                'A product cascade changed signed coupon eligibility.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot change',
                $exception->getMessage()
            );
        }
        try {
            $coupon->products()->detach($source->product->id);
            $this->fail(
                'Coupon product eligibility changed during an active quote.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot change',
                $exception->getMessage()
            );
        }

        $this->assertSame(50.0, $coupon->fresh()->value);
        $this->assertTrue(
            $coupon->fresh()->products->contains($source->product)
        );
    }

    public function test_setup_fee_is_never_part_of_proration_or_refund(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'period_base_price' => '10.00',
            'current_period_price' => '10.00',
            'pricing_ledger_started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Recurring $10 plus non-refundable setup $100',
            'quantity' => 1,
            'price' => '110.00',
        ]);

        Carbon::setTestNow('2026-07-16 00:00:00');
        $upgrade = $this->upgradeModel($service, $target);

        $this->assertSame(
            5.0,
            (float) $upgrade->calculatePrice()->price
        );
        $this->assertSame(
            '5.00',
            app(ServiceUpgradePricingService::class)
                ->remainingRefundableValue(
                    $upgrade->service
                )['amount']
        );
    }

    public function test_unsigned_legacy_completed_upgrade_before_ledger_baseline_is_ignored(): void
    {
        Carbon::setTestNow('2026-07-20 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'period_base_price' => '10.00',
            'current_period_price' => '10.00',
            'pricing_ledger_started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => ServiceUpgrade::STATUS_COMPLETED,
            'completed_at' => now()->subDay(),
        ]);

        $this->assertSame(
            10.0,
            (float) $this->upgradeModel(
                $service,
                $target
            )->calculatePrice()->price
        );
    }

    public function test_early_renewal_blocks_upgrade_until_new_period_boundary(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'period_base_price' => '10.00',
            'current_period_price' => '10.00',
            'pricing_ledger_started_at' => now()->subDays(23),
            'expires_at' => '2026-08-01',
        ]);
        $renewal = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'due_at' => $service->expires_at,
        ]);
        $renewal->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Early renewal',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        app(RenewServiceService::class)->handle($service, $renewal);
        $service = $service->fresh();
        $this->assertSame(
            '2026-08-01',
            $service->pricing_ledger_started_at->toDateString()
        );

        try {
            $this->upgradeModel($service, $target)->calculatePrice();
            $this->fail(
                'An upgrade consumed overlapping old and renewed coverage.'
            );
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'newly paid billing period begins',
                $exception->getMessage()
            );
        }

        Carbon::setTestNow('2026-08-01 00:00:00');
        $this->assertSame(
            10.0,
            (float) $this->upgradeModel(
                $service,
                $target
            )->calculatePrice()->price
        );
    }

    public function test_suspended_late_renewal_uses_today_for_entire_new_period(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_SUSPENDED,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => '2026-07-01',
        ]);
        $renewal = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'due_at' => $service->expires_at,
        ]);
        $renewal->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Late suspended renewal',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        app(RenewServiceService::class)->handle($service, $renewal);
        $service->refresh();
        $this->assertSame(
            '2026-07-25',
            $service->pricing_ledger_started_at->toDateString()
        );
        $this->assertSame(
            '2026-08-25',
            $service->expires_at->toDateString()
        );
        $this->assertNotNull($service->pricing_ledger_verified_at);
        $this->assertSame(
            10.0,
            (float) $this->upgradeModel(
                $service,
                $target
            )->calculatePrice()->price
        );
    }

    public function test_active_late_renewal_uses_today_for_entire_new_period(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => '2026-07-01',
        ]);
        $renewal = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'due_at' => $service->expires_at,
        ]);
        $renewal->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Late active renewal',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        app(RenewServiceService::class)->handle($service, $renewal);
        $service->refresh();

        $this->assertSame(
            '2026-07-25',
            $service->pricing_ledger_started_at->toDateString()
        );
        $this->assertSame(
            '2026-08-25',
            $service->expires_at->toDateString()
        );
        $this->assertNotNull($service->pricing_ledger_verified_at);
    }

    public function test_legacy_backfill_never_invents_refundable_first_cycle_value(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => null,
            'code' => 'LEGACY-FIRST',
            'value' => 50,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            // Historical first-cycle services store their future,
            // undiscounted renewal price here.
            'price' => '10.00',
            'expires_at' => '2026-08-01',
        ]);
        DB::table('services')->where('id', $service->id)->update([
            'period_base_price' => null,
            'current_period_price' => null,
            'pricing_ledger_started_at' => null,
            'pricing_ledger_verified_at' => null,
        ]);

        $migration = require database_path(
            'migrations/2026_07_27_000191_backfill_service_period_pricing_ledger.php'
        );
        $migration->up();

        $service->refresh();
        $this->assertSame(
            '0.00',
            (string) $service->period_base_price
        );
        $this->assertSame(
            '10.00',
            (string) $service->current_period_price
        );
        $this->assertNull($service->pricing_ledger_started_at);
        $this->assertNull($service->pricing_ledger_verified_at);
        $this->assertSame(1, $service->billing_cycles_completed);

        Carbon::setTestNow('2026-08-02 00:00:00');
        $target = $this->createProduct();
        try {
            $this->upgradeModel($service, $target)->calculatePrice();
            $this->fail(
                'An unverified legacy ledger unlocked without a clean renewal.'
            );
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'verified recurring-period payment evidence',
                $exception->getMessage()
            );
        }
    }

    public function test_finite_coupon_backfill_preserves_paid_cycles_but_exhausts_unprovable_free_cycles(): void
    {
        $source = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 5,
            'code' => 'LEGACY-FIVE',
            'value' => 50,
        ]);
        $paidService = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
        ]);
        foreach (range(1, 2) as $cycle) {
            $invoice = Invoice::factory()->create([
                'user_id' => $paidService->user_id,
                'currency_code' => 'USD',
                'status' => Invoice::STATUS_PAID,
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $paidService->id,
                'description' => "Reconstructable cycle {$cycle}",
                'quantity' => 1,
                'price' => '5.00',
            ]);
        }
        $freeService = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
        ]);
        DB::table('services')
            ->whereIn('id', [$paidService->id, $freeService->id])
            ->update([
                'period_base_price' => null,
                'current_period_price' => null,
                'pricing_ledger_started_at' => null,
                'pricing_ledger_verified_at' => null,
                'billing_cycles_completed' => 0,
            ]);

        $migration = require database_path(
            'migrations/2026_07_27_000191_backfill_service_period_pricing_ledger.php'
        );
        $migration->up();

        $this->assertSame(
            2,
            $paidService->fresh()->billing_cycles_completed
        );
        $this->assertSame(
            5,
            $freeService->fresh()->billing_cycles_completed
        );
    }

    public function test_unverified_service_cannot_mint_downgrade_credit(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $source->plan->prices()->update(['price' => '100.00']);
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '10.00']);
        $service = Service::factory()->make([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '100.00',
            'period_base_price' => null,
            'current_period_price' => null,
            'pricing_ledger_started_at' => null,
            'pricing_ledger_verified_at' => null,
            'expires_at' => now()->addDays(30),
        ]);
        $service->save();
        $service->refresh();

        $this->assertSame('0.00', (string) $service->period_base_price);
        $this->assertSame(
            '100.00',
            (string) $service->current_period_price
        );
        $this->assertNull($service->pricing_ledger_started_at);
        $this->assertNull($service->pricing_ledger_verified_at);

        try {
            $this->upgradeModel($service, $target)->calculatePrice();
            $this->fail(
                'An unverified administrative service minted a downgrade credit.'
            );
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'verified recurring-period payment evidence',
                $exception->getMessage()
            );
        }
    }

    public function test_expired_recurring_service_cannot_be_upgraded(): void
    {
        Carbon::setTestNow('2026-07-25 00:00:00');
        $source = $this->createProduct();
        $target = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => now()->startOfDay(),
        ]);

        try {
            $this->upgradeModel($service, $target)->calculatePrice();
            $this->fail('An expired recurring service was upgradable.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'must be renewed',
                $exception->getMessage()
            );
        }
    }

    public function test_cross_product_upgrade_cannot_change_billing_type(): void
    {
        foreach ([
            ['source' => 'free', 'target' => 'recurring'],
            ['source' => 'recurring', 'target' => 'free'],
        ] as $types) {
            $source = $this->createProduct();
            $target = $this->createProduct();
            $source->plan->forceFill([
                'type' => $types['source'],
            ])->save();
            $target->plan->forceFill([
                'type' => $types['target'],
            ])->save();
            $source->product->upgrades()->attach(
                $target->product->id
            );
            $service = Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $source->product->id,
                'plan_id' => $source->plan->id,
                'status' => Service::STATUS_ACTIVE,
                'currency_code' => 'USD',
                'quantity' => 1,
                'price' => $types['source'] === 'free'
                    ? '0.00'
                    : '10.00',
                'expires_at' => $types['source'] === 'recurring'
                    ? now()->addMonth()
                    : null,
            ]);

            $this->assertCount(0, $service->productUpgrades());
            try {
                $this->upgradeModel($service, $target)
                    ->captureSnapshots();
                $this->fail(
                    'A cross-product upgrade changed its billing type.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'preserve the service billing type',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_service_without_finite_coverage_cannot_mint_downgrade_credit(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $source->plan->forceFill(['type' => 'one-time'])->save();
        $target->plan->forceFill(['type' => 'one-time'])->save();
        $source->plan->prices()->update(['price' => '20.00']);
        $target->plan->prices()->update(['price' => '10.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '20.00',
            'period_base_price' => '20.00',
            'current_period_price' => '20.00',
            'expires_at' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'One-time purchase',
            'quantity' => 1,
            'price' => '20.00',
        ]);
        $upgrade = $this->upgradeModel($service, $target);

        $this->assertSame(
            '0.00',
            app(ServiceUpgradePricingService::class)
                ->remainingRefundableValue(
                    $upgrade->service
                )['amount']
        );
        $this->assertSame(
            0.0,
            (float) $upgrade->calculatePrice()->price
        );
    }

    public function test_mutable_credit_column_cannot_exceed_signed_downgrade_credit(): void
    {
        config(['settings.credits_on_downgrade' => true]);
        Carbon::setTestNow('2026-07-01 00:00:00');
        $source = $this->createProduct();
        $source->plan->prices()->update(['price' => '20.00']);
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => '10.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '20.00',
            'period_base_price' => '20.00',
            'current_period_price' => '20.00',
            'expires_at' => now()->addDays(30),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'description' => 'Paid source period',
            'quantity' => 1,
            'price' => '20.00',
        ]);
        $upgrade = $this->upgradeModel($service, $target);
        $upgrade->status = ServiceUpgrade::STATUS_AWAITING_PAYMENT;
        $upgrade->active_service_guard_id = $service->id;
        $upgrade->capacity_mode = ServiceUpgrade::CAPACITY_MODE_STATIC;
        $upgrade->captureSnapshots();
        $upgrade->quoted_amount =
            $upgrade->signedUpgradePrice()->price;
        $this->assertSame(10.0, $upgrade->signedCreditAmount());
        $upgrade->credit_amount = '999.00';
        $upgrade->save();

        try {
            app(ServiceUpgradeService::class)
                ->markPaidCommitted($upgrade);
            $this->fail(
                'A mutable credit column exceeded its signed downgrade cap.'
            );
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'signed pricing snapshot',
                $exception->getMessage()
            );
        }
    }

    public function test_config_value_pricing_uses_service_currency_not_session_currency(): void
    {
        session(['currency' => 'AUD']);
        $source = $this->createProduct();
        $target = $this->createProduct();
        $parent = ConfigOption::create([
            'name' => 'Template',
            'env_variable' => 'template',
            'type' => 'select',
            'hidden' => false,
            'upgradable' => true,
        ]);
        $target->product->configOptions()->attach($parent);
        $child = ConfigOption::create([
            'name' => 'Premium',
            'env_variable' => 'premium',
            'type' => 'select',
            'hidden' => false,
            'upgradable' => false,
            'parent_id' => $parent->id,
        ]);
        $audPlan = Plan::factory()->create([
            'priceable_id' => $child->id,
            'priceable_type' => ConfigOption::class,
            'billing_period' => 1,
            'billing_unit' => 'month',
            'type' => 'recurring',
            'sort' => 1,
        ]);
        Price::factory()->create([
            'plan_id' => $audPlan->id,
            'currency_code' => 'AUD',
            'price' => '100.00',
        ]);
        $usdPlan = Plan::factory()->create([
            'priceable_id' => $child->id,
            'priceable_type' => ConfigOption::class,
            'billing_period' => 1,
            'billing_unit' => 'month',
            'type' => 'recurring',
            'sort' => 2,
        ]);
        Price::factory()->create([
            'plan_id' => $usdPlan->id,
            'currency_code' => 'USD',
            'price' => '1.00',
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '10.00',
            'expires_at' => now()->addDays(30),
        ]);
        $upgrade = $this->upgradeModel($service, $target);
        $config = new ServiceConfig([
            'config_option_id' => $parent->id,
            'config_value_id' => $child->id,
        ]);
        $config->setRelation('configOption', $parent);
        $config->setRelation(
            'configValue',
            $child->fresh('plans.prices')
        );
        $upgrade->setRelation('configs', collect([$config]));

        $this->assertSame(
            1.0,
            (float) $upgrade->calculatePrice()->price
        );
    }

    private function upgradeModel(
        Service $service,
        object $target
    ): ServiceUpgrade {
        $service = $service->fresh([
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
            'user.properties',
            'coupon',
            'currency',
        ]);
        $upgrade = new ServiceUpgrade([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
            'currency_code' => $service->currency_code,
        ]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation(
            'product',
            $target->product->fresh([
                'server.settings',
                'settings',
            ])
        );
        $upgrade->setRelation(
            'plan',
            $target->plan->fresh('prices')
        );
        $upgrade->setRelation('configs', collect());

        return $upgrade;
    }
}
