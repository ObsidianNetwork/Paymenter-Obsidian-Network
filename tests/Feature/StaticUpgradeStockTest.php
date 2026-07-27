<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Upgrade as UpgradeComponent;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\ServiceUpgradeReconciliation;
use App\Models\User;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ProductStockService;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeReconciliationService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\StaticUpgradeStockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class StaticUpgradeStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoked_product_relationship_is_rejected_under_lock(): void
    {
        [$service, , $target] = $this->upgradeFixture();
        $stock = app(StaticUpgradeStockService::class);

        DB::transaction(
            fn () => $stock->assertProductChangeAuthorized(
                $service,
                $target->product
            )
        );
        // Simulate an already-corrupt/revoked authorization at the exact
        // reservation boundary. Normal model writes are independently guarded
        // from removing this pivot while the active quote exists.
        DB::table('product_upgrades')
            ->where('product_id', $service->product_id)
            ->where('upgrade_id', $target->product->id)
            ->delete();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('no longer an authorized upgrade');

        DB::transaction(
            fn () => $stock->assertProductChangeAuthorized(
                $service,
                $target->product
            )
        );
    }

    public function test_only_one_upgrade_can_reserve_the_last_target_unit(): void
    {
        [$firstService, $firstUpgrade, $target] =
            $this->upgradeFixture(targetStock: 1);
        [$secondService, $secondUpgrade] = $this->upgradeFixture(
            target: $target,
            targetStock: 1
        );
        $stock = app(StaticUpgradeStockService::class);

        DB::transaction(
            fn () => $stock->reserve($firstUpgrade, $firstService)
        );

        try {
            DB::transaction(
                fn () => $stock->reserve(
                    $secondUpgrade,
                    $secondService
                )
            );
            $this->fail('A second upgrade reserved unavailable stock.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'out of stock',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertNotNull(
            $firstUpgrade->fresh()->target_stock_reserved_at
        );
        $this->assertNull(
            $secondUpgrade->fresh()->target_stock_reserved_at
        );
    }

    public function test_unpaid_upgrade_cancellation_releases_stock_without_cancelling_service(): void
    {
        [$service, $upgrade, $target] =
            $this->upgradeFixture(targetStock: 1);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
        ]);
        $invoice->items()->create([
            'description' => 'Static product upgrade',
            'price' => 5,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        app(CancelInvoiceService::class)->handle($invoice);

        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertSame(1, $target->product->fresh()->stock);
        $this->assertNotNull(
            $upgrade->fresh()->target_stock_released_at
        );
    }

    public function test_completion_consumes_reserved_target_once_and_returns_source_stock(): void
    {
        [$service, $upgrade, $target] = $this->upgradeFixture(
            sourceStock: 0,
            targetStock: 1
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
            'provisioning_attempts' => 1,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        app(ServiceUpgradeService::class)->complete($upgrade);
        app(ServiceUpgradeService::class)->complete($upgrade->fresh());

        $this->assertSame(
            $target->product->id,
            $service->fresh()->product_id
        );
        $this->assertSame(1, $service->product->fresh()->stock);
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertNotNull(
            $upgrade->fresh()->target_stock_consumed_at
        );
    }

    public function test_predeployment_pending_upgrade_reserves_before_paid_commit(): void
    {
        Queue::fake();
        [$service, $upgrade, $target] =
            $this->upgradeFixture(targetStock: 1);

        app(ServiceUpgradeService::class)
            ->markPaidCommitted($upgrade);

        $this->assertSame(
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            $upgrade->fresh()->status
        );
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertNotNull(
            $upgrade->fresh()->target_stock_reserved_at
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
    }

    public function test_positive_price_upgrade_atomically_creates_line_binding_and_stock_hold(): void
    {
        $source = $this->createProduct(['stock' => 0]);
        $target = $this->createProduct(['stock' => 2]);
        $source->product->upgrades()->attach($target->product->id);
        $target->plan->prices()
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => '10.00',
            'expires_at' => now()->addMonth(),
        ]);
        $user = $service->user;
        $this->actingAs($user);
        session($this->loginUser($user));

        Livewire::test(UpgradeComponent::class, [
            'service' => $service->fresh(),
        ])
            ->set('upgrade', $target->product->id)
            ->call('nextStep')
            ->call('doUpgrade')
            ->assertHasNoErrors();

        $upgrade = ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->latest('id')
            ->firstOrFail();
        $invoice = $upgrade->invoice()->firstOrFail();

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->status
        );
        $this->assertSame(1, $invoice->items()->count());
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
        ]);
        $this->assertNotNull($upgrade->target_stock_reserved_at);
        $this->assertSame(1, $target->product->fresh()->stock);
    }

    public function test_late_static_upgrade_payment_requires_attention_without_consuming_hold(): void
    {
        [$service, $upgrade, $target] =
            $this->upgradeFixture(targetStock: 1);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->subSecond(),
        ]);
        $invoice->items()->create([
            'description' => 'Expiring static upgrade',
            'price' => '5.00',
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        ExtensionHelper::addPayment(
            $invoice,
            null,
            '5.00',
            transactionId: 'late-static-upgrade'
        );

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'capacity guarantee deadline',
            (string) $invoice->fresh()->payment_attention_reason
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertNull(
            $upgrade->fresh()->target_stock_consumed_at
        );
    }

    public function test_quantity_greater_than_one_cannot_reserve_upgrade_stock(): void
    {
        [$service, $upgrade, $target] =
            $this->upgradeFixture(targetStock: 2, quantity: 2);

        try {
            DB::transaction(
                fn () => app(StaticUpgradeStockService::class)
                    ->reserve($upgrade, $service)
            );
            $this->fail(
                'A quantity-two service reserved mismatched upgrade stock.'
            );
        } catch (DisplayException $exception) {
            $this->assertStringContainsString(
                'quantity of one',
                $exception->getMessage()
            );
        }

        $this->assertSame(2, $target->product->fresh()->stock);
        $this->assertNull(
            $upgrade->fresh()->target_stock_reserved_at
        );
    }

    public function test_dynamic_resource_activation_cannot_reclassify_active_static_hold(): void
    {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $target = $this->createProduct([
            'stock' => 1,
            'server_id' => $server->id,
        ]);
        [$service, $upgrade] = $this->upgradeFixture(
            target: $target
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1024,
                'max' => 8192,
                'step' => 1024,
                'default' => 1024,
                'display_divisor' => 1024,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 0,
                ],
            ],
        ]);

        try {
            $option->products()->attach($target->product->id);
            $this->fail(
                'A dynamic option reclassified an active static hold.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'active service upgrade',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            $option->products()
                ->whereKey($target->product->id)
                ->exists()
        );
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertNotNull(
            $upgrade->fresh()->target_stock_reserved_at
        );
    }

    public function test_upgrade_and_invoice_cannot_be_deleted_around_reserved_stock(): void
    {
        [$service, $upgrade] = $this->upgradeFixture(targetStock: 1);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        try {
            $upgrade->delete();
            $this->fail('The active stock reservation was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment history cannot be deleted',
                $exception->getMessage()
            );
        }

        try {
            DB::table('invoices')
                ->where('id', $invoice->id)
                ->delete();
            $this->fail(
                'Raw invoice deletion cascaded through reserved upgrade stock.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgrade->id,
            'invoice_id' => $invoice->id,
        ]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_cancelled_upgrade_history_cannot_be_deleted(): void
    {
        [, $upgrade] = $this->upgradeFixture();
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_CANCELLED,
            'active_service_guard_id' => null,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Upgrade fulfillment history cannot be deleted'
        );

        $upgrade->delete();
    }

    public function test_stale_admin_stock_edit_cannot_overwrite_a_reservation(): void
    {
        [, , $target] = $this->upgradeFixture(targetStock: 2);
        $staleProduct = $target->product->fresh();
        DB::table('products')
            ->where('id', $target->product->id)
            ->update(['stock' => 1]);

        try {
            DB::transaction(function () use ($staleProduct): void {
                $staleProduct->stock = 10;
                $staleProduct->save();
            });
            $this->fail('A stale product edit overwrote current stock.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'stock changed after this edit was opened',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $target->product->fresh()->stock);
    }

    public function test_active_upgrade_blocks_target_product_deletion(): void
    {
        [, $upgrade, $target] = $this->upgradeFixture();

        try {
            $target->product->delete();
            $this->fail(
                'A target product required by an active upgrade was deleted.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'unresolved or active capacity commitment',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('products', [
            'id' => $target->product->id,
        ]);
        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgrade->id,
        ]);
    }

    public function test_active_upgrade_blocks_finite_unlimited_stock_mode_changes(): void
    {
        [$service, $upgrade, $target] = $this->upgradeFixture(
            sourceStock: 1,
            targetStock: 1
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );

        foreach ([$service->product, $target->product] as $product) {
            try {
                DB::transaction(function () use ($product): void {
                    $product = $product->fresh();
                    $product->stock = null;
                    $product->save();
                });
                $this->fail(
                    'An active upgrade allowed a product stock mode change.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'stock',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_exact_paid_replay_after_deadline_remains_idempotent(): void
    {
        Queue::fake();
        [$service, $upgrade] = $this->upgradeFixture(
            targetStock: 1,
            quotedAmount: 5
        );
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addMinute(),
        ]);
        $invoice->items()->create([
            'description' => 'Deadline replay upgrade',
            'price' => '5.00',
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);

        ExtensionHelper::addPayment(
            $invoice,
            null,
            '5.00',
            transactionId: 'static-deadline-replay'
        );
        $this->assertSame(
            Invoice::STATUS_PAID,
            $invoice->fresh()->status
        );

        $this->travelTo($invoice->due_at->copy()->addSecond());
        ExtensionHelper::addPayment(
            $invoice->fresh(),
            null,
            '5.00',
            transactionId: 'static-deadline-replay'
        );

        $this->assertSame(
            Invoice::STATUS_PAID,
            $invoice->fresh()->status
        );
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            $upgrade->fresh()->status
        );
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_first_stock_claim_cannot_be_forged_by_model_save(): void
    {
        [, $upgrade] = $this->upgradeFixture();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'stock ownership can only be changed'
        );

        $upgrade->forceFill([
            'target_stock_reserved_quantity' => 1,
            'target_stock_reserved_at' => now(),
            'target_stock_fingerprint' => str_repeat('a', 64),
        ])->save();
    }

    public function test_tampered_target_snapshot_fails_before_provisioning(): void
    {
        [$service, $upgrade] = $this->upgradeFixture();
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $snapshot = $upgrade->fresh()->target_snapshot;
        $snapshot['recurring_price'] = '0.01';
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update(['target_snapshot' => json_encode($snapshot)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signed target identity');

        app(StaticUpgradeStockService::class)->assertReserved(
            $upgrade->fresh(),
            $service->fresh()
        );
    }

    public function test_same_product_upgrade_authenticates_snapshot(): void
    {
        $fixture = $this->createProduct(['stock' => null]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
            'quoted_amount' => 0,
            'currency_code' => 'USD',
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
        ]);
        $upgrade->load([
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'service.user',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();
        $snapshot = $upgrade->target_snapshot;
        $snapshot['recurring_price'] = '99.99';
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update(['target_snapshot' => json_encode($snapshot)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signed target identity');

        app(StaticUpgradeStockService::class)->assertReserved(
            $upgrade->fresh(),
            $service->fresh()
        );
    }

    public function test_active_upgrade_blocks_owner_transfer(): void
    {
        [$service] = $this->upgradeFixture();
        $service->user_id = User::factory()->create()->id;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot change owner');

        $service->save();
    }

    public function test_direct_static_status_transitions_are_blocked(): void
    {
        [$service] = $this->upgradeFixture(sourceStock: 0);
        $service->status = Service::STATUS_CANCELLED;
        try {
            $service->save();
            $this->fail('A direct active-to-cancelled transition succeeded.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment state machine',
                $exception->getMessage()
            );
        }

        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service): void {
                $service->status = Service::STATUS_CANCELLED;
                $service->save();
            }
        );
        app(ProductStockService::class)->release($service);
        $this->assertSame(1, $service->product->fresh()->stock);

        $service = $service->fresh();
        $service->status = Service::STATUS_ACTIVE;
        try {
            $service->save();
            $this->fail('A cancelled service was reactivated directly.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment state machine',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $service->product->fresh()->stock);
    }

    public function test_service_hard_delete_cannot_discard_stock_history(): void
    {
        [$service] = $this->upgradeFixture(sourceStock: 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be hard deleted');

        $service->delete();
    }

    public function test_active_static_service_blocks_stock_mode_changes(): void
    {
        foreach ([null, 0] as $initialStock) {
            $fixture = $this->createProduct([
                'stock' => $initialStock,
            ]);
            Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $fixture->product->id,
                'plan_id' => $fixture->plan->id,
                'status' => Service::STATUS_ACTIVE,
                'quantity' => 1,
                'currency_code' => 'USD',
                'price' => '10.00',
            ]);

            try {
                DB::transaction(function () use (
                    $fixture,
                    $initialStock
                ): void {
                    $product = $fixture->product->fresh();
                    $product->stock = $initialStock === null
                        ? 10
                        : null;
                    $product->save();
                });
                $this->fail(
                    'An active service allowed its stock mode to change.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'unreleased stock claim',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_cross_product_completion_removes_obsolete_configs(): void
    {
        [$service, $upgrade] = $this->upgradeFixture();
        $obsolete = ConfigOption::create([
            'name' => 'Legacy option',
            'env_variable' => 'legacy_option',
            'type' => 'text',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
        ]);
        ServiceConfig::create([
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $obsolete->id,
            'config_value_id' => null,
            'slider_value' => null,
        ]);
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_PROVISIONING,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        app(ServiceUpgradeService::class)->complete($upgrade);

        $this->assertDatabaseMissing('service_configs', [
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $obsolete->id,
        ]);
    }

    public function test_operator_retry_is_append_only_and_idempotent(): void
    {
        Queue::fake();
        [$service, $upgrade] = $this->upgradeFixture();
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            'last_error' => 'Indeterminate remote response.',
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);
        $reconciliation = app(
            ServiceUpgradeReconciliationService::class
        );

        $first = $reconciliation->reconcile(
            $upgrade->id,
            ServiceUpgradeReconciliationService::ACTION_RETRY,
            'Operator verified that retry is safe.',
            'admin@example.test'
        );
        $second = $reconciliation->reconcile(
            $upgrade->id,
            ServiceUpgradeReconciliationService::ACTION_RETRY,
            'Operator verified that retry is safe.',
            'admin@example.test'
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            $first->status
        );
        $this->assertSame($first->status, $second->status);
        $this->assertSame(
            1,
            ServiceUpgradeReconciliation::query()->count()
        );
        $record = ServiceUpgradeReconciliation::query()->firstOrFail();
        try {
            $record->reason = 'Rewritten evidence';
            $record->save();
            $this->fail('Reconciliation evidence was rewritten.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'append-only',
                $exception->getMessage()
            );
        }

        $migration = require database_path(
            'migrations/2026_07_27_000172_create_service_upgrade_reconciliations.php'
        );
        try {
            $migration->down();
            $this->fail('Reconciliation evidence was dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'append-only',
                $exception->getMessage()
            );
        }
    }

    public function test_operator_can_attest_completion_or_refund_exactly_once(): void
    {
        [$service, $upgrade, $target] = $this->upgradeFixture();
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        $completed = app(
            ServiceUpgradeReconciliationService::class
        )->reconcile(
            $upgrade->id,
            ServiceUpgradeReconciliationService::ACTION_ATTEST_COMPLETED,
            'Panel target state was independently verified.',
            'admin@example.test'
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_COMPLETED,
            $completed->status
        );
        $this->assertSame(
            $target->product->id,
            $service->fresh()->product_id
        );
        $this->assertNotNull(
            $completed->target_stock_consumed_at
        );

        [$refundService, $refundUpgrade, $refundTarget] =
            $this->upgradeFixture();
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($refundUpgrade, $refundService)
        );
        $refundUpgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
        ]);
        ServiceUpgradeMutationCoordinator::save($refundUpgrade);
        $refunded = app(
            ServiceUpgradeReconciliationService::class
        )->reconcile(
            $refundUpgrade->id,
            ServiceUpgradeReconciliationService::ACTION_REFUNDED_NOT_APPLIED,
            'Refund verified and panel target was not applied.',
            'admin@example.test'
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $refunded->status
        );
        $this->assertNotNull(
            $refunded->target_stock_released_at
        );
        $this->assertSame(1, $refundTarget->product->fresh()->stock);
    }

    public function test_reconciliation_cannot_clear_a_missing_stock_hold(): void
    {
        [$service, $upgrade] = $this->upgradeFixture();
        DB::transaction(
            fn () => app(StaticUpgradeStockService::class)
                ->reserve($upgrade, $service)
        );
        $upgrade->forceFill([
            'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'target_stock_reserved_at' => null,
                'target_stock_fingerprint' => null,
            ]);

        try {
            app(ServiceUpgradeReconciliationService::class)
                ->reconcile(
                    $upgrade->id,
                    ServiceUpgradeReconciliationService::ACTION_REFUNDED_NOT_APPLIED,
                    'Refund verified but ownership is corrupt.',
                    'admin@example.test'
                );
            $this->fail('Corrupt stock ownership was cleared.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'active target product stock reservation',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
    }

    private function upgradeFixture(
        int $sourceStock = 0,
        int $targetStock = 1,
        ?object $target = null,
        int $quantity = 1,
        float $quotedAmount = 0
    ): array {
        $source = $this->createProduct(['stock' => $sourceStock]);
        $target ??= $this->createProduct(['stock' => $targetStock]);
        $source->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => $quantity,
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
            'quoted_amount' => $quotedAmount,
            'currency_code' => 'USD',
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
        ]);
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
            'service.configs.configOption',
            'service.configs.configValue',
            'service.user',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();

        return [
            $service->fresh(['product', 'plan']),
            $upgrade->fresh([
                'service.product.server.settings',
                'service.product.settings',
                'service.plan.prices',
                'service.configs.configOption',
                'service.configs.configValue',
                'service.user',
                'product.server.settings',
                'product.settings',
                'plan.prices',
                'configs.configOption',
                'configs.configValue',
            ]),
            $target,
        ];
    }
}
