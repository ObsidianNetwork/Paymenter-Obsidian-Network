<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\PermanentProvisioningException;
use App\Jobs\Server\UpgradeJob;
use App\Livewire\Services\Upgrade as UpgradeComponent;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\ProductUpgrade;
use App\Models\Property;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeReconciliationService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\StaticUpgradeStockService;
use App\Services\ServiceUpgrade\UpgradeProvisionerIdentityService;
use App\Support\LegacyServiceUpgradeMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class StaticUpgradeTargetContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_plan_must_match_service_currency_type_and_cycle(): void
    {
        $source = $this->createProduct();
        $service = $this->serviceFor($source);
        $component = app(UpgradeComponent::class);
        $component->service = $service;

        $wrongCurrency = $this->createProduct();
        $wrongCurrency->plan->prices()->delete();
        Price::factory()->create([
            'plan_id' => $wrongCurrency->plan->id,
            'currency_code' => 'AUD',
            'price' => '10.00',
        ]);

        $wrongType = $this->createProduct();
        $wrongType->plan->forceFill(['type' => 'one-time'])->save();

        $wrongCycle = $this->createProduct();
        $wrongCycle->plan->forceFill([
            'billing_period' => 3,
        ])->save();

        foreach ([$wrongCurrency, $wrongType, $wrongCycle] as $target) {
            $this->assertNull(
                $component->planForUpgradeProduct(
                    $target->product->fresh('plans.prices')
                )
            );
        }
    }

    public function test_unsupported_raw_target_configuration_is_not_selectable(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $source->product->upgrades()->attach($target->product->id);
        $unsupported = ConfigOption::create([
            'name' => 'Raw command',
            'env_variable' => 'raw_command',
            'type' => 'text',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
        ]);
        $unsupported->products()->attach($target->product->id);
        $service = $this->serviceFor($source);
        $component = app(UpgradeComponent::class);
        $component->service = $service->fresh([
            'product.upgrades.configOptions.children.plans.prices',
            'product.upgrades.plans.prices',
            'product.upgradableConfigOptions.children',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);

        $this->assertFalse(
            $component->selectableProductUpgrades()
                ->contains('id', $target->product->id)
        );
    }

    public function test_same_product_noop_is_rejected_without_durable_rows(): void
    {
        Queue::fake();
        $fixture = $this->createProduct();
        $service = $this->serviceFor($fixture);
        $this->authenticate($service->user);

        Livewire::test(UpgradeComponent::class, [
            'service' => $service,
        ])
            ->call('doUpgrade')
            ->assertDispatched(
                'notify',
                function (string $event, array $parameters): bool {
                    $payload = $parameters[0] ?? $parameters;

                    return $event === 'notify'
                        && ($payload['message'] ?? null)
                            === 'You have not changed any product or resource configuration.'
                        && ($payload['type'] ?? null) === 'error';
                }
            );

        $this->assertDatabaseMissing('service_upgrades', [
            'service_id' => $service->id,
        ]);
    }

    public function test_cross_product_quote_captures_complete_target_vector_and_completion_cleans_obsolete_state(): void
    {
        Queue::fake();
        $source = $this->createProduct(['stock' => 0]);
        $target = $this->createProduct(['stock' => 1]);
        $target->plan->prices()
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $source->product->upgrades()->attach($target->product->id);

        [$sharedOption, $sharedValue] = $this->selectOption(
            'Transferred template',
            'template',
            upgradable: false,
            products: [$source->product->id, $target->product->id]
        );
        [$sourceOnly, $sourceOnlyValue] = $this->selectOption(
            'Legacy image',
            'legacy_image',
            upgradable: false,
            products: [$source->product->id]
        );
        [$targetOnly, $targetOnlyValue] = $this->selectOption(
            'Target region',
            'target_region',
            upgradable: true,
            products: [$target->product->id]
        );

        $service = $this->serviceFor($source);
        $this->serviceConfig(
            $service,
            $sharedOption,
            configValueId: $sharedValue->id
        );
        $this->serviceConfig(
            $service,
            $sourceOnly,
            configValueId: $sourceOnlyValue->id
        );
        $service->properties()->create([
            'key' => 'provider_identity',
            'name' => 'Provider identity',
            'value' => 'external-123',
        ]);
        $service->properties()->create([
            'key' => 'legacy_image',
            'name' => 'Stale legacy image',
            'value' => 'stale',
        ]);
        $this->authenticate($service->user);

        Livewire::test(UpgradeComponent::class, [
            'service' => $service->fresh(),
        ])
            ->set('upgrade', $target->product->id)
            ->call('nextStep')
            ->set(
                "configOptions.{$targetOnly->id}",
                $targetOnlyValue->id
            )
            ->call('doUpgrade')
            ->assertHasNoErrors();

        $upgrade = ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->latest('id')
            ->firstOrFail();
        $configIds = collect($upgrade->target_snapshot['configs'])
            ->pluck('config_option_id')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            collect([$sharedOption->id, $targetOnly->id])
                ->sort()
                ->values()
                ->all(),
            $configIds
        );
        $this->assertSame(
            $sharedValue->id,
            $upgrade->configs()
                ->where('config_option_id', $sharedOption->id)
                ->value('config_value_id')
        );
        $this->assertArrayNotHasKey(
            'legacy_image',
            $upgrade->target_snapshot['properties']
        );
        $this->assertSame(
            'external-123',
            $upgrade->target_snapshot['properties']['provider_identity']
        );

        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);
        app(ServiceUpgradeService::class)->complete($upgrade);

        $this->assertDatabaseMissing('service_configs', [
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $sourceOnly->id,
        ]);
        $this->assertSame(
            collect([$sharedOption->id, $targetOnly->id])
                ->sort()
                ->values()
                ->all(),
            ServiceConfig::query()
                ->where('configurable_id', $service->id)
                ->where('configurable_type', Service::class)
                ->pluck('config_option_id')
                ->sort()
                ->values()
                ->all()
        );
        $this->assertDatabaseMissing('properties', [
            'model_id' => $service->id,
            'model_type' => Service::class,
            'key' => 'legacy_image',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => Service::class,
            'key' => 'provider_identity',
            'value' => 'external-123',
        ]);
    }

    public function test_later_slider_policy_change_does_not_reinterpret_signed_target(): void
    {
        $fixture = $this->createProduct();
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1,
                'max' => 32,
                'step' => 1,
                'default' => 4,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 0,
                ],
            ],
        ]);
        $option->products()->attach($fixture->product->id);
        $service = $this->serviceFor($fixture);
        $this->serviceConfig($service, $option, sliderValue: 4);
        $upgrade = $this->signedUpgrade(
            $service,
            $fixture,
            [[
                'option' => $option,
                'slider_value' => 8,
            ]]
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );

        $metadata = $option->metadata;
        $metadata['min'] = 16;
        $metadata['step'] = 16;
        $metadata['default'] = 16;
        $option->metadata = $metadata;
        $option->save();

        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);
        app(ServiceUpgradeService::class)->complete($upgrade);

        $this->assertSame(
            8,
            (int) ServiceConfig::query()
                ->where('configurable_id', $service->id)
                ->where('configurable_type', Service::class)
                ->where('config_option_id', $option->id)
                ->value('slider_value')
        );
    }

    public function test_product_upgrade_pivot_cannot_be_deleted_or_reassigned_during_active_quote(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $replacement = $this->createProduct();
        $source->product->upgrades()->attach($target->product->id);
        $service = $this->serviceFor($source);
        $this->signedUpgrade($service, $target);
        $pivot = ProductUpgrade::query()
            ->where('product_id', $source->product->id)
            ->where('upgrade_id', $target->product->id)
            ->firstOrFail();

        try {
            $pivot->delete();
            $this->fail(
                'An active quote lost its product-upgrade authorization.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'active capacity commitment',
                $exception->getMessage()
            );
        }

        $pivot = $pivot->fresh();
        try {
            $pivot->upgrade_id = $replacement->product->id;
            $pivot->save();
            $this->fail(
                'An active quote had its product-upgrade authorization reassigned.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'active capacity commitment',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('product_upgrades', [
            'product_id' => $source->product->id,
            'upgrade_id' => $target->product->id,
        ]);
    }

    public function test_enhance_org_and_endpoint_identity_are_immutable_during_upgrade(): void
    {
        [$server, $source, $target, $service, $organization] =
            $this->enhanceFixture();
        $upgrade = $this->signedUpgrade($service, $target);
        $identity = $upgrade->target_snapshot['provisioner'];

        $this->assertSame('external', $identity['mode']);
        $this->assertSame(
            'enhance-org-1',
            $identity['user_identity']['enhance_org_id']
        );

        try {
            $organization->value = 'enhance-org-2';
            $organization->save();
            $this->fail(
                'The signed Enhance organization identity was changed.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'immutable while an upgrade is active',
                $exception->getMessage()
            );
        }

        $host = $server->settings()
            ->where('key', 'host')
            ->firstOrFail();
        try {
            $host->value = 'https://other-enhance.example';
            $host->save();
            $this->fail(
                'The signed external provisioner endpoint was changed.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'provisioner identity is pinned',
                $exception->getMessage()
            );
        }

        DB::table('settings')
            ->where('id', $host->id)
            ->update(['value' => 'https://tampered-enhance.example']);
        try {
            app(UpgradeProvisionerIdentityService::class)
                ->assertCurrent($upgrade->fresh([
                    'service.product.server.settings',
                    'service.user',
                    'product.server.settings',
                ]));
            $this->fail(
                'A raw endpoint drift passed the signed identity proof.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'no longer matches its signed identity',
                $exception->getMessage()
            );
        }
    }

    public function test_enhance_upgrade_uses_signed_customer_and_target_identity(): void
    {
        [, , $target, $service] = $this->enhanceFixture();
        $upgrade = $this->signedUpgrade($service, $target);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
            'paid_at' => now(),
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);
        Http::fake([
            'https://enhance.example/api/orgs/enhance-org-1/subscriptions/subscription-1' => Http::response([], 200),
        ]);

        (new UpgradeJob($upgrade, false))
            ->handle(app(ServiceUpgradeService::class));

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'PATCH'
                && $request->url()
                    === 'https://enhance.example/api/orgs/enhance-org-1/subscriptions/subscription-1'
                && (int) $request['planId'] === 200
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_COMPLETED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            $target->product->id,
            $service->fresh()->product_id
        );
    }

    public function test_external_crash_after_remote_attempt_fails_closed_for_reconciliation(): void
    {
        [, , $target, $service] = $this->enhanceFixture();
        $upgrade = $this->signedUpgrade($service, $target);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'provisioning_started_at' => now()->subMinutes(11),
            'provisioning_attempts' => 1,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        try {
            app(ServiceUpgradeService::class)
                ->beginProvisioning($upgrade);
            $this->fail(
                'An indeterminate external upgrade was automatically replayed.'
            );
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'operator reconciliation',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
    }

    public function test_stale_serverless_provisioning_is_recovered_and_completed(): void
    {
        $fixture = $this->createProduct();
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1,
                'max' => 32,
                'step' => 1,
                'default' => 4,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 0,
                ],
            ],
        ]);
        $option->products()->attach($fixture->product->id);
        $service = $this->serviceFor($fixture);
        $this->serviceConfig($service, $option, sliderValue: 4);
        $upgrade = $this->signedUpgrade(
            $service,
            $fixture,
            [[
                'option' => $option,
                'slider_value' => 8,
            ]]
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'provisioning_started_at' => now()->subMinutes(11),
            'provisioning_attempts' => 1,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        $recovered = app(ServiceUpgradeService::class)
            ->beginProvisioning($upgrade);

        $this->assertNotNull($recovered);
        $this->assertSame(
            ServiceUpgrade::STATUS_PROVISIONING,
            $recovered->status
        );
        $this->assertSame(2, (int) $recovered->provisioning_attempts);
        app(ServiceUpgradeService::class)->complete($recovered);
        $this->assertSame(
            ServiceUpgrade::STATUS_COMPLETED,
            $upgrade->fresh()->status
        );
    }

    public function test_outstanding_unsigned_awaiting_upgrade_is_retired(): void
    {
        [$upgrade, $invoice] = $this->legacyAwaitingFixture();

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
    }

    public function test_current_signed_awaiting_quote_is_ignored_by_legacy_reconciliation(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $source->product->upgrades()->attach($target->product->id);
        $service = $this->serviceFor($source);
        $upgrade = $this->signedUpgrade($service, $target);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $invoice->items()->create([
            'description' => 'Current signed upgrade',
            'price' => $upgrade->quoted_amount,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        $this->assertNull($upgrade->fresh()->legacy_refund_only_at);
    }

    public function test_signed_awaiting_quote_with_stale_source_is_retired(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $source->product->upgrades()->attach($target->product->id);
        $service = $this->serviceFor($source);
        $upgrade = $this->signedUpgrade($service, $target);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $invoice->items()->create([
            'description' => 'Stale signed upgrade',
            'price' => $upgrade->quoted_amount,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        DB::table('services')
            ->where('id', $service->id)
            ->update(['price' => '11.00']);

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
    }

    public function test_outstanding_legacy_payment_is_preserved_for_refund_only_attention(): void
    {
        [$upgrade, $invoice] = $this->legacyAwaitingFixture(
            paymentEvidence: true
        );

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );

        $upgrade->refresh();
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->status
        );
        $this->assertNotNull($upgrade->legacy_refund_only_at);
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    public function test_stale_signed_paid_upgrade_remains_refund_only_reconcilable(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $target->plan->prices()
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $source->product->upgrades()->attach($target->product->id);
        $service = $this->serviceFor($source);
        $upgrade = $this->signedUpgrade($service, $target);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $invoice->items()->create([
            'description' => 'Stale paid signed upgrade',
            'price' => $upgrade->quoted_amount,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);
        DB::table('invoice_transactions')->insert([
            'invoice_id' => $invoice->id,
            'amount' => $upgrade->quoted_amount,
            'fee' => '0.00',
            'status' => InvoiceTransactionStatus::Processing->value,
            'is_credit_transaction' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('services')
            ->where('id', $service->id)
            ->update(['price' => '11.00']);

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );
        $this->assertNotNull(
            $upgrade->fresh()->legacy_refund_only_at
        );

        $result = app(
            ServiceUpgradeReconciliationService::class
        )->reconcile(
            $upgrade->id,
            ServiceUpgradeReconciliationService::ACTION_REFUNDED_NOT_APPLIED,
            'Payment was independently refunded without application.',
            'admin@example.test'
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $result->status
        );
        $this->assertNotNull($result->source_fingerprint);
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertSame(
            $source->product->id,
            $service->fresh()->product_id
        );
    }

    public function test_unsigned_paid_committed_upgrade_is_never_promoted_to_stock_backfill(): void
    {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $service = $this->serviceFor($source);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
            'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'quoted_amount' => '0.00',
            'credit_amount' => '0.00',
            'currency_code' => 'USD',
            'paid_at' => now(),
        ]);

        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile()
        );

        $upgrade->refresh();
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->status
        );
        $this->assertNotNull($upgrade->legacy_refund_only_at);
        $this->assertNull($upgrade->capacity_mode);
        $this->assertNull($upgrade->target_stock_reserved_at);
    }

    public function test_ambiguous_legacy_payment_rolls_back_every_prior_decision(): void
    {
        [$ambiguous, $ambiguousInvoice] =
            $this->legacyAwaitingFixture(
                paymentEvidence: true,
                ambiguous: true
            );
        [$unpaid, $unpaidInvoice] = $this->legacyAwaitingFixture();
        $migration = $this->outstandingLegacyMigration();

        try {
            $migration->up();
            $this->fail(
                'An ambiguous legacy payment passed migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'ambiguous invoice obligation',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $unpaid->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $unpaidInvoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $ambiguous->fresh()->status
        );
        $this->assertNull(
            $ambiguousInvoice->fresh()
                ->payment_attention_required_at
        );
    }

    public function test_ambiguous_unpaid_legacy_invoice_is_not_cancelled(): void
    {
        [$upgrade, $invoice] = $this->legacyAwaitingFixture(
            ambiguous: true
        );

        try {
            $this->outstandingLegacyMigration()->up();
            $this->fail(
                'An ambiguous unpaid invoice was cancelled by migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'ambiguous invoice obligation',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
    }

    public function test_outstanding_legacy_reconciliation_cannot_be_rolled_back_after_decision(): void
    {
        [$upgrade] = $this->legacyAwaitingFixture();
        $migration = $this->outstandingLegacyMigration();
        $migration->up();

        try {
            $migration->down();
            $this->fail(
                'A terminal legacy migration decision was rolled back.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "service upgrade {$upgrade->id}",
                $exception->getMessage()
            );
        }
    }

    /**
     * @param  list<array{
     *     option: ConfigOption,
     *     config_value_id?: int,
     *     slider_value?: int
     * }>  $configs
     */
    private function signedUpgrade(
        Service $service,
        object $target,
        array $configs = []
    ): ServiceUpgrade {
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
        foreach ($configs as $config) {
            $upgrade->configs()->create([
                'config_option_id' => $config['option']->id,
                'config_value_id' => $config['config_value_id'] ?? null,
                'slider_value' => $config['slider_value'] ?? null,
            ]);
        }
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'service.user.properties',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->quoted_amount =
            $upgrade->signedUpgradePrice()->price;
        $upgrade->credit_amount = $upgrade->signedCreditAmount();
        $upgrade->save();

        return $upgrade->fresh([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'service.user.properties',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
    }

    private function serviceFor(
        object $fixture,
        ?User $user = null
    ): Service {
        $service = Service::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => '10.00',
            'period_base_price' => '10.00',
            'current_period_price' => '10.00',
            'pricing_ledger_started_at' => now()->startOfDay(),
            'pricing_ledger_verified_at' => now(),
            'billing_cycles_completed' => 1,
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);

        return $service->fresh([
            'product.server.settings',
            'product.settings',
            'product.upgrades.configOptions.children.plans.prices',
            'product.upgrades.plans.prices',
            'product.upgradableConfigOptions.children',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
            'user.properties',
            'coupon',
            'currency',
        ]);
    }

    /**
     * @param  list<int>  $products
     * @return array{ConfigOption, ConfigOption}
     */
    private function selectOption(
        string $name,
        string $key,
        bool $upgradable,
        array $products
    ): array {
        $option = ConfigOption::create([
            'name' => $name,
            'env_variable' => $key,
            'type' => 'select',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => $upgradable,
        ]);
        foreach ($products as $productId) {
            DB::table('config_option_products')->insert([
                'product_id' => $productId,
                'config_option_id' => $option->id,
            ]);
        }
        $value = ConfigOption::create([
            'name' => "{$name} value",
            'env_variable' => "{$key}_value",
            'type' => 'select',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
            'parent_id' => $option->id,
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $value->id,
            'priceable_type' => ConfigOption::class,
            'billing_period' => 1,
            'billing_unit' => 'month',
            'type' => 'recurring',
        ]);
        Price::factory()->create([
            'plan_id' => $plan->id,
            'currency_code' => 'USD',
            'price' => '0.00',
        ]);

        return [
            $option->fresh('children.plans.prices'),
            $value->fresh('plans.prices'),
        ];
    }

    private function serviceConfig(
        Service $service,
        ConfigOption $option,
        ?int $configValueId = null,
        ?int $sliderValue = null
    ): ServiceConfig {
        return ServiceConfig::create([
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $option->id,
            'config_value_id' => $configValueId,
            'slider_value' => $sliderValue,
        ]);
    }

    /**
     * @return array{Server, object, object, Service, Property}
     */
    private function enhanceFixture(): array
    {
        $server = Server::create([
            'name' => 'Enhance',
            'extension' => 'Enhance',
            'type' => 'server',
            'enabled' => true,
        ]);
        foreach ([
            'host' => 'https://enhance.example',
            'apikey' => 'secret',
            'orgId' => 'provider-root',
        ] as $key => $value) {
            $server->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }
        $source = $this->createProduct([
            'server_id' => $server->id,
            'stock' => null,
        ]);
        $target = $this->createProduct([
            'server_id' => $server->id,
            'stock' => null,
        ]);
        $source->product->upgrades()->attach($target->product->id);
        foreach ([
            [$source->product, 100],
            [$target->product, 200],
        ] as [$product, $planId]) {
            $product->settings()->create([
                'key' => 'plan',
                'value' => (string) $planId,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }
        $user = User::factory()->create();
        $organization = $user->properties()->create([
            'key' => 'enhance_orgId',
            'name' => 'Enhance organization',
            'value' => 'enhance-org-1',
        ]);
        $service = $this->serviceFor($source, $user);
        $service->properties()->create([
            'key' => 'subscription_id',
            'name' => 'Enhance subscription',
            'value' => 'subscription-1',
        ]);

        return [
            $server->fresh('settings'),
            $source,
            $target,
            $service->fresh([
                'product.server.settings',
                'product.settings',
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
                'user.properties',
                'coupon',
                'currency',
            ]),
            $organization,
        ];
    }

    /**
     * @return array{ServiceUpgrade, Invoice}
     */
    private function legacyAwaitingFixture(
        bool $paymentEvidence = false,
        bool $ambiguous = false
    ): array {
        $source = $this->createProduct();
        $target = $this->createProduct();
        $service = $this->serviceFor($source);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'quoted_amount' => '5.00',
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'description' => 'Unsigned legacy upgrade',
            'price' => '5.00',
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        if ($ambiguous) {
            $invoice->items()->create([
                'description' => 'Ambiguous extra line',
                'price' => '1.00',
                'quantity' => 1,
            ]);
        }
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);
        if ($paymentEvidence) {
            DB::table('invoice_transactions')->insert([
                'invoice_id' => $invoice->id,
                'amount' => '5.00',
                'fee' => '0.00',
                'status' => InvoiceTransactionStatus::Processing->value,
                'is_credit_transaction' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$upgrade, $invoice];
    }

    private function authenticate(User $user): void
    {
        $this->actingAs($user);
        session($this->loginUser($user));
    }

    private function outstandingLegacyMigration(): object
    {
        return require database_path(
            'migrations/2026_07_27_000165_reconcile_outstanding_legacy_service_upgrades.php'
        );
    }
}
