<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaticUpgradeStockMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_migration_is_installed_before_backfill(): void
    {
        foreach ([
            'capacity_mode',
            'target_stock_reserved_quantity',
            'target_stock_reserved_at',
            'target_stock_released_at',
            'target_stock_consumed_at',
            'target_stock_fingerprint',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('service_upgrades', $column),
                "Missing static stock schema column {$column}."
            );
        }
    }

    public function test_existing_paid_upgrade_reserves_target_stock_once(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(2);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            1,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
            'target_stock_reserved_quantity' => 1,
        ]);
        $this->assertNotNull(
            DB::table('service_upgrades')
                ->where('id', $upgradeId)
                ->value('target_stock_reserved_at')
        );
        $this->assertSame(
            64,
            strlen((string) DB::table('service_upgrades')
                ->where('id', $upgradeId)
                ->value('target_stock_fingerprint'))
        );
    }

    public function test_existing_signed_awaiting_payment_quote_reserves_target_stock(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(1);
        DB::table('prices')
            ->where('plan_id', $targetPlanId)
            ->where('currency_code', 'USD')
            ->update(['price' => '20.00']);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );
        $upgrade = ServiceUpgrade::query()->findOrFail($upgradeId);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(3),
        ]);
        $invoice->items()->create([
            'description' => 'Existing signed static upgrade',
            'price' => $upgrade->quoted_amount,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $upgrade->forceFill([
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'paid_at' => null,
        ]);
        ServiceUpgradeMutationCoordinator::save($upgrade);

        $this->migration()->up();

        $this->assertSame(
            0,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
            'target_stock_reserved_quantity' => 1,
        ]);
    }

    public function test_failed_backfill_rolls_back_and_can_be_rerun(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(0);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );

        try {
            $this->migration()->up();
            $this->fail(
                'Insufficient target stock must block the backfill.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'outstanding upgrades require 1',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => null,
            'target_stock_reserved_at' => null,
        ]);
        $this->assertSame(
            0,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );

        DB::table('products')
            ->where('id', $targetProductId)
            ->update(['stock' => 1]);
        $this->migration()->up();

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
            'target_stock_reserved_quantity' => 1,
        ]);
        $this->assertSame(
            0,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_quantity_two_upgrade_rolls_back_without_mutation(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(2);
        DB::table('services')
            ->where('id', $service->id)
            ->update(['quantity' => 2]);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId,
            signed: false
        );

        try {
            $this->migration()->up();
            $this->fail(
                'A quantity-two legacy upgrade passed stock migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "Service upgrade {$upgradeId} belongs to a quantity-2 service",
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => null,
            'target_stock_reserved_at' => null,
        ]);
        $this->assertSame(
            2,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_visible_dynamic_product_is_not_misclassified_as_static(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(2);
        $this->attachDynamicSlider($targetProductId, hidden: false);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId,
            signed: false
        );

        $this->migration()->up();

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_DYNAMIC,
            'target_stock_reserved_at' => null,
        ]);
        $this->assertSame(
            2,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_hidden_unsigned_legacy_upgrade_cannot_acquire_static_stock(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(2);
        $this->attachDynamicSlider($targetProductId, hidden: true);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId,
            signed: false
        );

        try {
            $this->migration()->up();
            $this->fail(
                'An unsigned hidden-slider upgrade acquired static stock.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'no authentic signed quote',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => null,
            'target_stock_reserved_quantity' => null,
        ]);
        $this->assertSame(
            2,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_backfill_rollback_refuses_durable_stock_evidence(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(1);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );
        $migration = $this->migration();
        $migration->up();

        try {
            $migration->down();
            $this->fail(
                'A migration rollback discarded target-stock evidence.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "upgrade {$upgradeId} has durable reservation",
                $exception->getMessage()
            );
        }
    }

    public function test_rerun_rejects_tampered_existing_stock_evidence(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(1);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );
        $migration = $this->migration();
        $migration->up();
        DB::table('service_upgrades')
            ->where('id', $upgradeId)
            ->update([
                'target_stock_fingerprint' => str_repeat('0', 64),
            ]);

        try {
            $migration->up();
            $this->fail(
                'A tampered target-stock ownership proof passed a rerun.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "upgrade {$upgradeId} has no valid active ownership proof",
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_needs_attention_row_cannot_reserve_stock_for_a_target_outside_its_signed_identity(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(1);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );
        DB::table('service_upgrades')
            ->where('id', $upgradeId)
            ->update([
                'status' => ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                'plan_id' => $service->plan_id,
            ]);

        try {
            $this->migration()->up();
            $this->fail(
                'A mutable target identity acquired static stock.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "Active static upgrade {$upgradeId} no longer matches its signed",
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => null,
            'target_stock_reserved_at' => null,
        ]);
        $this->assertSame(
            1,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    public function test_explicit_static_ownership_is_not_rewritten_as_dynamic(): void
    {
        [$service, $targetProductId, $targetPlanId] =
            $this->legacyFixture(1);
        $upgradeId = $this->insertLegacyUpgrade(
            $service,
            $targetProductId,
            $targetPlanId
        );
        $migration = $this->migration();
        $migration->up();
        $this->attachDynamicSlider($targetProductId, hidden: false);

        try {
            $migration->up();
            $this->fail(
                'Static stock evidence was reclassified as dynamic.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "Active static upgrade {$upgradeId} conflicts with dynamic capacity",
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('service_upgrades', [
            'id' => $upgradeId,
            'capacity_mode' => ServiceUpgrade::CAPACITY_MODE_STATIC,
            'target_stock_reserved_quantity' => 1,
        ]);
        $this->assertSame(
            0,
            (int) DB::table('products')
                ->where('id', $targetProductId)
                ->value('stock')
        );
    }

    /**
     * @return array{Service, int, int}
     */
    private function legacyFixture(?int $targetStock): array
    {
        $source = $this->createProduct(['stock' => 0]);
        $target = $this->createProduct(['stock' => $targetStock]);
        $source->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $source->product->id,
            'plan_id' => $source->plan->id,
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

        return [
            $service,
            (int) $target->product->id,
            (int) $target->plan->id,
        ];
    }

    private function insertLegacyUpgrade(
        Service $service,
        int $targetProductId,
        int $targetPlanId,
        bool $signed = true
    ): int {
        if (!$signed) {
            return DB::table('service_upgrades')->insertGetId([
                'service_id' => $service->id,
                'plan_id' => $targetPlanId,
                'product_id' => $targetProductId,
                'invoice_id' => null,
                'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
                'type' => 'product',
                'source_snapshot' => null,
                'target_snapshot' => null,
                'source_fingerprint' => null,
                'target_fingerprint' => null,
                'quoted_amount' => '5.00',
                'currency_code' => 'USD',
                'credit_amount' => '0.00',
                'active_service_guard_id' => $service->id,
                'provisioning_attempts' => 0,
                'paid_at' => now(),
                'capacity_mode' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'plan_id' => $targetPlanId,
            'product_id' => $targetProductId,
            'invoice_id' => null,
            'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
            'type' => 'product',
            'currency_code' => 'USD',
            'active_service_guard_id' => $service->id,
            'paid_at' => now(),
            'capacity_mode' => null,
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
        $upgrade->quoted_amount =
            $upgrade->signedUpgradePrice()->price;
        $upgrade->credit_amount = $upgrade->signedCreditAmount();
        $upgrade->save();

        return (int) $upgrade->id;
    }

    private function attachDynamicSlider(
        int $productId,
        bool $hidden
    ): void {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        DB::table('products')
            ->where('id', $productId)
            ->update(['server_id' => $server->id]);
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => $hidden,
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
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $productId,
        ]);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_27_000171_backfill_static_upgrade_stock.php'
        );
    }
}
