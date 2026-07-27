<?php

namespace Paymenter\Extensions\Gateways\DurableFake {

    use App\Classes\Extension\Gateway;
    use App\Models\BillingAgreement;
    use App\Models\BillingChargeAttempt;
    use App\Models\Invoice;

    class DurableFake extends Gateway
    {
        public static int $calls = 0;

        /** @var null|callable(BillingChargeAttempt): array */
        public static $handler = null;

        /** @var list<string> */
        public static array $idempotencyKeys = [];

        public static ?string $providerCustomerReference = null;

        public static function reset(): void
        {
            self::$calls = 0;
            self::$handler = null;
            self::$idempotencyKeys = [];
            self::$providerCustomerReference = null;
        }

        public function pay(Invoice $invoice, $total)
        {
            return '';
        }

        public function chargeBillingAttempt(
            BillingChargeAttempt $attempt
        ): array {
            self::$calls++;
            self::$idempotencyKeys[] =
                (string) $attempt->idempotency_key;
            if (is_callable(self::$handler)) {
                return (self::$handler)($attempt);
            }

            return [
                'provider_reference' => "fake-charge-{$attempt->id}",
                'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                'provider_status' => 'processing',
                'evidence_status' => 'processing',
            ];
        }

        public function billingAttemptProviderCustomerReference(
            BillingAgreement $billingAgreement
        ): ?string {
            return self::$providerCustomerReference;
        }

        public function supportsDurableBillingAttempts(): bool
        {
            return true;
        }

        public function supportsBillingAgreements(): bool
        {
            return true;
        }

        public function supportsCustomerInitiatedBillingAttempts(): bool
        {
            return true;
        }
    }
}

namespace Paymenter\Extensions\Gateways\LegacyFake {

    use App\Classes\Extension\Gateway;
    use App\Models\Invoice;

    class LegacyFake extends Gateway
    {
        public function pay(Invoice $invoice, $total)
        {
            return '';
        }
    }
}

namespace Tests\Feature {

    use App\Enums\InvoiceTransactionStatus;
    use App\Helpers\ExtensionHelper;
    use App\Jobs\ProcessBillingChargeAttemptJob;
    use App\Livewire\Invoices\Show;
    use App\Models\BillingAgreement;
    use App\Models\BillingChargeAttempt;
    use App\Models\Gateway;
    use App\Models\Invoice;
    use App\Models\Service;
    use App\Models\User;
    use App\Services\Invoice\BillingChargeAttemptService;
    use App\Services\Invoice\CancelInvoiceService;
    use Illuminate\Database\QueryException;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Queue;
    use Illuminate\Support\Str;
    use Livewire\Livewire;
    use Paymenter\Extensions\Gateways\DurableFake\DurableFake;
    use Tests\TestCase;

    class BillingChargeAttemptTest extends TestCase
    {
        use RefreshDatabase;

        protected function setUp(): void
        {
            parent::setUp();
            DurableFake::reset();
        }

        public function test_failed_dispatch_is_recovered_by_the_scanner(): void
        {
            $fixture = $this->createBillingFixture();
            Queue::shouldReceive('push')
                ->once()
                ->andThrow(new \RuntimeException('broker unavailable'));

            try {
                app(BillingChargeAttemptService::class)->dispatchById(
                    $fixture['attempt']->id
                );
                $this->fail('Expected the queue write to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'broker unavailable',
                    $exception->getMessage()
                );
            }

            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(
                BillingChargeAttempt::STATUS_PENDING,
                $attempt->status
            );
            $this->assertStringContainsString(
                'broker unavailable',
                (string) $attempt->last_error
            );
            $this->assertTrue($attempt->available_at->isFuture());

            $this->travel(61)->seconds();
            Queue::fake();
            $summary = app(BillingChargeAttemptService::class)
                ->recover();

            $this->assertSame(1, $summary['scanned']);
            $this->assertSame(1, $summary['dispatched']);
            $this->assertSame(0, $summary['failed']);
            Queue::assertPushed(
                ProcessBillingChargeAttemptJob::class,
                fn (ProcessBillingChargeAttemptJob $job): bool => $job->attemptId === $attempt->id
            );
        }

        public function test_retry_reuses_the_frozen_idempotency_key(): void
        {
            $fixture = $this->createBillingFixture();
            DurableFake::$handler = function (
                BillingChargeAttempt $attempt
            ): array {
                if (DurableFake::$calls === 1) {
                    throw new \RuntimeException(
                        'connection closed after request'
                    );
                }

                return [
                    'provider_reference' => "fake-charge-{$attempt->id}",
                    'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                    'provider_status' => 'processing',
                    'evidence_status' => 'processing',
                ];
            };
            $attempts = app(BillingChargeAttemptService::class);

            $this->assertFalse(
                $attempts->process($fixture['attempt']->id)
            );
            $this->assertSame(
                BillingChargeAttempt::STATUS_RETRYABLE,
                $fixture['attempt']->fresh()->status
            );
            $fixture['attempt']->fresh()->forceFill([
                'available_at' => now()->subSecond(),
            ])->save();

            $this->assertTrue(
                $attempts->process($fixture['attempt']->id)
            );

            $this->assertSame(2, DurableFake::$calls);
            $this->assertCount(
                1,
                array_unique(DurableFake::$idempotencyKeys)
            );
            $this->assertSame(
                (string) $fixture['attempt']->idempotency_key,
                DurableFake::$idempotencyKeys[0]
            );
            $this->assertSame(
                BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                $fixture['attempt']->fresh()->status
            );
            $this->assertSame(
                1,
                $fixture['invoice']->transactions()->count()
            );
        }

        public function test_exhausted_transport_retries_require_reconciliation(): void
        {
            $fixture = $this->createBillingFixture();
            DurableFake::$handler = static function (
                BillingChargeAttempt $attempt
            ): array {
                throw new \RuntimeException(
                    'connection closed after request'
                );
            };
            $attempts = app(BillingChargeAttemptService::class);

            for ($retry = 0; $retry < 8; $retry++) {
                $this->assertFalse(
                    $attempts->process($fixture['attempt']->id)
                );
                $current = $fixture['attempt']->fresh();
                if ($retry < 7) {
                    $this->assertSame(
                        BillingChargeAttempt::STATUS_RETRYABLE,
                        $current->status
                    );
                    $current->forceFill([
                        'available_at' => now()->subSecond(),
                    ])->save();
                }
            }

            $this->assertSame(8, DurableFake::$calls);
            $this->assertSame(
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                $fixture['attempt']->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertBlocked(
                fn () => ExtensionHelper::pay(
                    $fixture['gateway'],
                    $fixture['invoice']->fresh()
                ),
                'not eligible for provider payment initiation'
            );
        }

        public function test_verified_provider_poll_resets_consecutive_failure_count(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                'attempt_count' => 7,
                'provider_reference' => 'fake-charge-existing',
                'provider_transaction_id' => 'fake-transaction-existing',
                'provider_status' => 'processing',
                'available_at' => now()->subSecond(),
            ]);
            DurableFake::$handler = static function (
                BillingChargeAttempt $attempt
            ): array {
                return [
                    'provider_reference' => (string) $attempt->provider_reference,
                    'provider_transaction_id' => (string) $attempt->provider_transaction_id,
                    'provider_status' => 'processing',
                    'evidence_status' => 'processing',
                ];
            };

            $this->assertTrue(
                app(BillingChargeAttemptService::class)
                    ->process($fixture['attempt']->id)
            );

            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(
                BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                $attempt->status
            );
            $this->assertSame(
                BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
                $attempt->purpose
            );
            $this->assertSame(0, $attempt->attempt_count);
            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
        }

        public function test_renewal_creation_freezes_provider_customer_identity(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();
            DurableFake::$providerCustomerReference = 'customer-original';
            Queue::fake();

            $attempt = DB::transaction(
                fn () => app(BillingChargeAttemptService::class)
                    ->createForRenewal(
                        $fixture['invoice']->fresh(),
                        $fixture['agreement']->fresh()
                    )
            );

            $this->assertSame(
                'customer-original',
                $attempt->provider_customer_reference
            );
            DurableFake::$providerCustomerReference = 'customer-rotated';
            $attempt->provider_customer_reference = 'customer-rotated';
            try {
                $attempt->save();
                $this->fail(
                    'Expected the frozen provider customer to be immutable.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'immutable',
                    strtolower($exception->getMessage())
                );
            }
            $this->assertSame(
                'customer-original',
                $attempt->fresh()->provider_customer_reference
            );
        }

        public function test_provider_evidence_identities_are_immutable_once_frozen(): void
        {
            $fixture = $this->createBillingFixture([
                'provider_reference' => 'charge-original',
                'provider_transaction_id' => 'transaction-original',
            ]);

            foreach ([
                'provider_reference' => 'charge-replacement',
                'provider_transaction_id' => 'transaction-replacement',
            ] as $attribute => $replacement) {
                $this->assertBlocked(
                    function () use (
                        $fixture,
                        $attribute,
                        $replacement
                    ): void {
                        $attempt = $fixture['attempt']->fresh();
                        $attempt->{$attribute} = $replacement;
                        $attempt->save();
                    },
                    'immutable'
                );
            }

            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(
                'charge-original',
                $attempt->provider_reference
            );
            $this->assertSame(
                'transaction-original',
                $attempt->provider_transaction_id
            );
        }

        public function test_provider_evidence_identity_cannot_belong_to_two_attempts(): void
        {
            $first = $this->createBillingFixture([
                'provider_reference' => 'provider-resource-unique',
                'provider_transaction_id' => 'provider-transaction-unique',
            ]);
            $second = $this->createBillingFixture();

            try {
                DB::table('billing_charge_attempts')
                    ->where('id', $second['attempt']->id)
                    ->update([
                        'gateway_snapshot_id' => $first['gateway']->id,
                        'provider_reference' => 'provider-resource-unique',
                    ]);
                $this->fail(
                    'A provider resource was attached to two billing attempts.'
                );
            } catch (QueryException) {
                // The database is the final cross-request identity guard.
            }

            try {
                DB::table('billing_charge_attempts')
                    ->where('id', $second['attempt']->id)
                    ->update([
                        'gateway_snapshot_id' => $first['gateway']->id,
                        'provider_transaction_id' => 'provider-transaction-unique',
                    ]);
                $this->fail(
                    'A provider transaction was attached to two billing attempts.'
                );
            } catch (QueryException) {
                // The first rejected update was atomic on both supported DBs.
            }
        }

        public function test_unsupported_gateway_is_rejected_before_attempt_creation(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();
            DB::table('extensions')
                ->where('id', $fixture['gateway']->id)
                ->update(['extension' => 'LegacyFake']);

            $reported = null;
            try {
                DB::transaction(
                    fn () => app(
                        BillingChargeAttemptService::class
                    )->createForRenewal(
                        $fixture['invoice']->fresh(),
                        $fixture['agreement']->fresh()
                    )
                );
            } catch (\RuntimeException $exception) {
                $reported = $exception->getMessage();
            }

            $this->assertIsString($reported);
            $this->assertStringContainsString(
                'durable automatic charge',
                $reported
            );
            $this->assertFalse(
                app(BillingChargeAttemptService::class)
                    ->hasAttempt($fixture['invoice'])
            );
        }

        public function test_reentrant_worker_or_manual_charge_cannot_submit_twice(): void
        {
            $fixture = $this->createBillingFixture();
            DurableFake::$handler = function (
                BillingChargeAttempt $attempt
            ) use ($fixture): array {
                $this->assertTrue(
                    app(BillingChargeAttemptService::class)
                        ->chargeSavedMethod(
                            $fixture['invoice']->fresh(),
                            $fixture['agreement']->fresh()
                        )
                );

                return [
                    'provider_reference' => "fake-charge-{$attempt->id}",
                    'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                    'provider_status' => 'processing',
                    'evidence_status' => 'processing',
                ];
            };

            $this->assertTrue(
                app(BillingChargeAttemptService::class)
                    ->process($fixture['attempt']->id)
            );

            $this->assertSame(1, DurableFake::$calls);
            $this->assertSame(
                BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                $fixture['attempt']->fresh()->status
            );
        }

        public function test_manual_click_reuses_existing_automatic_renewal_attempt(): void
        {
            $fixture = $this->createBillingFixture();
            $attemptId = (int) $fixture['attempt']->id;
            $idempotencyKey =
                (string) $fixture['attempt']->idempotency_key;

            $this->assertTrue(
                ExtensionHelper::charge(
                    $fixture['gateway'],
                    $fixture['invoice']->fresh(),
                    $fixture['agreement']->fresh()
                )
            );

            $attempt = BillingChargeAttempt::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            $this->assertSame($attemptId, (int) $attempt->id);
            $this->assertSame(
                $idempotencyKey,
                (string) $attempt->idempotency_key
            );
            $this->assertSame(
                BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
                $attempt->purpose
            );
            $this->assertSame(1, DurableFake::$calls);
        }

        public function test_manual_saved_method_creates_durable_attempt_before_provider_submission(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();
            DurableFake::$handler = function (
                BillingChargeAttempt $attempt
            ) use ($fixture): array {
                $frozen = BillingChargeAttempt::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->firstOrFail();
                $this->assertSame($attempt->id, $frozen->id);
                $this->assertSame(
                    BillingChargeAttempt::STATUS_LEASED,
                    $frozen->status
                );

                return [
                    'provider_reference' => "fake-charge-{$attempt->id}",
                    'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                    'provider_status' => 'processing',
                    'evidence_status' => 'processing',
                ];
            };

            $this->assertTrue(
                ExtensionHelper::charge(
                    $fixture['gateway'],
                    $fixture['invoice']->fresh(),
                    $fixture['agreement']->fresh()
                )
            );

            $attempt = BillingChargeAttempt::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            $this->assertSame(1, DurableFake::$calls);
            $this->assertSame(
                BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                $attempt->status
            );
            $this->assertSame(
                (string) $attempt->idempotency_key,
                DurableFake::$idempotencyKeys[0]
            );
        }

        public function test_repeated_manual_saved_method_click_reuses_one_attempt(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();

            foreach (range(1, 2) as $_) {
                $this->assertTrue(
                    ExtensionHelper::charge(
                        $fixture['gateway'],
                        $fixture['invoice']->fresh(),
                        $fixture['agreement']->fresh()
                    )
                );
            }

            $this->assertSame(
                1,
                BillingChargeAttempt::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->count()
            );
            $this->assertSame(1, DurableFake::$calls);
            $this->assertCount(
                1,
                array_unique(DurableFake::$idempotencyKeys)
            );
        }

        public function test_livewire_manual_saved_method_creates_attempt_on_first_click(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();
            $this->actingAs($fixture['user']);
            session($this->loginUser($fixture['user']));

            Livewire::test(Show::class, [
                'invoice' => $fixture['invoice']->fresh(),
            ])
                ->set(
                    'selectedMethod',
                    $fixture['agreement']->ulid
                )
                ->call('processPayment');

            $this->assertSame(
                1,
                BillingChargeAttempt::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->count()
            );
            $this->assertSame(1, DurableFake::$calls);
        }

        public function test_manual_saved_method_rejects_legacy_gateway_without_fallback(): void
        {
            $fixture = $this->createBillingFixture();
            DB::table('billing_charge_attempts')
                ->where('id', $fixture['attempt']->id)
                ->delete();
            DB::table('extensions')
                ->where('id', $fixture['gateway']->id)
                ->update(['extension' => 'LegacyFake']);

            $this->assertBlocked(
                fn () => ExtensionHelper::charge(
                    $fixture['gateway']->fresh(),
                    $fixture['invoice']->fresh(),
                    $fixture['agreement']->fresh()
                ),
                'customer-initiated'
            );
            $this->assertFalse(
                BillingChargeAttempt::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->exists()
            );
            $this->assertSame(0, DurableFake::$calls);
        }

        public function test_webhook_wins_without_a_late_response_downgrade(): void
        {
            $fixture = $this->createBillingFixture();
            DurableFake::$handler = function (
                BillingChargeAttempt $attempt
            ) use ($fixture): array {
                ExtensionHelper::addPayment(
                    $fixture['invoice']->id,
                    $fixture['gateway'],
                    $attempt->amount,
                    transactionId: "fake-transaction-{$attempt->id}",
                    billingChargeAttemptId: $attempt->id
                );

                return [
                    'provider_reference' => "fake-charge-{$attempt->id}",
                    'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                    'provider_status' => 'processing',
                    'evidence_status' => 'processing',
                ];
            };

            $this->assertTrue(
                app(BillingChargeAttemptService::class)
                    ->process($fixture['attempt']->id)
            );

            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(
                BillingChargeAttempt::STATUS_SUCCEEDED,
                $attempt->status
            );
            $this->assertSame(
                "fake-charge-{$attempt->id}",
                $attempt->provider_reference
            );
            $this->assertNotNull($attempt->settled_at);
            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $fixture['invoice']->transactions()->first()->status
            );
        }

        public function test_lifecycle_mutations_are_blocked_while_charge_is_unresolved(): void
        {
            $fixture = $this->createBillingFixture();
            $attemptId = $fixture['attempt']->id;

            $this->assertBlocked(
                fn () => app(CancelInvoiceService::class)
                    ->handle($fixture['invoice']),
                "invoice {$fixture['invoice']->id}"
            );
            $this->assertBlocked(
                fn () => $fixture['invoice']->delete(),
                'durable'
            );
            $this->assertBlocked(
                fn () => $fixture['attempt']->delete(),
                'durable payment evidence'
            );
            $this->assertBlocked(
                fn () => $fixture['agreement']->delete(),
                (string) $attemptId
            );
            $this->assertBlocked(function () use ($fixture): void {
                $agreement = $fixture['agreement']->fresh();
                $agreement->external_reference = 'replacement-method';
                $agreement->save();
            }, (string) $attemptId);
            $this->assertBlocked(function () use ($fixture): void {
                $gateway = $fixture['gateway']->fresh();
                $gateway->enabled = false;
                $gateway->save();
            }, (string) $attemptId);
            $this->assertBlocked(function () use ($fixture): void {
                $gateway = $fixture['gateway']->fresh();
                $gateway->extension = 'OtherGateway';
                $gateway->save();
            }, (string) $attemptId);
            $this->assertBlocked(function () use ($fixture): void {
                $service = $fixture['service']->fresh();
                $service->status = Service::STATUS_CANCELLED;
                $service->save();
            }, (string) $attemptId);
            $this->assertBlocked(
                fn () => ExtensionHelper::terminateServer(
                    $fixture['service']->fresh()
                ),
                (string) $attemptId
            );
        }

        public function test_paid_or_cancelled_invoice_never_reaches_provider(): void
        {
            foreach ([
                Invoice::STATUS_PAID,
                Invoice::STATUS_CANCELLED,
            ] as $invoiceStatus) {
                $fixture = $this->createBillingFixture();
                DB::table('invoices')
                    ->where('id', $fixture['invoice']->id)
                    ->update(['status' => $invoiceStatus]);

                $this->assertFalse(
                    app(BillingChargeAttemptService::class)
                        ->process($fixture['attempt']->id)
                );
                $this->assertSame(
                    BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                    $fixture['attempt']->fresh()->status
                );
            }

            $this->assertSame(0, DurableFake::$calls);
        }

        public function test_failed_attempt_releases_invoice_for_a_new_payment(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'provider_reference' => 'failed-charge',
                'provider_transaction_id' => 'failed-transaction',
                'provider_status' => 'failed',
            ]);
            $manualGateway = $this->createGateway('ManualFake');

            ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $manualGateway,
                '10.00',
                transactionId: 'manual-recovery-payment'
            );

            $this->assertSame(
                Invoice::STATUS_PAID,
                $fixture['invoice']->fresh()->status
            );
            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                BillingChargeAttempt::STATUS_FAILED,
                $fixture['attempt']->fresh()->status
            );
        }

        public function test_payment_method_is_revoked_locally_before_provider_cleanup(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'provider_reference' => 'failed-charge',
                'provider_transaction_id' => 'failed-transaction',
                'provider_status' => 'failed',
            ]);

            try {
                ExtensionHelper::cancelBillingAgreement(
                    $fixture['agreement']
                );
                $this->fail(
                    'The fake provider should reject remote cleanup.'
                );
            } catch (\Exception $exception) {
                $this->assertSame(
                    'Not implemented',
                    $exception->getMessage()
                );
            }

            $this->assertNotNull(
                BillingAgreement::withTrashed()
                    ->findOrFail($fixture['agreement']->id)
                    ->deleted_at
            );
            $this->assertNull(
                $fixture['service']->fresh()->billing_agreement_id
            );
        }

        public function test_payment_method_is_revoked_when_gateway_code_is_missing(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'provider_reference' => 'failed-charge',
                'provider_transaction_id' => 'failed-transaction',
                'provider_status' => 'failed',
            ]);
            DB::table('extensions')
                ->where('id', $fixture['gateway']->id)
                ->update(['extension' => 'MissingGateway']);

            $reported = false;
            try {
                ExtensionHelper::cancelBillingAgreement(
                    $fixture['agreement']
                );
            } catch (\Throwable) {
                $reported = true;
                // Local revocation must not depend on loading provider code.
            }
            $this->assertTrue(
                $reported,
                'The missing provider extension should be reported.'
            );

            $this->assertNotNull(
                BillingAgreement::withTrashed()
                    ->findOrFail($fixture['agreement']->id)
                    ->deleted_at
            );
            $this->assertNull(
                $fixture['service']->fresh()->billing_agreement_id
            );
        }

        public function test_soft_deleted_payment_method_can_still_be_force_deleted(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'provider_reference' => 'failed-charge',
                'provider_transaction_id' => 'failed-transaction',
                'provider_status' => 'failed',
            ]);
            $agreementId = (int) $fixture['agreement']->id;

            $fixture['agreement']->delete();
            $this->assertTrue(
                BillingAgreement::withTrashed()
                    ->findOrFail($agreementId)
                    ->trashed()
            );

            BillingAgreement::withTrashed()
                ->findOrFail($agreementId)
                ->forceDelete();

            $this->assertNull(
                BillingAgreement::withTrashed()->find($agreementId)
            );
            $this->assertNull(
                $fixture['attempt']->fresh()->billing_agreement_id
            );
        }

        public function test_provider_deletion_preserves_unresolved_charge_for_review(): void
        {
            $fixture = $this->createBillingFixture();

            app(BillingChargeAttemptService::class)
                ->providerPaymentMethodDeleted(
                    $fixture['agreement']
                );

            $this->assertSame(
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                $fixture['attempt']->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertNotNull(
                BillingAgreement::withTrashed()
                    ->findOrFail($fixture['agreement']->id)
                    ->deleted_at
            );
            $this->assertNull(
                $fixture['service']->fresh()->billing_agreement_id
            );
        }

        public function test_provider_deletion_during_request_keeps_late_payment_evidence(): void
        {
            $fixture = $this->createBillingFixture();
            DurableFake::$handler = function (
                BillingChargeAttempt $attempt
            ) use ($fixture): array {
                app(BillingChargeAttemptService::class)
                    ->providerPaymentMethodDeleted(
                        $fixture['agreement']->fresh()
                    );

                return [
                    'provider_reference' => "fake-charge-{$attempt->id}",
                    'provider_transaction_id' => "fake-transaction-{$attempt->id}",
                    'provider_status' => 'succeeded',
                    'evidence_status' => 'succeeded',
                ];
            };

            $this->assertTrue(
                app(BillingChargeAttemptService::class)
                    ->process($fixture['attempt']->id)
            );

            $attempt = $fixture['attempt']->fresh();
            $this->assertSame(
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                $attempt->status
            );
            $this->assertSame(
                "fake-charge-{$attempt->id}",
                $attempt->provider_reference
            );
            $this->assertSame(
                "fake-transaction-{$attempt->id}",
                $attempt->provider_transaction_id
            );
            $this->assertSame(
                1,
                $fixture['invoice']->transactions()
                    ->where(
                        'status',
                        InvoiceTransactionStatus::Succeeded->value
                    )
                    ->count()
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                Invoice::STATUS_PENDING,
                $fixture['invoice']->fresh()->status
            );
        }

        public function test_post_cancel_provider_evidence_is_preserved_and_replay_is_read_only(): void
        {
            $fixture = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'provider_reference' => 'late-charge',
                'provider_transaction_id' => 'late-transaction',
                'provider_status' => 'failed',
            ]);
            app(CancelInvoiceService::class)
                ->handle($fixture['invoice']);
            $this->assertSame(
                Invoice::STATUS_CANCELLED,
                $fixture['invoice']->fresh()->status
            );

            $first = ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'late-transaction',
                billingChargeAttemptId: $fixture['attempt']->id
            );

            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $first->status
            );
            $this->assertSame(
                Invoice::STATUS_CANCELLED,
                $fixture['invoice']->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                $fixture['attempt']->fresh()->status
            );
            $rawUpdatedAt = $fixture['attempt']->fresh()
                ->getRawOriginal('updated_at');

            $this->travel(2)->seconds();
            $replay = ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'late-transaction',
                billingChargeAttemptId: $fixture['attempt']->id
            );

            $this->assertTrue($first->is($replay));
            $this->assertSame(
                1,
                $fixture['invoice']->transactions()->count()
            );
            $this->assertSame(
                $rawUpdatedAt,
                $fixture['attempt']->fresh()
                    ->getRawOriginal('updated_at')
            );
        }

        public function test_unbound_gateway_evidence_is_preserved_for_review(): void
        {
            $fixture = $this->createBillingFixture();

            $transaction = ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'unbound-provider-payment'
            );

            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $transaction->status
            );
            $this->assertSame(
                BillingChargeAttempt::STATUS_NEEDS_ATTENTION,
                $fixture['attempt']->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
        }

        public function test_only_confirmed_settlements_increment_charge_metric(): void
        {
            $pending = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_PROVIDER_PENDING,
                'provider_reference' => 'pending-charge',
                'provider_transaction_id' => 'pending-transaction',
                'provider_status' => 'processing',
            ]);
            $settled = $this->createBillingFixture([
                'status' => BillingChargeAttempt::STATUS_SUCCEEDED,
                'provider_reference' => 'settled-charge',
                'provider_transaction_id' => 'settled-transaction',
                'provider_status' => 'succeeded',
                'settled_at' => now(),
            ]);
            $attempts = app(BillingChargeAttemptService::class);

            $this->assertSame(1, $attempts->claimUnreportedSettlements());
            $this->assertNull(
                $pending['attempt']->fresh()->metric_recorded_at
            );
            $this->assertNotNull(
                $settled['attempt']->fresh()->metric_recorded_at
            );
            $this->assertSame(0, $attempts->claimUnreportedSettlements());
        }

        /**
         * @param  array<string, mixed>  $attemptOverrides
         * @return array{
         *   user: User,
         *   gateway: Gateway,
         *   agreement: BillingAgreement,
         *   service: Service,
         *   invoice: Invoice,
         *   attempt: BillingChargeAttempt
         * }
         */
        private function createBillingFixture(
            array $attemptOverrides = []
        ): array {
            $user = User::factory()->create();
            $gateway = $this->createGateway('DurableFake');
            $agreement = BillingAgreement::create([
                'user_id' => $user->id,
                'gateway_id' => $gateway->id,
                'name' => 'Test saved method',
                'external_reference' => 'saved-method-' . (string) Str::uuid(),
                'type' => 'card',
            ]);
            $product = $this->createProduct();
            $service = Service::factory()->create([
                'user_id' => $user->id,
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'billing_agreement_id' => $agreement->id,
                'status' => Service::STATUS_ACTIVE,
                'expires_at' => now()->addDay(),
                'currency_code' => 'USD',
                'price' => '10.00',
                'quantity' => 1,
            ]);
            $invoice = Invoice::factory()->create([
                'user_id' => $user->id,
                'status' => Invoice::STATUS_PENDING,
                'currency_code' => 'USD',
                'due_at' => $service->expires_at,
            ]);
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'price' => '10.00',
                'quantity' => 1,
                'description' => 'Automatic renewal',
            ]);
            $attempt = BillingChargeAttempt::create(array_merge([
                'invoice_id' => $invoice->id,
                'billing_agreement_id' => $agreement->id,
                'billing_agreement_snapshot_id' => $agreement->id,
                'billing_agreement_reference' => $agreement->external_reference,
                'gateway_id' => $gateway->id,
                'gateway_snapshot_id' => $gateway->id,
                'gateway_extension' => $gateway->extension,
                'purpose' => BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
                'amount' => '10.00',
                'currency_code' => 'USD',
                'idempotency_key' => (string) Str::uuid(),
                'status' => BillingChargeAttempt::STATUS_PENDING,
                'available_at' => now(),
            ], $attemptOverrides));

            return compact(
                'user',
                'gateway',
                'agreement',
                'service',
                'invoice',
                'attempt'
            );
        }

        private function createGateway(string $extension): Gateway
        {
            $gateway = Gateway::create([
                'name' => "{$extension} gateway",
                'extension' => $extension,
                'type' => 'gateway',
                'enabled' => false,
            ]);
            DB::table('extensions')
                ->where('id', $gateway->id)
                ->update(['enabled' => true]);

            return $gateway->fresh();
        }

        private function assertBlocked(
            callable $callback,
            string $messageFragment
        ): void {
            try {
                $callback();
                $this->fail('Expected the lifecycle mutation to be blocked.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    strtolower($messageFragment),
                    strtolower($exception->getMessage())
                );
            }
        }
    }
}
