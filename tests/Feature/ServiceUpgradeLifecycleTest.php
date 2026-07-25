<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\Server;
use App\Models\User;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeFailureAlertService;
use App\Services\ServiceUpgrade\UpgradeGuaranteeService;
use App\Support\LegacyServiceUpgradeMigration;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ServiceUpgradeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_coupon_is_applied_to_both_sides_of_upgrade_delta(): void
    {
        $fixture = $this->createProduct();
        $option = $this->dynamicOption($fixture->product->id);
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 0,
            'code' => 'HALF',
            'value' => 50,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => 7,
            'expires_at' => null,
        ]);
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->update(['status' => Service::STATUS_ACTIVE])
        );
        ServiceConfig::create([
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $option->id,
            'config_value_id' => null,
            'slider_value' => 4,
        ]);
        $service = $service->fresh([
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
            'coupon',
        ]);

        $targetConfig = new ServiceConfig([
            'config_option_id' => $option->id,
            'slider_value' => 8,
        ]);
        $targetConfig->setRelation('configOption', $option);
        $upgrade = new ServiceUpgrade([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $fixture->product);
        $upgrade->setRelation('plan', $fixture->plan->load('prices'));
        $upgrade->setRelation('configs', collect([$targetConfig]));

        // Old recurring amount: (10 + 4) / 2 = 7.
        // Target recurring amount: (10 + 8) / 2 = 9.
        $this->assertSame(2.0, (float) $upgrade->calculatePrice()->price);
        $upgrade->captureSnapshots();
        $this->assertSame(
            '9.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );

        $metadata = $option->metadata;
        $metadata['pricing']['rate_per_unit'] = 10;
        $option->metadata = $metadata;
        $option->save();

        $this->assertSame(
            '9.00',
            data_get($upgrade->target_snapshot, 'recurring_price')
        );
    }

    public function test_decimal_static_resource_fallback_is_rejected(): void
    {
        $fixture = $this->createProduct();
        foreach ([
            'memory' => '4096.5',
            'cpu' => '200',
            'disk' => '20480',
            'location' => '1',
        ] as $key => $value) {
            $fixture->product->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
        ]);
        $upgrade = new ServiceUpgrade([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation(
            'product',
            $fixture->product->fresh('settings')
        );
        $upgrade->setRelation('configs', collect());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive whole number');

        $upgrade->targetResources();
    }

    public function test_legacy_dynamic_upgrade_without_payment_is_retired_with_invoice(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade();

        LegacyServiceUpgradeMigration::reconcile();

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertNull($upgrade->fresh()->active_service_guard_id);
    }

    public function test_legacy_dynamic_upgrade_with_payment_evidence_requires_attention(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade();
        InvoiceTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => 1,
            'fee' => 0,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        LegacyServiceUpgradeMigration::reconcile();

        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    public function test_non_pterodactyl_slider_upgrade_is_not_retired_as_dynamic_stock(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade('CustomServer');

        LegacyServiceUpgradeMigration::reconcile();

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(
            $upgrade->service_id,
            $upgrade->fresh()->active_service_guard_id
        );
    }

    public function test_upgrade_guarantee_accepts_service_expiring_at_exact_boundary(): void
    {
        $from = Carbon::parse('2026-07-26 00:00:00');
        $service = new Service([
            'expires_at' => $from->copy()->addDays(7),
        ]);

        $deadline = app(UpgradeGuaranteeService::class)
            ->deadline($service, $from);

        $this->assertTrue(
            $deadline->equalTo($from->copy()->addDays(7))
        );
    }

    public function test_upgrade_guarantee_rejects_service_expiring_before_boundary(): void
    {
        $from = Carbon::parse('2026-07-26 00:00:00');
        $service = new Service([
            'expires_at' => $from->copy()->addDays(6),
        ]);

        $this->expectException(\App\Exceptions\DisplayException::class);
        $this->expectExceptionMessage('full seven-day');

        app(UpgradeGuaranteeService::class)->deadline($service, $from);
    }

    public function test_terminal_upgrade_failure_alert_is_deduplicated(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->update(['status' => Service::STATUS_ACTIVE])
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'type' => 'product',
            'provisioning_attempts' => 5,
        ]);
        $this->mock(
            UpgradeFailureAlertService::class,
            function (MockInterface $mock) use ($upgrade): void {
                $mock->shouldReceive('notify')
                    ->once()
                    ->with($upgrade->id);
            }
        );

        $service = app(ServiceUpgradeService::class);
        $service->recordFailure(
            $upgrade,
            new \RuntimeException('panel timeout')
        );
        $firstAlertedAt = $upgrade->fresh()->failure_alerted_at;
        $service->recordFailure(
            $upgrade,
            new \RuntimeException('duplicate terminal callback'),
            true
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertNotNull($firstAlertedAt);
        $this->assertTrue(
            $firstAlertedAt->equalTo(
                $upgrade->fresh()->failure_alerted_at
            )
        );
    }

    private function legacyDynamicUpgrade(
        string $serverExtension = 'Pterodactyl'
    ): array
    {
        $fixture = $this->createProduct();
        $server = Server::create([
            'name' => $serverExtension,
            'extension' => $serverExtension,
            'type' => 'server',
            'enabled' => true,
        ]);
        $fixture->product->server_id = $server->id;
        $fixture->product->save();
        $this->dynamicOption($fixture->product->id);
        $user = User::factory()->create();
        $service = CapacityServiceCreationCoordinator::run(
            fn () => Service::factory()->create([
                'user_id' => $user->id,
                'product_id' => $fixture->product->id,
                'plan_id' => $fixture->plan->id,
                'status' => Service::STATUS_PENDING,
                'currency_code' => 'USD',
                'quantity' => 1,
                'price' => 10,
            ])
        );
        FulfillmentStatusTransitionService::run(
            $service,
            fn () => $service->update(['status' => Service::STATUS_ACTIVE])
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_PENDING,
            'type' => 'product',
        ]);

        return [$upgrade, $invoice];
    }

    private function dynamicOption(int $productId): ConfigOption
    {
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'default' => 4,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 1,
                ],
            ],
        ]);
        ConfigOptionProduct::create([
            'product_id' => $productId,
            'config_option_id' => $option->id,
        ]);

        return $option;
    }
}
