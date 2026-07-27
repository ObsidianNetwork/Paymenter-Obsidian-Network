<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Enums\InvoiceTransactionStatus;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CronjobTest extends TestCase
{
    use RefreshDatabase;

    public function test_finite_recurring_coupon_ends_after_exact_paid_cycle_allowance(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 2,
            'code' => 'TWO-CYCLES',
            'value' => 50,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
            'expires_at' => now()->addDays(2),
        ]);
        $checkoutInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $checkoutInvoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => '5.00',
            'quantity' => 1,
            'description' => 'Discounted checkout period',
        ]);

        $renew = new \ReflectionMethod(
            CronJob::class,
            'createRenewalForService'
        );
        $renew->setAccessible(true);
        DB::transaction(
            fn (): bool => $renew->invoke(
                app(CronJob::class),
                $service->id
            )
        );

        $firstRenewal = $service->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->firstOrFail();
        $this->assertSame(
            '5.00',
            (string) $firstRenewal->items()->firstOrFail()->price
        );
        $this->assertSame('5.00', (string) $service->fresh()->price);

        DB::table('invoices')
            ->where('id', $firstRenewal->id)
            ->update(['status' => Invoice::STATUS_PAID]);
        DB::table('services')
            ->where('id', $service->id)
            ->update(['billing_cycles_completed' => 2]);
        DB::transaction(
            fn (): bool => $renew->invoke(
                app(CronJob::class),
                $service->id
            )
        );

        $secondRenewal = $service->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->firstOrFail();
        $this->assertSame(
            '10.00',
            (string) $secondRenewal->items()->firstOrFail()->price
        );
        $this->assertSame('10.00', (string) $service->fresh()->price);

        $legacyService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '5.00',
            'billing_cycles_completed' => 2,
            'expires_at' => now()->addDays(2),
        ]);
        foreach (range(1, 3) as $cycle) {
            $invoice = Invoice::factory()->create([
                'user_id' => $user->id,
                'currency_code' => 'USD',
                'status' => Invoice::STATUS_PAID,
            ]);
            $invoice->items()->create([
                'reference_id' => $legacyService->id,
                'reference_type' => Service::class,
                'price' => '5.00',
                'quantity' => 1,
                'description' => "Legacy discounted period {$cycle}",
            ]);
        }

        DB::transaction(
            fn (): bool => $renew->invoke(
                app(CronJob::class),
                $legacyService->id
            )
        );
        $legacyRenewal = $legacyService->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->firstOrFail();
        $this->assertSame(
            '10.00',
            (string) $legacyRenewal->items()->firstOrFail()->price
        );
        $this->assertSame(
            '10.00',
            (string) $legacyService->fresh()->price
        );
    }

    public function test_finite_fully_discounted_coupon_counts_zero_price_renewal(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $coupon = Coupon::forceCreate([
            'type' => 'percentage',
            'applies_to' => 'all',
            'recurring' => 2,
            'code' => 'TWO-FREE-CYCLES',
            'value' => 100,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
            'period_base_price' => '0.00',
            'current_period_price' => '0.00',
            'billing_cycles_completed' => 1,
            'expires_at' => now()->addDays(2),
        ]);
        $renew = new \ReflectionMethod(
            CronJob::class,
            'createRenewalForService'
        );
        $renew->setAccessible(true);

        DB::transaction(
            fn (): bool => $renew->invoke(
                app(CronJob::class),
                $service->id
            )
        );
        $service->refresh();
        $this->assertSame(2, $service->billing_cycles_completed);
        $this->assertSame(
            0,
            $service->invoices()
                ->where('status', Invoice::STATUS_PENDING)
                ->count()
        );

        Carbon::setTestNow(
            $service->expires_at->copy()->subDays(2)
        );
        DB::transaction(
            fn (): bool => $renew->invoke(
                app(CronJob::class),
                $service->id
            )
        );

        $cycleThree = $service->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->firstOrFail();
        $this->assertSame(
            '10.00',
            (string) $cycleThree->items()->firstOrFail()->price
        );
        $this->assertSame('10.00', (string) $service->fresh()->price);
        $this->assertSame(
            2,
            $service->fresh()->billing_cycles_completed,
            'A pending cycle does not consume coupon evidence.'
        );
        Carbon::setTestNow();
    }

    public function test_failed_cron_row_rolls_back_without_blocking_later_rows(): void
    {
        $command = app(CronJob::class);
        $runner = new \ReflectionMethod(CronJob::class, 'runCronRow');
        $runner->setAccessible(true);

        $failed = $runner->invoke(
            $command,
            'regression',
            'row:failed',
            function (): bool {
                Setting::create([
                    'key' => 'failed_cron_row',
                    'value' => 'must roll back',
                    'type' => 'string',
                ]);

                throw new \RuntimeException('expected isolated failure');
            }
        );
        $succeeded = $runner->invoke(
            $command,
            'regression',
            'row:succeeded',
            function (): bool {
                Setting::create([
                    'key' => 'successful_cron_row',
                    'value' => 'committed',
                    'type' => 'string',
                ]);

                return true;
            }
        );

        $this->assertFalse($failed);
        $this->assertTrue($succeeded);
        $this->assertDatabaseMissing('settings', [
            'key' => 'failed_cron_row',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'successful_cron_row',
            'value' => 'committed',
        ]);
    }

    public function test_paid_order_race_is_revalidated_before_cancellation(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct(['stock' => 10]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
            'created_at' => now()->subDays(8),
            'currency_code' => 'USD',
            'price' => 10.00,
            'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => '10.00',
            'quantity' => 1,
            'description' => 'Initial service',
        ]);
        $this->mock(CancelInvoiceService::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(
                function () use ($invoice, $service): Invoice {
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update(['status' => Invoice::STATUS_PAID]);
                    FulfillmentStatusTransitionService::run(
                        $service,
                        fn () => $service->forceFill([
                            'status' => Service::STATUS_ACTIVE,
                        ])->save()
                    );

                    return $invoice->fresh();
                }
            );

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertSame(
            10,
            $product->product->fresh()->stock
        );
    }

    public function test_renewal_race_is_revalidated_before_termination(): void
    {
        config(['settings.cronjob_order_terminate' => 14]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => '10.00',
            'quantity' => 1,
            'description' => 'Renewal',
        ]);
        $renewedUntil = now()->addMonth()->startOfSecond();
        $this->mock(CancelInvoiceService::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(
                function () use (
                    $invoice,
                    $service,
                    $renewedUntil
                ): Invoice {
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update(['status' => Invoice::STATUS_PAID]);
                    FulfillmentStatusTransitionService::run(
                        $service,
                        fn () => $service->forceFill([
                            'status' => Service::STATUS_ACTIVE,
                            'expires_at' => $renewedUntil,
                        ])->save()
                    );

                    return $invoice->fresh();
                }
            );
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertTrue(
            $service->fresh()->expires_at->equalTo($renewedUntil)
        );
        Queue::assertNotPushed(TerminateJob::class);
    }

    /**
     * A basic unit test example.
     */
    public function test_invoices_are_created_if_due_date_is_reached(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'USD',
            'price' => 10.00, // Set a price for the service
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'USD',
        ]);
    }

    public function test_invoices_are_paid_with_credits_if_available(): void
    {
        $user = User::factory()->create();

        $user->credits()->create([
            'currency_code' => 'USD',
            'amount' => 10.00,
        ]);

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'USD',
            'price' => 10.00, // Set a price for the service
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'paid',
            'due_at' => $service->expires_at,
            'currency_code' => 'USD',
        ]);
        $invoice = Invoice::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(
            '0.00',
            number_format((float) $user->credits()->firstOrFail()->amount, 2, '.', '')
        );
        $this->assertSame(1, $invoice->transactions()
            ->where('is_credit_transaction', true)
            ->count());
    }

    public function test_cron_does_not_partially_consume_credit(): void
    {
        $user = User::factory()->create();
        $credit = $user->credits()->create([
            'currency_code' => 'USD',
            'amount' => 5.00,
        ]);
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => true,
        ]);
        $product = $this->createProduct();
        Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2)->subHour(),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = Invoice::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(
            '5.00',
            number_format((float) $credit->fresh()->amount, 2, '.', '')
        );
        $this->assertSame(0, $invoice->transactions()
            ->where('is_credit_transaction', true)
            ->count());
    }

    public function test_active_unpaid_upgrades_block_renewal_invoices(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $product = $this->createProduct();
        $fixtures = [];

        foreach ([
            ServiceUpgrade::STATUS_PENDING,
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
        ] as $status) {
            $user = User::factory()->create();
            $service = Service::factory()->create([
                'user_id' => $user->id,
                'plan_id' => $product->plan->id,
                'product_id' => $product->product->id,
                'status' => Service::STATUS_ACTIVE,
                'expires_at' => now()->addDays(2),
                'currency_code' => 'USD',
                'price' => 10.00,
            ]);
            $upgradeInvoice = Invoice::factory()->create([
                'user_id' => $user->id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now()->addDays(7),
                'currency_code' => 'USD',
            ]);
            $upgrade = ServiceUpgrade::create([
                'service_id' => $service->id,
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'invoice_id' => $upgradeInvoice->id,
                'status' => $status,
                'type' => 'product',
                'active_service_guard_id' => $service->id,
            ]);
            $upgradeInvoice->items()->create([
                'description' => 'Pending upgrade',
                'quantity' => 1,
                'price' => 1.00,
                'reference_type' => ServiceUpgrade::class,
                'reference_id' => $upgrade->id,
            ]);
            $fixtures[] = [$service, $upgradeInvoice];
        }

        $this->artisan('app:cron-job')->assertExitCode(0);

        foreach ($fixtures as [$service, $upgradeInvoice]) {
            $this->assertSame(1, Invoice::query()
                ->where('user_id', $service->user_id)
                ->count());
            $this->assertSame(
                $upgradeInvoice->id,
                Invoice::query()
                    ->where('user_id', $service->user_id)
                    ->sole()
                    ->id
            );
            $this->assertFalse($service->invoices()->exists());
        }
    }

    public function test_active_upgrade_blocks_zero_price_renewal(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $product = $this->createProduct();
        $user = User::factory()->create();
        $expiresAt = now()->addDays(2)->startOfDay();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
            'currency_code' => 'USD',
            'price' => 0,
        ]);
        ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'invoice_id' => null,
            'status' => ServiceUpgrade::STATUS_PENDING,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            $expiresAt->toDateString(),
            $service->fresh()->expires_at?->toDateString()
        );
        $this->assertFalse($service->invoices()->exists());
    }

    public function test_expired_upgrade_without_payment_is_cancelled(): void
    {
        [$invoice, $upgrade] = $this->expiredUpgradeFixture();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertNull($upgrade->fresh()->active_service_guard_id);
    }

    public function test_expired_upgrade_with_locked_payment_evidence_requires_attention(): void
    {
        [$invoice, $upgrade] = $this->expiredUpgradeFixture();
        $transaction = $invoice->transactions()->create([
            'gateway_id' => null,
            'amount' => '5.00',
            'fee' => '0.00',
            'transaction_id' => 'expiring-upgrade-processing',
            'status' => InvoiceTransactionStatus::Processing,
            'is_credit_transaction' => false,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'in-flight payment',
            (string) $invoice->fresh()->payment_attention_reason
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertNotNull($upgrade->fresh()->failed_at);
        $this->assertSame(
            InvoiceTransactionStatus::Processing,
            $transaction->fresh()->status
        );
    }

    public function test_services_are_renewed_if_price_is_zero(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'USD',
            'price' => 0.00, // Set a price for the service
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was renewed
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'active',
            'expires_at' => $service->calculateNextDueDate(),
        ]);
    }

    public function test_services_are_cancelled_if_not_paid_within_configured_days(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_order_cancel
        config(['settings.cronjob_order_cancel' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'pending',
            'currency_code' => 'USD',
            'price' => 10.00,
            'created_at' => now()->subDays(8), // Set created_at to 8 days ago
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was cancelled
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_services_are_suspended_if_due_date_has_passed(): void
    {
        // Create a user
        $user = User::factory()->create();

        $product = $this->createProduct();

        // Making sure the cronjob_order_suspend is set to 2 days
        config(['settings.cronjob_order_suspend' => 2]);

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->subDays(3), // Set expires_at to 1 day ago
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        Queue::fake();

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'USD',
        ]);

        Queue::assertPushed(SuspendJob::class, function ($job) use ($service) {
            return $job->service->id === $service->id;
        });

        // Check if the service was suspended
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'suspended',
        ]);
    }

    public function test_orders_are_terminated_if_due_date_is_overdue(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_order_terminate
        config(['settings.cronjob_order_terminate' => 14]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now(), // Set expires_at to 15 days ago
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Now it should have generated an invoice
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'USD',
        ]);

        // Update due date to be overdue
        $service = app(
            ServiceBillingAnchorMutationCoordinator::class
        )->update($service, [
            'expires_at' => now()->subDays(15),
        ]);

        Queue::fake();

        // Run the cron job again
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was terminated
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_CANCELLED,
        ]);

        Queue::assertPushed(TerminateJob::class, function ($job) use ($service) {
            return $job->service->id === $service->id;
        });

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'cancelled',
            'currency_code' => 'USD',
        ]);
    }

    public function test_termination_cancels_every_unpaid_service_obligation(): void
    {
        config(['settings.cronjob_order_terminate' => 14]);
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $renewalInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $renewalInvoice->items()->create([
            'description' => 'Overdue renewal',
            'quantity' => 1,
            'price' => '10.00',
            'reference_type' => Service::class,
            'reference_id' => $service->id,
        ]);
        $upgradeInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDay(),
            'currency_code' => 'USD',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'invoice_id' => $upgradeInvoice->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
        ]);
        $upgradeInvoice->items()->create([
            'description' => 'Pending upgrade',
            'quantity' => 1,
            'price' => '5.00',
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
        ]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $renewalInvoice->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $upgradeInvoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_CANCELLED,
            $service->fresh()->status
        );
    }

    public function test_termination_rolls_back_when_an_upgrade_has_payment_activity(): void
    {
        config(['settings.cronjob_order_terminate' => 14]);
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $renewalInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $renewalInvoice->items()->create([
            'description' => 'Overdue renewal',
            'quantity' => 1,
            'price' => '10.00',
            'reference_type' => Service::class,
            'reference_id' => $service->id,
        ]);
        $upgradeInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDay(),
            'currency_code' => 'USD',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'invoice_id' => $upgradeInvoice->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
        ]);
        $upgradeInvoice->items()->create([
            'description' => 'Pending upgrade',
            'quantity' => 1,
            'price' => '5.00',
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
        ]);
        $upgradeInvoice->transactions()->create([
            'gateway_id' => null,
            'amount' => '5.00',
            'fee' => '0.00',
            'transaction_id' => 'termination-processing-upgrade',
            'status' => InvoiceTransactionStatus::Processing,
            'is_credit_transaction' => false,
        ]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Service::STATUS_SUSPENDED,
            $service->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewalInvoice->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $upgradeInvoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        Queue::assertNotPushed(TerminateJob::class);
    }

    public function test_termination_rolls_back_when_a_renewal_has_payment_activity(): void
    {
        config(['settings.cronjob_order_terminate' => 14]);
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $renewalInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $renewalInvoice->items()->create([
            'description' => 'Overdue renewal',
            'quantity' => 1,
            'price' => '10.00',
            'reference_type' => Service::class,
            'reference_id' => $service->id,
        ]);
        $transaction = $renewalInvoice->transactions()->create([
            'gateway_id' => null,
            'amount' => '10.00',
            'fee' => '0.00',
            'transaction_id' => 'termination-processing-renewal',
            'status' => InvoiceTransactionStatus::Processing,
            'is_credit_transaction' => false,
        ]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(
            Service::STATUS_SUSPENDED,
            $service->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewalInvoice->fresh()->status
        );
        $this->assertSame(
            InvoiceTransactionStatus::Processing,
            $transaction->fresh()->status
        );
        Queue::assertNotPushed(TerminateJob::class);
    }

    public function test_tickets_are_closed_if_no_response_for_x_days(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_ticket_close
        config(['settings.cronjob_close_ticket' => 7]);

        // Create a ticket for the user
        $ticket = Ticket::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $differentUser = User::factory()->create();

        // Add message
        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $differentUser->id,
            'message' => 'This is a test message.',
            'created_at' => now()->subDays(8), // Set created_at to 8 days ago
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the ticket was closed
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'closed',
        ]);
    }

    public function test_if_product_stock_is_incremented_on_termination(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'price' => 10.00,
            'quantity' => 2,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was terminated
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_CANCELLED,
        ]);

        // Check if the product stock was incremented
        $this->assertDatabaseHas('products', [
            'id' => $product->product->id,
            'stock' => 10 + $service->quantity,
        ]);
    }

    public function test_if_stock_is_null_it_does_not_increment_stock()
    {
        $product = $this->createProduct(['stock' => null]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was terminated
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_CANCELLED,
        ]);
    }

    public function test_if_stock_is_zero_it_does_increment_stock()
    {
        $product = $this->createProduct(['stock' => 0]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was terminated
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_CANCELLED,
        ]);

        // Check if the product stock was incremented
        $this->assertDatabaseHas('products', [
            'id' => $product->product->id,
            'stock' => 1,
        ]);
    }

    /**
     * @return array{Invoice, ServiceUpgrade}
     */
    private function expiredUpgradeFixture(): array
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addMonth(),
            'currency_code' => 'USD',
            'price' => '10.00',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->subMinute(),
            'currency_code' => 'USD',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
        ]);
        $invoice->items()->create([
            'description' => 'Expired upgrade',
            'quantity' => 1,
            'price' => '5.00',
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
        ]);

        return [$invoice, $upgrade];
    }
}
