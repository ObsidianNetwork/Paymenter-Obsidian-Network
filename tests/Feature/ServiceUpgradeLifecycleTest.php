<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeFailureAlertService;
use App\Services\ServiceUpgrade\UpgradeGuaranteeService;
use App\Support\LegacyServiceUpgradeMigration;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
            'expires_at' => now()->addMonth()->startOfDay(),
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

    public function test_empty_legacy_upgrade_reconciliation_skips_product_catalog_lookup(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        LegacyServiceUpgradeMigration::reconcile();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('config_options', $queries);
        $this->assertStringNotContainsString('extensions', $queries);
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
        // Model pre-migration payment evidence without triggering the current
        // observer-driven payment coordinator.
        DB::table('invoice_transactions')->insert([
            'invoice_id' => $invoice->id,
            'amount' => 1,
            'fee' => 0,
            'status' => InvoiceTransactionStatus::Processing->value,
            'is_credit_transaction' => false,
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_non_pterodactyl_slider_upgrade_without_signed_quote_is_retired(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade('CustomServer');

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

    public function test_gateway_named_pterodactyl_upgrade_without_signed_quote_is_retired(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade(
            'Pterodactyl',
            'gateway'
        );

        LegacyServiceUpgradeMigration::reconcile();

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
    }

    public function test_soft_deleted_pterodactyl_host_still_retires_unsafe_upgrade(): void
    {
        [$upgrade, $invoice] = $this->legacyDynamicUpgrade(
            softDeleteServer: true
        );

        LegacyServiceUpgradeMigration::reconcile();

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
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

        $this->expectException(DisplayException::class);
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

    public function test_needs_attention_failure_is_a_noop_before_retry_budget_is_exhausted(): void
    {
        $alert = \Mockery::mock(UpgradeFailureAlertService::class);
        $alert->shouldNotReceive('notify');
        $this->app->instance(UpgradeFailureAlertService::class, $alert);

        foreach ([0, 1] as $attempts) {
            $fixture = $this->createProduct();
            $service = Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $fixture->product->id,
                'plan_id' => $fixture->plan->id,
                'status' => Service::STATUS_ACTIVE,
            ]);
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'currency_code' => 'USD',
                'status' => Invoice::STATUS_PAID,
            ]);
            $failedAt = now()->subMinute()->startOfSecond();
            $alertedAt = now()->subSeconds(30)->startOfSecond();
            $upgrade = ServiceUpgrade::create([
                'service_id' => $service->id,
                'product_id' => $fixture->product->id,
                'plan_id' => $fixture->plan->id,
                'invoice_id' => $invoice->id,
                'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                'type' => 'product',
                'active_service_guard_id' => $service->id,
                'provisioning_attempts' => $attempts,
                'last_error' => 'Original permanent failure.',
                'failed_at' => $failedAt,
                'failure_alerted_at' => $alertedAt,
            ]);
            $coordinator = new class
            {
                public int $calls = 0;

                public function failProvisioning(
                    ServiceUpgrade $upgrade,
                    \Throwable $exception,
                    ?string $reservationLeaseId
                ): bool {
                    $this->calls++;

                    return true;
                }
            };
            $upgrades = new class($coordinator) extends ServiceUpgradeService
            {
                public function __construct(
                    private object $coordinator
                ) {}

                protected function usesDynamicCapacity(
                    ServiceUpgrade $upgrade
                ): bool {
                    return true;
                }

                protected function capacityService(): object
                {
                    return $this->coordinator;
                }
            };

            $upgrades->recordFailure(
                $upgrade,
                new \RuntimeException('Duplicate queue delivery.')
            );

            $upgrade->refresh();
            $this->assertSame(0, $coordinator->calls);
            $this->assertSame(
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                $upgrade->status
            );
            $this->assertSame(
                'Original permanent failure.',
                $upgrade->last_error
            );
            $this->assertSame(
                $attempts,
                (int) $upgrade->provisioning_attempts
            );
            $this->assertTrue($failedAt->equalTo($upgrade->failed_at));
            $this->assertTrue(
                $alertedAt->equalTo($upgrade->failure_alerted_at)
            );
        }
    }

    public function test_upgrade_completion_rechecks_billing_anchor_before_local_commit(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => 10,
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'provisioning_attempts' => 1,
            'quoted_amount' => 0,
            'currency_code' => 'USD',
        ]);
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();
        $originalPrice = (string) $service->fresh()->price;

        DB::table('services')
            ->where('id', $service->id)
            ->update([
                'expires_at' => $service->expires_at->copy()->addDay(),
            ]);

        try {
            app(ServiceUpgradeService::class)->complete($upgrade);
            $this->fail(
                'Expected the final billing-anchor proof to reject drift.'
            );
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'changed after remote upgrade provisioning',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_PROVISIONING,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            $fixture->product->id,
            $service->fresh()->product_id
        );
        $this->assertSame($originalPrice, (string) $service->fresh()->price);
    }

    public function test_billing_anchor_edit_is_serialized_with_active_upgrade(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => 10,
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);
        ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'quoted_amount' => 0,
            'currency_code' => 'USD',
        ]);

        try {
            app(
                ServiceBillingAnchorMutationCoordinator::class
            )->update($service, ['price' => '11.00']);
            $this->fail(
                'An administrator changed a billing anchor during an active upgrade.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot change while an upgrade is active',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            '10.00',
            number_format((float) $service->fresh()->price, 2, '.', '')
        );
    }

    public function test_upgrade_completion_never_reprices_an_issued_renewal_invoice(): void
    {
        Queue::fake();
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()->update(['price' => 20]);
        $target->plan->load('prices');
        $source->product->upgrades()->attach($target->product->id);
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => 10,
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'currency_code' => 'USD',
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
        ]);
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->quoted_amount = $upgrade->signedUpgradePrice()->price;
        $upgrade->credit_amount = $upgrade->signedCreditAmount();
        ServiceUpgradeMutationCoordinator::save($upgrade);
        $upgradeInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
        ]);
        $upgradeInvoice->items()->create([
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
            'description' => 'Product upgrade',
            'quantity' => 1,
            'price' => $upgrade->quoted_amount,
        ]);
        $upgrade->invoice_id = $upgradeInvoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);
        ExtensionHelper::addPayment(
            $upgradeInvoice,
            null,
            $upgrade->quoted_amount,
            transactionId: 'immutable-renewal-upgrade'
        );
        (new UniqueLock(app('cache')->store()))
            ->release(new UpgradeJob($upgrade));

        $renewal = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $service->expires_at,
        ]);
        $renewalItem = $renewal->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Issued renewal',
            'quantity' => 1,
            'price' => 10,
        ]);
        $upgrade = app(ServiceUpgradeService::class)
            ->beginProvisioning($upgrade->fresh());
        $this->assertNotNull($upgrade);

        app(ServiceUpgradeService::class)->complete($upgrade);

        $this->assertSame(
            '20.00',
            number_format((float) $service->fresh()->price, 2, '.', '')
        );
        $this->assertSame(
            '10.00',
            (string) $renewalItem->fresh()->price
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
    }

    public function test_upgrade_completion_keeps_service_upgrade_reservation_lock_order(): void
    {
        $method = new \ReflectionMethod(
            ServiceUpgradeService::class,
            'complete'
        );
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $serviceLock = strpos(
            $source,
            '$service = Service::query()'
        );
        $upgradeLock = strpos(
            $source,
            '$upgrade = $this->lockedUpgrade($upgradeId)'
        );
        $reservationLock = strpos(
            $source,
            '$this->capacityService()->completeProvisioning'
        );

        $this->assertIsInt($serviceLock);
        $this->assertIsInt($upgradeLock);
        $this->assertIsInt($reservationLock);
        $this->assertTrue($serviceLock < $upgradeLock);
        $this->assertTrue($upgradeLock < $reservationLock);
    }

    public function test_coordinator_resolution_loss_after_provisioning_requires_attention(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'provisioning_attempts' => 1,
        ]);
        $this->mock(
            UpgradeFailureAlertService::class,
            function (MockInterface $mock) use ($upgrade): void {
                $mock->shouldReceive('notify')
                    ->once()
                    ->with($upgrade->id);
            }
        );

        $upgrades = new class extends ServiceUpgradeService
        {
            protected function usesDynamicCapacity(
                ServiceUpgrade $upgrade
            ): bool {
                return true;
            }

            protected function capacityService(): object
            {
                throw new \RuntimeException(
                    'The extension container binding disappeared.'
                );
            }
        };
        $upgrades->recordFailure(
            $upgrade,
            new \RuntimeException('Pterodactyl request timed out.'),
            reservationLeaseId: 'lease-lost-runtime'
        );

        $upgrade->refresh();
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->status
        );
        $this->assertSame(
            $service->id,
            $upgrade->active_service_guard_id
        );
        $this->assertNotNull($upgrade->failed_at);
        $this->assertNotNull($upgrade->failure_alerted_at);
        $this->assertStringContainsString(
            'Pterodactyl request timed out.',
            (string) $upgrade->last_error
        );
        $this->assertStringContainsString(
            'extension container binding disappeared',
            (string) $upgrade->last_error
        );
    }

    private function legacyDynamicUpgrade(
        string $serverExtension = 'Pterodactyl',
        string $extensionType = 'server',
        bool $softDeleteServer = false
    ): array {
        $fixture = $this->createProduct();
        $server = Server::create([
            'name' => $serverExtension,
            'extension' => $serverExtension,
            'type' => $extensionType,
            'enabled' => true,
        ]);
        $fixture->product->server_id = $server->id;
        $fixture->product->save();
        if ($softDeleteServer) {
            DB::table('extensions')
                ->where('id', $server->id)
                ->update(['deleted_at' => now()]);
        }
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
            'status' => ServiceUpgrade::STATUS_PENDING,
            'type' => 'product',
        ]);
        $invoice->items()->create([
            'description' => 'Legacy upgrade',
            'price' => '1.00',
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

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
