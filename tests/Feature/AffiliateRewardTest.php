<?php

namespace Tests\Feature;

use App\Events\Invoice\Paid;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Extension as ExtensionModel;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateReward;
use Paymenter\Extensions\Others\Affiliates\Services\AffiliateRewardLedgerMigration;
use Tests\TestCase;

class AffiliateRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        app('migrator')->path(base_path(
            'extensions/Others/Affiliates/database/migrations'
        ));

        if (!Schema::hasTable('ext_affiliate_rewards')) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_paid_invoice_reward_is_durable_and_idempotent(): void
    {
        [$invoice, $affiliate, $referral] = $this->referralInvoice(
            '10.05',
            10
        );
        $listener = app(RewardAffiliate::class);

        $listener->handle(new Paid($invoice));
        $affiliate->update(['reward' => 50]);
        $listener->handle(new Paid($invoice->fresh()));

        $this->assertSame(1, AffiliateReward::query()->count());
        $this->assertDatabaseHas('ext_affiliate_rewards', [
            'invoice_id' => $invoice->id,
            'affiliate_id' => $affiliate->id,
            'user_id' => $affiliate->user_id,
            'currency_code' => 'USD',
            'reward_percentage' => 10,
            'amount' => 1.01,
        ]);
        $this->assertSame(
            '1.01',
            $this->money(Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->sole()
                ->amount)
        );
        $this->assertSame(
            ['US Dollar' => 1.01],
            $affiliate->fresh()->earnings
        );
        $this->assertSame(
            ['US Dollar' => 1.01],
            $referral->fresh()->earnings
        );

        $reward = AffiliateReward::query()->sole();
        try {
            $reward->update(['amount' => '99.00']);
            $this->fail('Affiliate reward evidence was mutable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Affiliate reward evidence is immutable.',
                $exception->getMessage()
            );
        }
        $this->assertSame('1.01', $reward->fresh()->amount);
    }

    public function test_interleaved_invoice_events_share_one_exact_balance(): void
    {
        [$firstInvoice, $affiliate] = $this->referralInvoice(
            '10.10',
            10
        );
        [$secondInvoice] = $this->referralInvoice(
            '20.20',
            10,
            $affiliate
        );
        $listener = app(RewardAffiliate::class);

        $listener->handle(new Paid($firstInvoice));
        $listener->handle(new Paid($secondInvoice));
        $listener->handle(new Paid($firstInvoice->fresh()));
        $listener->handle(new Paid($secondInvoice->fresh()));

        $this->assertSame(2, AffiliateReward::query()->count());
        $this->assertSame(
            1,
            Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->count()
        );
        $this->assertSame(
            '3.03',
            $this->money(Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->sole()
                ->amount)
        );
        $this->assertSame(
            ['US Dollar' => 3.03],
            $affiliate->fresh()->earnings
        );
    }

    public function test_explicit_zero_reward_is_snapshotted_without_credit(): void
    {
        [$invoice, $affiliate] = $this->referralInvoice('10.00', 0);
        $listener = app(RewardAffiliate::class);

        $listener->handle(new Paid($invoice));
        $affiliate->update(['reward' => 50]);
        $listener->handle(new Paid($invoice->fresh()));

        $this->assertDatabaseHas('ext_affiliate_rewards', [
            'invoice_id' => $invoice->id,
            'affiliate_id' => $affiliate->id,
            'reward_percentage' => 0,
            'amount' => 0,
        ]);
        $this->assertSame(
            0,
            Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->count()
        );
        $this->assertSame(
            ['US Dollar' => 0.0],
            $affiliate->fresh()->earnings
        );
    }

    public function test_credit_failure_rolls_back_reward_evidence(): void
    {
        [$invoice, $affiliate] = $this->referralInvoice('10.00', 10);
        Credit::query()->create([
            'user_id' => $affiliate->user_id,
            'currency_code' => 'USD',
            'amount' => '999999999999999.00',
        ]);

        try {
            app(RewardAffiliate::class)->handle(new Paid($invoice));
            $this->fail('An overflowing affiliate reward was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'exceeds DECIMAL(17,2)',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('ext_affiliate_rewards', [
            'invoice_id' => $invoice->id,
        ]);
        $this->assertSame(
            '999999999999999.00',
            $this->money(Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->sole()
                ->amount)
        );
    }

    public function test_migration_backfills_without_double_crediting(): void
    {
        [$invoice, $affiliate, $referral] = $this->referralInvoice(
            '10.05',
            10
        );
        Credit::query()->create([
            'user_id' => $affiliate->user_id,
            'currency_code' => 'USD',
            'amount' => '1.01',
        ]);

        try {
            AffiliateRewardLedgerMigration::assertReady();
            $this->fail(
                'Migration readiness accepted missing historical evidence.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "invoice {$invoice->id}",
                $exception->getMessage()
            );
        }

        AffiliateRewardLedgerMigration::backfill();
        AffiliateRewardLedgerMigration::assertReady();
        app(RewardAffiliate::class)->handle(new Paid($invoice->fresh()));

        $this->assertSame(1, AffiliateReward::query()->count());
        $this->assertDatabaseHas('ext_affiliate_rewards', [
            'invoice_id' => $invoice->id,
            'affiliate_order_id' => $referral->id,
            'reward_percentage' => 10,
            'amount' => 1.01,
        ]);
        $this->assertSame(
            '1.01',
            $this->money(Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->sole()
                ->amount)
        );
        $this->assertSame(
            ['US Dollar' => 1.01],
            $affiliate->fresh()->earnings
        );
    }

    public function test_backfill_preserves_legacy_zero_reward_fallback(): void
    {
        [$invoice, $affiliate] = $this->referralInvoice('10.00', 0);
        $this->configureDefaultReward(10);
        Credit::query()->create([
            'user_id' => $affiliate->user_id,
            'currency_code' => 'USD',
            'amount' => '1.00',
        ]);

        AffiliateRewardLedgerMigration::backfill();
        app(RewardAffiliate::class)->handle(new Paid($invoice->fresh()));

        $this->assertDatabaseHas('ext_affiliate_rewards', [
            'invoice_id' => $invoice->id,
            'affiliate_id' => $affiliate->id,
            'reward_percentage' => 10,
            'amount' => 1.00,
        ]);
        $this->assertSame(
            '1.00',
            $this->money(Credit::query()
                ->where('user_id', $affiliate->user_id)
                ->where('currency_code', 'USD')
                ->sole()
                ->amount)
        );
        $this->assertSame(
            ['US Dollar' => 1.0],
            $affiliate->fresh()->earnings
        );
    }

    public function test_core_and_extension_upgrade_paths_share_readiness(): void
    {
        $coreMigration = file_get_contents(database_path(
            'migrations/2026_07_27_000150_create_affiliate_reward_evidence.php'
        ));
        $extensionMigration = file_get_contents(base_path(
            'extensions/Others/Affiliates/database/migrations/'
            . '2026_07_27_000001_create_ext_affiliate_rewards_table.php'
        ));
        $extension = file_get_contents(base_path(
            'extensions/Others/Affiliates/Affiliates.php'
        ));

        $this->assertStringContainsString(
            'AffiliateRewardLedgerMigration::migrate();',
            $coreMigration
        );
        $this->assertStringContainsString(
            'AffiliateRewardLedgerMigration::migrate();',
            $extensionMigration
        );
        $this->assertStringContainsString(
            'public function upgraded(',
            $extension
        );
        $this->assertStringContainsString(
            'runMigrationsOrFail(',
            $extension
        );
    }

    public function test_extension_rollback_runs_child_migrations_first(): void
    {
        $relativePath = 'storage/framework/testing/affiliate-rollback-'
            . bin2hex(random_bytes(6));
        $migrationPath = base_path($relativePath);
        File::ensureDirectoryExists($migrationPath);
        $migrations = [
            '2024_12_25_075634_fixture_affiliates',
            '2025_01_31_155928_fixture_affiliate_orders',
            '2026_07_27_000001_fixture_affiliate_rewards',
        ];
        AffiliateMigrationRollbackRecorder::$order = [];

        try {
            foreach ($migrations as $migration) {
                File::put(
                    "{$migrationPath}/{$migration}.php",
                    "<?php\n\nreturn new class\n{\n"
                    . "    public function down(): void\n    {\n"
                    . "        \\Tests\\Feature\\AffiliateMigrationRollbackRecorder::record('{$migration}');\n"
                    . "    }\n};\n"
                );
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => 999,
                ]);
            }

            ExtensionHelper::rollbackMigrations($relativePath);

            $this->assertSame(
                array_reverse($migrations),
                AffiliateMigrationRollbackRecorder::$order
            );
            foreach ($migrations as $migration) {
                $this->assertDatabaseMissing('migrations', [
                    'migration' => $migration,
                ]);
            }
        } finally {
            DB::table('migrations')
                ->whereIn('migration', $migrations)
                ->delete();
            File::deleteDirectory($migrationPath);
        }
    }

    public function test_reward_evidence_has_database_unique_invoice_guard(): void
    {
        [$invoice, $affiliate, $referral] = $this->referralInvoice(
            '10.00',
            10
        );
        $attributes = [
            'invoice_id' => $invoice->id,
            'affiliate_order_id' => $referral->id,
            'affiliate_id' => $affiliate->id,
            'user_id' => $affiliate->user_id,
            'currency_code' => 'USD',
            'reward_percentage' => 10,
            'amount' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('ext_affiliate_rewards')->insert($attributes);

        try {
            DB::table('ext_affiliate_rewards')->insert($attributes);
            $this->fail(
                'Expected duplicate affiliate reward evidence to be rejected.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, AffiliateReward::query()->count());
    }

    public function test_listener_uses_the_shared_lock_order(): void
    {
        $source = file_get_contents(
            base_path(
                'extensions/Others/Affiliates/Listeners/RewardAffiliate.php'
            )
        );
        $markers = [
            '$invoice = Invoice::query()',
            '$service = Service::query()',
            '$affiliate = Affiliate::query()',
            '$referral = AffiliateOrder::query()',
            '$this->creditPayments->addBalance(',
        ];
        $lastPosition = -1;

        foreach ($markers as $marker) {
            $position = strpos($source, $marker);
            $this->assertNotFalse(
                $position,
                "Missing affiliate lock-order marker: {$marker}"
            );
            $this->assertGreaterThan(
                $lastPosition,
                $position,
                "Affiliate lock-order marker is out of order: {$marker}"
            );
            $lastPosition = $position;
        }
    }

    /**
     * @return array{Invoice, Affiliate, AffiliateOrder}
     */
    private function referralInvoice(
        string $amount,
        int $reward,
        ?Affiliate $affiliate = null
    ): array {
        $buyer = User::factory()->create();
        if ($affiliate === null) {
            $recipient = User::factory()->create();
            $affiliate = Affiliate::query()->create([
                'user_id' => $recipient->id,
                'code' => fake()->unique()->bothify('reward-#####'),
                'reward' => $reward,
            ]);
        }

        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'currency_code' => 'USD',
        ]);
        $service = Service::factory()->create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => $amount,
        ]);
        $referral = AffiliateOrder::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $affiliate->id,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $buyer->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'description' => 'Affiliate reward fixture',
            'quantity' => 1,
            'price' => $amount,
            'reference_type' => Service::class,
            'reference_id' => $service->id,
        ]);

        return [$invoice, $affiliate, $referral];
    }

    private function configureDefaultReward(int $percentage): void
    {
        $now = now();
        $extensionId = DB::table('extensions')->insertGetId([
            'name' => 'Affiliates',
            'extension' => 'Affiliates',
            'type' => 'other',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('settings')->insert([
            'key' => 'default_reward',
            'value' => (string) $percentage,
            'type' => 'integer',
            'encrypted' => false,
            'settingable_id' => $extensionId,
            'settingable_type' => ExtensionModel::class,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}

class AffiliateMigrationRollbackRecorder
{
    /** @var array<int, string> */
    public static array $order = [];

    public static function record(string $migration): void
    {
        self::$order[] = $migration;
    }
}
