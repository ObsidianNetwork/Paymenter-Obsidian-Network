<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Livewire\Invoices\Show;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\MarkInvoicePaidService;
use App\Services\Service\DurableFulfillmentService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class InvoicePaymentProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function createInvoiceWithItem($total = 100.00)
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        $invoice->items()->create([
            'description' => 'Test Item',
            'quantity' => 1,
            'price' => $total,
        ]);

        return $invoice->fresh();
    }

    public function test_invoice_starts_with_pending_status()
    {
        $invoice = $this->createInvoiceWithItem();

        $this->assertEquals('pending', $invoice->status);
        $this->assertGreaterThan(0, $invoice->total);
    }

    public function test_invoice_calculates_remaining_amount_correctly()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // No payments yet
        $this->assertEquals(100.00, $invoice->remaining);

        // Add partial payment
        ExtensionHelper::addPayment($invoice->id, null, 30.00);

        $this->assertEquals(70.00, $invoice->fresh()->remaining);
    }

    public function test_successful_payment_marks_invoice_as_paid()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process full payment
        ExtensionHelper::addPayment(
            $invoice->id,
            'TestGateway',
            100.00,
            fee: 2.50,
            transactionId: 'test_txn_123'
        );

        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(0, $invoice->remaining);
        $this->assertEquals(1, $invoice->transactions()->count());

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(100.00, $transaction->amount);
        $this->assertEquals(2.50, $transaction->fee);
        $this->assertEquals('test_txn_123', $transaction->transaction_id);
    }

    public function test_partial_payment_keeps_invoice_pending()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process partial payment
        ExtensionHelper::addPayment($invoice->id, 'TestGateway', 60.00);

        $invoice->refresh();

        $this->assertEquals('pending', $invoice->status);
        $this->assertEquals(40.00, $invoice->remaining);
    }

    public function test_overpayment_marks_invoice_as_paid()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process overpayment
        ExtensionHelper::addPayment($invoice->id, 'TestGateway', 150.00);

        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(-50.00, $invoice->remaining); // Credit balance
    }

    public function test_service_is_activated_when_invoice_is_paid()
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $service = Service::factory()->create(['status' => 'pending', 'user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => 'pending',
            'due_at' => now()->addDays(7),
            'currency_code' => $service->currency_code,
        ]);

        // Link service to invoice
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 100.00,
            'quantity' => 1,
            'description' => 'blah',
        ]);

        // Pay the invoice
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00);

        $service->refresh();

        $this->assertEquals('active', $service->status);
        $this->assertNotNull($service->expires_at);
    }

    public function test_external_payment_survives_checkout_commit_failure_as_attention(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'status' => Service::STATUS_PENDING,
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
            'currency_code' => $service->currency_code,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Reserved server',
        ]);
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class extends CapacityInvoicePaymentService
            {
                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    return true;
                }

                public function deadlineExpired(Invoice $invoice): bool
                {
                    return false;
                }
            }
        );
        $this->app->instance(
            DurableFulfillmentService::class,
            new class extends DurableFulfillmentService
            {
                public function isReservationBacked(
                    Service $service
                ): bool {
                    return true;
                }

                public function preflightPaidService(
                    Service $service,
                    Invoice $invoice
                ): ?string {
                    return null;
                }

                public function commitPaidService(
                    Service $service,
                    Invoice $invoice
                ): bool {
                    throw new \RuntimeException(
                        'Deterministic reservation commit failure.'
                    );
                }
            }
        );

        $transaction = ExtensionHelper::addPayment(
            $invoice->id,
            null,
            100,
            transactionId: 'captured-checkout-failure'
        );

        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'captured-checkout-failure',
            'status' => \App\Enums\InvoiceTransactionStatus::Succeeded->value,
        ]);
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'Deterministic reservation commit failure',
            (string) $invoice->fresh()->payment_attention_reason
        );
        $this->assertSame(
            Service::STATUS_PENDING,
            $service->fresh()->status
        );
        $attentionAt = $invoice->fresh()
            ->payment_attention_required_at?->toJSON();
        $replayed = ExtensionHelper::addPayment(
            $invoice->id,
            null,
            100,
            transactionId: 'captured-checkout-failure'
        );
        $this->assertTrue($replayed->is($transaction));
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(
            $attentionAt,
            $invoice->fresh()->payment_attention_required_at?->toJSON()
        );

        foreach ([
            fn () => ExtensionHelper::addPayment(
                $invoice->id,
                null,
                101,
                transactionId: 'captured-checkout-failure'
            ),
            fn () => ExtensionHelper::addPayment(
                $invoice->id,
                null,
                100,
                transactionId: 'captured-checkout-failure',
                status: InvoiceTransactionStatus::Processing
            ),
            fn () => ExtensionHelper::addPayment(
                $invoice->id,
                null,
                100,
                transactionId: 'captured-checkout-failure',
                isCreditTransaction: true
            ),
        ] as $mismatchedReplay) {
            try {
                $mismatchedReplay();
                $this->fail(
                    'Expected conflicting payment evidence to be rejected.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'transaction',
                    strtolower($exception->getMessage())
                );
            }
        }

        try {
            ExtensionHelper::addPayment(
                $invoice->id,
                null,
                100,
                transactionId: 'second-captured-payment'
            );
            $this->fail(
                'Expected payment attention to reject a duplicate payment.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'manual payment review',
                $exception->getMessage()
            );
        }
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertDatabaseMissing('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'second-captured-payment',
        ]);
        try {
            $transaction->forceFill(['fee' => 9.99])->save();
            $this->fail(
                'Expected attention payment evidence to be immutable.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'manual payment review',
                $exception->getMessage()
            );
        }
        try {
            $transaction->delete();
            $this->fail(
                'Expected attention payment evidence deletion to fail.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'manual payment review',
                $exception->getMessage()
            );
        }

        try {
            app(MarkInvoicePaidService::class)->handle($invoice);
            $this->fail(
                'Expected manual paid transition to remain blocked.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'manual payment review',
                $exception->getMessage()
            );
        }
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_gateway_scoped_payment_identity_is_case_sensitive_and_fee_safe(): void
    {
        $invoice = $this->createInvoiceWithItem(100);
        $firstGateway = Gateway::create([
            'name' => 'First gateway',
            'extension' => 'FirstGateway',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        $secondGateway = Gateway::create([
            'name' => 'Second gateway',
            'extension' => 'SecondGateway',
            'type' => 'gateway',
            'enabled' => true,
        ]);

        $first = ExtensionHelper::addProcessingPayment(
            $invoice->id,
            $firstGateway,
            1,
            fee: 0,
            transactionId: 'Case-Sensitive-Reference'
        );
        $second = ExtensionHelper::addProcessingPayment(
            $invoice->id,
            $secondGateway,
            1,
            fee: 0,
            transactionId: 'Case-Sensitive-Reference'
        );
        $caseVariant = ExtensionHelper::addProcessingPayment(
            $invoice->id,
            $firstGateway,
            1,
            fee: 0,
            transactionId: 'case-sensitive-reference'
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->id, $caseVariant->id);
        $this->assertNotSame(
            $first->gateway_transaction_guard,
            $second->gateway_transaction_guard
        );
        $this->assertNotSame(
            $first->gateway_transaction_guard,
            $caseVariant->gateway_transaction_guard
        );

        ExtensionHelper::addPaymentFee(
            'Case-Sensitive-Reference',
            2.50,
            $secondGateway
        );
        $this->assertSame('0.00', $first->fresh()->fee);
        $this->assertSame('2.50', $second->fresh()->fee);
        $this->assertSame('0.00', $caseVariant->fresh()->fee);

        try {
            ExtensionHelper::addPaymentFee(
                'Case-Sensitive-Reference',
                9.99
            );
            $this->fail(
                'Expected an unscoped cross-gateway fee update to fail.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'ambiguous across gateways',
                $exception->getMessage()
            );
        }
        $this->assertSame('0.00', $first->fresh()->fee);
        $this->assertSame('2.50', $second->fresh()->fee);
    }

    public function test_capacity_invoice_line_cannot_move_to_non_capacity_invoice(): void
    {
        $capacityInvoice = $this->createInvoiceWithItem(100);
        $ordinaryInvoice = $this->createInvoiceWithItem(100);
        $line = $capacityInvoice->items()->firstOrFail();
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class((int) $capacityInvoice->id) extends CapacityInvoicePaymentService
            {
                public function __construct(
                    private readonly int $capacityInvoiceId
                ) {
                }

                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    $invoiceId = $invoice instanceof Invoice
                        ? (int) $invoice->id
                        : $invoice;

                    return $invoiceId === $this->capacityInvoiceId;
                }
            }
        );
        $line->invoice_id = $ordinaryInvoice->id;

        try {
            $line->save();
            $this->fail(
                'Expected moving a capacity line off its invoice to fail.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment lines are immutable',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $capacityInvoice->id,
            $line->fresh()->invoice_id
        );
    }

    public function test_expired_partial_payment_immediately_requires_attention_and_can_progress(): void
    {
        Queue::fake();
        $invoice = $this->createInvoiceWithItem(100);
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class extends CapacityInvoicePaymentService
            {
                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    return true;
                }

                public function deadlineExpired(Invoice $invoice): bool
                {
                    return true;
                }
            }
        );

        $processing = ExtensionHelper::addProcessingPayment(
            $invoice->id,
            null,
            10,
            transactionId: 'late-partial-payment'
        );

        $this->assertSame(
            InvoiceTransactionStatus::Processing,
            $processing->status
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_alerted_at
        );

        $succeeded = ExtensionHelper::addPayment(
            $invoice->id,
            null,
            10,
            transactionId: 'late-partial-payment'
        );

        $this->assertTrue($succeeded->is($processing));
        $this->assertSame(
            InvoiceTransactionStatus::Succeeded,
            $succeeded->fresh()->status
        );
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_late_processing_payment_can_fail_without_clearing_attention(): void
    {
        Queue::fake();
        $invoice = $this->createInvoiceWithItem(100);
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class extends CapacityInvoicePaymentService
            {
                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    return true;
                }

                public function deadlineExpired(Invoice $invoice): bool
                {
                    return true;
                }
            }
        );

        $processing = ExtensionHelper::addProcessingPayment(
            $invoice->id,
            null,
            10,
            transactionId: 'late-payment-that-failed'
        );
        $attentionAt = $invoice->fresh()
            ->payment_attention_required_at?->toJSON();
        $attentionReason = $invoice->fresh()->payment_attention_reason;

        $failed = ExtensionHelper::addFailedPayment(
            $invoice->id,
            null,
            10,
            transactionId: 'late-payment-that-failed'
        );

        $this->assertTrue($failed->is($processing));
        $this->assertSame(
            InvoiceTransactionStatus::Failed,
            $failed->fresh()->status
        );
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(
            $attentionAt,
            $invoice->fresh()->payment_attention_required_at?->toJSON()
        );
        $this->assertSame(
            $attentionReason,
            $invoice->fresh()->payment_attention_reason
        );
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_cancelled_capacity_invoice_records_partial_payment_as_attention(): void
    {
        Queue::fake();
        $invoice = $this->createInvoiceWithItem(100);
        $invoice->status = Invoice::STATUS_CANCELLED;
        $invoice->save();
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class extends CapacityInvoicePaymentService
            {
                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    return true;
                }

                public function deadlineExpired(Invoice $invoice): bool
                {
                    return false;
                }
            }
        );

        $transaction = ExtensionHelper::addPayment(
            $invoice->id,
            null,
            10,
            transactionId: 'cancelled-capacity-payment'
        );

        $this->assertTrue($transaction->exists);
        $this->assertSame(
            InvoiceTransactionStatus::Succeeded,
            $transaction->status
        );
        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'cancelled',
            (string) $invoice->fresh()->payment_attention_reason
        );
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_whmcs_import_populates_gateway_transaction_guard(): void
    {
        $source = file_get_contents(
            app_path('Console/Commands/ImportFromWhmcs.php')
        );

        $this->assertStringContainsString(
            "'gateway_transaction_guard' =>",
            $source
        );
        $this->assertStringContainsString(
            'InvoiceTransaction::gatewayTransactionGuard(',
            $source
        );
    }

    public function test_database_guard_rejects_concurrent_nullable_gateway_duplicate(): void
    {
        $firstInvoice = $this->createInvoiceWithItem(100);
        $secondInvoice = $this->createInvoiceWithItem(100);
        $guard = InvoiceTransaction::gatewayTransactionGuard(
            null,
            'concurrent-null-gateway-reference'
        );
        $row = [
            'gateway_id' => null,
            'amount' => 10,
            'fee' => null,
            'transaction_id' => 'concurrent-null-gateway-reference',
            'gateway_transaction_guard' => $guard,
            'status' => InvoiceTransactionStatus::Processing->value,
            'is_credit_transaction' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('invoice_transactions')->insert(
            ['invoice_id' => $firstInvoice->id] + $row
        );

        try {
            DB::table('invoice_transactions')->insert(
                ['invoice_id' => $secondInvoice->id] + $row
            );
            $this->fail(
                'Expected the database idempotency guard to reject duplicate evidence.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_paid_and_cancelled_capacity_invoices_share_the_same_lock_order(): void
    {
        $paid = file_get_contents(
            app_path('Services/Invoice/ProcessPaidInvoiceService.php')
        );
        $cancelled = file_get_contents(
            app_path('Services/Invoice/CancelInvoiceService.php')
        );

        $this->assertOrderedSourceMarkers($paid, [
            '$services = Service::query()',
            '$upgrades = ServiceUpgrade::query()',
            "DB::table('ptero_resource_reservations')",
            '$items = $invoice->items()',
        ]);
        $this->assertOrderedSourceMarkers($cancelled, [
            '$services = Service::query()',
            '$upgrades = ServiceUpgrade::query()',
            '$reservations = DB::table(',
            '$items = $invoice->items()',
        ]);
    }

    public function test_payment_attention_invoice_hides_pay_and_rejects_modal_open(): void
    {
        $invoice = $this->createInvoiceWithItem(100);
        $invoice->forceFill([
            'payment_attention_required_at' => now(),
            'payment_attention_reason' => 'Captured payment needs review.',
        ])->save();
        $user = $invoice->user;
        $this->actingAs($user);
        session($this->loginUser($user));

        Livewire::test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee('Manual payment review required')
            ->assertSeeHtml('data-testid="invoice-payment-attention"')
            ->assertDontSeeHtml('data-testid="invoice-pay-button"')
            ->set('showPayModal', true)
            ->assertSet('showPayModal', false)
            ->set('selectedMethod', 'credit')
            ->call('processPayment')
            ->assertSet('showPayModal', false);
    }

    public function test_expired_capacity_deadline_hides_pay_and_stops_polling(): void
    {
        $invoice = $this->createInvoiceWithItem(100);
        $user = $invoice->user;
        $this->actingAs($user);
        session($this->loginUser($user));
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class extends CapacityInvoicePaymentService
            {
                public function deadlineExpired(Invoice $invoice): bool
                {
                    return true;
                }
            }
        );

        Livewire::test(Show::class, ['invoice' => $invoice])
            ->assertSee('capacity guarantee expired')
            ->assertDontSeeHtml('data-testid="invoice-pay-button"')
            ->set('showPayModal', true)
            ->assertSet('showPayModal', false)
            ->set('checkPayment', true)
            ->call('checkPaymentStatus')
            ->assertSet('checkPayment', false)
            ->assertSet('showPayModal', false);
    }

    public function test_non_capacity_fulfillment_failure_is_not_recovered_as_attention(): void
    {
        $user = User::factory()->create();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'status' => Service::STATUS_PENDING,
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => $service->currency_code,
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Ordinary server',
        ]);
        $this->app->instance(
            DurableFulfillmentService::class,
            new class extends DurableFulfillmentService
            {
                public function isReservationBacked(
                    Service $service
                ): bool {
                    return true;
                }

                public function preflightPaidService(
                    Service $service,
                    Invoice $invoice
                ): ?string {
                    return null;
                }

                public function commitPaidService(
                    Service $service,
                    Invoice $invoice
                ): bool {
                    throw new \RuntimeException(
                        'Ordinary fulfillment failure.'
                    );
                }
            }
        );

        try {
            ExtensionHelper::addPayment(
                $invoice->id,
                null,
                100,
                transactionId: 'ordinary-failure'
            );
            $this->fail(
                'Expected an ordinary fulfillment failure to propagate.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Ordinary fulfillment failure.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    public function test_invoice_handles_multiple_partial_payments()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // First payment
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 30.00);

        // Second payment
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 40.00);

        // Third payment completes it
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 30.00);

        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(0, $invoice->remaining);
        $this->assertEquals(3, $invoice->transactions()->count());
    }

    public function test_payment_fee_is_recorded_correctly()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        ExtensionHelper::addPayment(
            $invoice->id,
            'Stripe',
            100.00,
            fee: 3.20,
            transactionId: 'pi_123'
        );

        $transaction = $invoice->transactions()->first();

        $this->assertEquals(3.20, $transaction->fee);
    }

    public function test_fee_can_be_updated_after_payment()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Initial payment without fee
        ExtensionHelper::addPayment(
            $invoice->id,
            'Stripe',
            100.00,
            transactionId: 'pi_123'
        );

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(0, $transaction->fee);

        // Update fee (from Stripe webhook)
        ExtensionHelper::addPaymentFee('pi_123', 2.90);

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(2.90, $transaction->fee);
    }

    public function test_non_capacity_invoice_may_retain_legacy_direct_paid_transition(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $invoice->status = Invoice::STATUS_PAID;
        $invoice->save();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_non_capacity_invoice_may_be_created_paid(): void
    {
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PAID,
        ]);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
    }

    public function test_direct_capacity_paid_model_transition_is_rejected(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class((int) $invoice->id) extends CapacityInvoicePaymentService
            {
                public function __construct(
                    private readonly int $capacityInvoiceId
                ) {
                }

                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    $invoiceId = $invoice instanceof Invoice
                        ? (int) $invoice->id
                        : $invoice;

                    return $invoiceId === $this->capacityInvoiceId;
                }
            }
        );
        $invoice->status = Invoice::STATUS_PAID;

        try {
            $invoice->save();
            $this->fail('Expected the fulfillment coordinator guard to reject the direct transition.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('fulfillment coordinator', $exception->getMessage());
        }

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_non_capacity_succeeded_evidence_retains_legacy_write_semantics(): void
    {
        $invoice = $this->createInvoiceWithItem();

        $transaction = InvoiceTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => 1,
            'fee' => 0,
            'transaction_id' => 'legacy-direct-success',
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $this->assertTrue($transaction->exists);
        $this->assertSame(
            InvoiceTransactionStatus::Succeeded,
            $transaction->status
        );
    }

    public function test_capacity_succeeded_evidence_requires_atomic_coordinator(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            new class((int) $invoice->id) extends CapacityInvoicePaymentService
            {
                public function __construct(
                    private readonly int $capacityInvoiceId
                ) {
                }

                public function isCapacityBacked(
                    Invoice|int $invoice
                ): bool {
                    $invoiceId = $invoice instanceof Invoice
                        ? (int) $invoice->id
                        : $invoice;

                    return $invoiceId === $this->capacityInvoiceId;
                }
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Succeeded capacity invoice transactions'
        );

        InvoiceTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => 1,
            'fee' => 0,
            'transaction_id' => 'unsafe-capacity-success',
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);
    }

    public function test_capacity_payment_scope_is_invoice_specific(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $otherInvoice = $this->createInvoiceWithItem();
        $payments = new class(
            [(int) $invoice->id, (int) $otherInvoice->id]
        ) extends CapacityInvoicePaymentService
        {
            /**
             * @param  list<int>  $capacityInvoiceIds
             */
            public function __construct(
                private readonly array $capacityInvoiceIds
            ) {
            }

            public function isCapacityBacked(Invoice|int $invoice): bool
            {
                $invoiceId = $invoice instanceof Invoice
                    ? (int) $invoice->id
                    : $invoice;

                return in_array(
                    $invoiceId,
                    $this->capacityInvoiceIds,
                    true
                );
            }
        };
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            $payments
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Succeeded capacity invoice transactions'
        );

        $payments->recordPaymentEvidence(
            (int) $invoice->id,
            fn () => InvoiceTransaction::create([
                'invoice_id' => $otherInvoice->id,
                'amount' => 1,
                'fee' => 0,
                'transaction_id' => 'wrong-invoice-scope',
                'status' => InvoiceTransactionStatus::Succeeded,
            ])
        );
    }

    public function test_payment_scope_rolls_back_and_clears_after_exception(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $payments = new class(
            (int) $invoice->id
        ) extends CapacityInvoicePaymentService
        {
            public function __construct(
                private readonly int $capacityInvoiceId
            ) {
            }

            public function isCapacityBacked(Invoice|int $invoice): bool
            {
                $invoiceId = $invoice instanceof Invoice
                    ? (int) $invoice->id
                    : $invoice;

                return $invoiceId === $this->capacityInvoiceId;
            }
        };
        $this->app->instance(
            CapacityInvoicePaymentService::class,
            $payments
        );

        try {
            $payments->recordPaymentEvidence(
                (int) $invoice->id,
                function () use ($invoice): void {
                    InvoiceTransaction::create([
                        'invoice_id' => $invoice->id,
                        'amount' => 1,
                        'fee' => 0,
                        'transaction_id' => 'rolled-back-scope',
                        'status' => InvoiceTransactionStatus::Succeeded,
                    ]);

                    throw new \RuntimeException('scope sentinel');
                }
            );
            $this->fail('Expected the payment scope to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('scope sentinel', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoice_transactions', [
            'transaction_id' => 'rolled-back-scope',
        ]);
        $this->assertFalse(
            $payments->isRecordingPaymentEvidence((int) $invoice->id)
        );
    }

    public function test_nested_payment_scope_preserves_and_clears_depth(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $payments = app(CapacityInvoicePaymentService::class);

        $this->assertFalse(
            $payments->isRecordingPaymentEvidence((int) $invoice->id)
        );
        $payments->recordPaymentEvidence(
            (int) $invoice->id,
            function () use ($invoice, $payments): void {
                $this->assertTrue(
                    $payments->isRecordingPaymentEvidence(
                        (int) $invoice->id
                    )
                );

                try {
                    $payments->recordPaymentEvidence(
                        (int) $invoice->id,
                        static fn () => throw new \RuntimeException(
                            'nested scope sentinel'
                        )
                    );
                    $this->fail('Expected the nested scope to throw.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame(
                        'nested scope sentinel',
                        $exception->getMessage()
                    );
                }

                $this->assertTrue(
                    $payments->isRecordingPaymentEvidence(
                        (int) $invoice->id
                    )
                );
            }
        );
        $this->assertFalse(
            $payments->isRecordingPaymentEvidence((int) $invoice->id)
        );
    }

    public function test_missing_fulfillment_reference_rolls_back_paid_status(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => 999999,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Missing service',
        ]);

        try {
            app(MarkInvoicePaidService::class)->handle($invoice);
            $this->fail('Expected the missing service reference to abort payment.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('references missing service', $exception->getMessage());
        }

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_capacity_upgrade_payment_fails_closed_when_coordinator_is_missing(): void
    {
        [$invoice, $upgrade] = $this->upgradeInvoice(
            ServiceUpgrade::STATUS_PENDING
        );
        $this->mock(
            CapacityUpgradeReservationIdentity::class,
            function (MockInterface $mock) use ($upgrade): void {
                $mock->shouldReceive('requiresCoordinator')
                    ->once()
                    ->with(\Mockery::on(
                        fn (ServiceUpgrade $candidate): bool =>
                            $candidate->is($upgrade)
                    ))
                    ->andReturnTrue();
            }
        );

        try {
            app(MarkInvoicePaidService::class)->handle($invoice);
            $this->fail(
                'Expected a row-backed upgrade to require its coordinator.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'reservation coordinator is unavailable',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
    }

    public function test_non_capacity_upgrade_is_not_blocked_by_missing_coordinator(): void
    {
        [$invoice, $upgrade] = $this->upgradeInvoice(
            ServiceUpgrade::STATUS_CANCELLED
        );
        $this->mock(
            CapacityUpgradeReservationIdentity::class,
            function (MockInterface $mock) use ($upgrade): void {
                $mock->shouldReceive('requiresCoordinator')
                    ->twice()
                    ->with(\Mockery::on(
                        fn (ServiceUpgrade $candidate): bool =>
                            $candidate->is($upgrade)
                    ))
                    ->andReturnFalse();
            }
        );

        app(MarkInvoicePaidService::class)->handle($invoice);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
    }

    private function upgradeInvoice(string $upgradeStatus): array
    {
        $user = User::factory()->create();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => $service->currency_code,
            'due_at' => now()->addDays(7),
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'invoice_id' => $invoice->id,
            'status' => $upgradeStatus,
            'type' => 'product',
        ]);
        $invoice->items()->create([
            'reference_type' => ServiceUpgrade::class,
            'reference_id' => $upgrade->id,
            'price' => 10,
            'quantity' => 1,
            'description' => 'Resource upgrade',
        ]);

        return [$invoice->fresh('items'), $upgrade];
    }

    /**
     * @param  list<string>  $markers
     */
    private function assertOrderedSourceMarkers(
        string $source,
        array $markers
    ): void {
        $previous = -1;
        foreach ($markers as $marker) {
            $position = strpos($source, $marker);
            $this->assertNotFalse(
                $position,
                "Missing lock-order marker: {$marker}"
            );
            $this->assertGreaterThan(
                $previous,
                $position,
                "Lock-order marker is out of order: {$marker}"
            );
            $previous = $position;
        }
    }
}
