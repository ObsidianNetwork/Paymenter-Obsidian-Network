<?php

namespace Tests\Feature\Invoices;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\CreditInvoicePaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class CreditInvoicePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_payment_uses_fresh_remaining_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user, '10.00');
        $credit = Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '10.00',
        ]);
        $staleInvoice = $invoice->fresh(['items', 'transactions']);

        ExtensionHelper::addPayment($invoice->id, null, '4.00');

        $service = app(CreditInvoicePaymentService::class);
        $result = $service->pay($staleInvoice);

        $this->assertSame('6.00', $result['applied']);
        $this->assertTrue($result['fully_paid']);
        $this->assertSame('4.00', $this->money($credit->fresh()->amount));
        $this->assertSame(2, $invoice->transactions()->count());
        $this->assertSame(1, $invoice->transactions()
            ->where('is_credit_transaction', true)
            ->count());

        $replayed = $service->pay($staleInvoice);

        $this->assertSame('0.00', $replayed['applied']);
        $this->assertTrue($replayed['fully_paid']);
        $this->assertSame('4.00', $this->money($credit->fresh()->amount));
        $this->assertSame(2, $invoice->transactions()->count());
    }

    public function test_external_settlement_wins_before_stale_credit_request(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user, '10.00');
        $credit = Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '10.00',
        ]);
        $staleInvoice = $invoice->fresh(['items', 'transactions']);

        ExtensionHelper::addPayment($invoice->id, null, '10.00');
        $result = app(CreditInvoicePaymentService::class)->pay($staleInvoice);

        $this->assertSame('0.00', $result['applied']);
        $this->assertTrue($result['fully_paid']);
        $this->assertSame('10.00', $this->money($credit->fresh()->amount));
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_partial_credit_is_optional_and_uses_exact_cents(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user, '10.00');
        $credit = Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '4.25',
        ]);
        $service = app(CreditInvoicePaymentService::class);

        $skipped = $service->pay($invoice, allowPartial: false);

        $this->assertSame('0.00', $skipped['applied']);
        $this->assertSame('4.25', $this->money($credit->fresh()->amount));
        $this->assertSame(0, $invoice->transactions()->count());

        $partial = $service->pay($invoice);

        $this->assertSame('4.25', $partial['applied']);
        $this->assertFalse($partial['fully_paid']);
        $this->assertSame('0.00', $this->money($credit->fresh()->amount));
        $this->assertSame(
            '5.75',
            $this->money($invoice->fresh()->remaining)
        );
    }

    public function test_invoice_livewire_action_uses_credit_coordinator(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user, '10.00');
        Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '3.50',
        ]);
        $this->actingAs($user);
        session($this->loginUser($user));

        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame(
            '0.00',
            $this->money($user->credits()->firstOrFail()->amount)
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'amount' => 3.50,
            'is_credit_transaction' => true,
        ]);
    }

    public function test_credit_balance_additions_share_one_exact_row(): void
    {
        $user = User::factory()->create();
        $service = app(CreditInvoicePaymentService::class);

        DB::transaction(function () use ($service, $user): void {
            $service->addBalance($user->id, 'USD', '0.10');
            $service->addBalance($user->id, 'USD', '0.20');
        });

        $this->assertSame(1, $user->credits()->count());
        $this->assertSame(
            '0.30',
            $this->money($user->credits()->firstOrFail()->amount)
        );
    }

    public function test_credit_balance_addition_rejects_decimal_overflow(): void
    {
        $user = User::factory()->create();
        Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            // SQLite can represent this integer boundary exactly; retaining
            // cents at this magnitude would be rounded by NUMERIC affinity.
            'amount' => '999999999999999.00',
        ]);

        try {
            DB::transaction(
                fn () => app(CreditInvoicePaymentService::class)
                    ->addBalance($user->id, 'USD', '1.00')
            );
            $this->fail('An overflowing credit balance was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'exceeds DECIMAL(17,2)',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            '999999999999999.00',
            $this->money($user->credits()->firstOrFail()->amount)
        );
    }

    public function test_paid_credit_deposits_reuse_the_unique_balance(): void
    {
        $user = User::factory()->create();
        foreach (['1.10', '2.20'] as $amount) {
            $invoice = $this->invoice(
                $user,
                $amount,
                Credit::class
            );
            ExtensionHelper::addPayment($invoice->id, null, $amount);
        }

        $this->assertSame(1, $user->credits()->count());
        $this->assertSame(
            '3.30',
            $this->money($user->credits()->firstOrFail()->amount)
        );
    }

    public function test_database_rejects_duplicate_user_currency_balance(): void
    {
        $user = User::factory()->create();
        Credit::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '1.00',
        ]);

        try {
            DB::table('credits')->insert([
                'user_id' => $user->id,
                'currency_code' => 'USD',
                'amount' => '2.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail(
                'Expected the database to reject a duplicate credit balance.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $user->credits()->count());
    }

    public function test_migration_consolidates_existing_balances_before_adding_unique_guard(): void
    {
        Schema::table('credits', function (Blueprint $table): void {
            $table->dropUnique('credits_user_currency_unique');
        });
        $user = User::factory()->create();
        DB::table('credits')->insert([
            [
                'user_id' => $user->id,
                'currency_code' => 'USD',
                'amount' => '1.10',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'user_id' => $user->id,
                'currency_code' => 'USD',
                'amount' => '2.20',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_07_27_000130_enforce_unique_credit_balances.php'
        );
        $migration->up();

        $this->assertSame(1, $user->credits()->count());
        $this->assertSame(
            '3.30',
            $this->money($user->credits()->firstOrFail()->amount)
        );

        $this->expectException(QueryException::class);
        DB::table('credits')->insert([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '4.40',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_credit_coordinator_keeps_credit_as_final_shared_lock(): void
    {
        $source = file_get_contents(
            app_path('Services/Invoice/CreditInvoicePaymentService.php')
        );
        $markers = [
            '$invoice = Invoice::query()',
            '->lockFulfillmentObligations($invoice)',
            '$paidCents = $invoice->transactions()',
            '$credit = Credit::query()',
        ];
        $lastPosition = -1;

        foreach ($markers as $marker) {
            $position = strpos($source, $marker);
            $this->assertNotFalse(
                $position,
                "Missing credit lock-order marker: {$marker}"
            );
            $this->assertGreaterThan(
                $lastPosition,
                $position,
                "Credit lock-order marker is out of order: {$marker}"
            );
            $lastPosition = $position;
        }
    }

    public function test_rejected_credit_deposit_closes_its_transaction(): void
    {
        config([
            'settings.credits_minimum_deposit' => 1,
            'settings.credits_maximum_deposit' => 100,
            'settings.credits_maximum_credit' => 100,
            'settings.default_currency' => 'USD',
        ]);
        $user = User::factory()->create();
        Credit::query()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => '95.00',
        ]);
        $this->actingAs($user);
        session($this->loginUser($user));
        $component = app(Credits::class);
        $component->currency = 'USD';
        $component->amount = 10;
        $component->gateway = 999;
        $component->gateways = [['id' => 999]];
        $transactionLevel = DB::transactionLevel();

        try {
            $component->addCredit();
            $this->fail('An over-limit credit deposit was accepted.');
        } catch (DisplayException $exception) {
            $this->assertSame(
                'You cannot exceed the maximum credits allowed.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $transactionLevel,
            DB::transactionLevel()
        );
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(
            '95.00',
            $this->money($user->credits()->sole()->amount)
        );
    }

    private function invoice(
        User $user,
        string $amount,
        ?string $referenceType = null
    ): Invoice {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create([
            'description' => 'Credit payment fixture',
            'quantity' => 1,
            'price' => $amount,
            'reference_type' => $referenceType,
        ]);

        return $invoice->fresh();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
