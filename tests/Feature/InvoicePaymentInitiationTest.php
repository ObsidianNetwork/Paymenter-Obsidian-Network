<?php

namespace Paymenter\Extensions\Gateways\ClaimFake {

    use App\Classes\Extension\Gateway;
    use App\Models\BillingAgreement;
    use App\Models\BillingChargeAttempt;
    use App\Models\Invoice;
    use App\Models\InvoicePaymentInitiation;
    use App\Services\Invoice\InvoicePaymentInitiationService;

    class ClaimFake extends Gateway
    {
        public static int $interactiveCalls = 0;

        public static int $savedCalls = 0;

        /** @var list<string> */
        public static array $idempotencyKeys = [];

        public static string $reconciliationStatus = 'open';

        public static string $providerStatus = 'created';

        public static ?string $reconciliationTransactionId = null;

        public static function reset(): void
        {
            self::$interactiveCalls = 0;
            self::$savedCalls = 0;
            self::$idempotencyKeys = [];
            self::$reconciliationStatus = 'open';
            self::$providerStatus = 'created';
            self::$reconciliationTransactionId = null;
        }

        public function pay(Invoice $invoice, $total)
        {
            return '/legacy-pay';
        }

        public function supportsDurablePaymentInitiations(): bool
        {
            return true;
        }

        public function payInvoiceInitiation(
            InvoicePaymentInitiation $initiation
        ): mixed {
            self::$interactiveCalls++;
            self::$idempotencyKeys[] =
                (string) $initiation->idempotency_key;
            $reference = "provider-payment-{$initiation->id}";
            app(InvoicePaymentInitiationService::class)
                ->recordProviderReference(
                    $initiation,
                    $reference
                );

            return "/provider/{$reference}";
        }

        public function reconcileInvoicePaymentInitiation(
            InvoicePaymentInitiation $initiation,
            bool $cancelIfSafe = false
        ): array {
            return [
                'provider_reference' => (string) $initiation->provider_reference,
                'provider_transaction_id' => self::$reconciliationTransactionId,
                'provider_status' => self::$providerStatus,
                'evidence_status' => self::$reconciliationStatus,
                'amount' => (string) $initiation->amount,
                'currency_code' => (string) $initiation->currency_code,
                'fee' => null,
                'message' => self::$reconciliationStatus === 'failed'
                        ? 'Provider object is terminal.'
                        : null,
            ];
        }

        public function supportsBillingAgreements(): bool
        {
            return true;
        }

        public function supportsDurableBillingAttempts(): bool
        {
            return true;
        }

        public function supportsCustomerInitiatedBillingAttempts(): bool
        {
            return true;
        }

        public function billingAttemptProviderCustomerReference(
            BillingAgreement $billingAgreement
        ): ?string {
            return null;
        }

        public function chargeBillingAttempt(
            BillingChargeAttempt $attempt
        ): array {
            self::$savedCalls++;

            return [
                'provider_reference' => "saved-charge-{$attempt->id}",
                'provider_transaction_id' => "saved-transaction-{$attempt->id}",
                'provider_status' => 'processing',
                'evidence_status' => 'processing',
            ];
        }
    }
}

namespace Paymenter\Extensions\Gateways\UnsafeClaimFake {

    use App\Classes\Extension\Gateway;
    use App\Models\Invoice;

    class UnsafeClaimFake extends Gateway
    {
        public static int $payCalls = 0;

        public function pay(Invoice $invoice, $total)
        {
            self::$payCalls++;

            return '/unsafe-provider';
        }
    }
}

namespace Tests\Feature {

    use App\Enums\InvoiceTransactionStatus;
    use App\Helpers\ExtensionHelper;
    use App\Models\BillingAgreement;
    use App\Models\BillingChargeAttempt;
    use App\Models\Gateway;
    use App\Models\Invoice;
    use App\Models\InvoicePaymentInitiation;
    use App\Models\Setting;
    use App\Models\User;
    use App\Services\Invoice\CancelInvoiceService;
    use App\Services\Invoice\InvoicePaymentInitiationService;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;
    use Paymenter\Extensions\Gateways\ClaimFake\ClaimFake;
    use Paymenter\Extensions\Gateways\UnsafeClaimFake\UnsafeClaimFake;
    use Tests\TestCase;

    class InvoicePaymentInitiationTest extends TestCase
    {
        use RefreshDatabase;

        protected function setUp(): void
        {
            parent::setUp();
            ClaimFake::reset();
            UnsafeClaimFake::$payCalls = 0;
        }

        public function test_repeated_provider_open_reuses_one_claim_and_key(): void
        {
            $fixture = $this->createFixture();

            $first = ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $second = ExtensionHelper::pay(
                $fixture['gateway']->fresh(),
                $fixture['invoice']->fresh()
            );

            $this->assertSame($first, $second);
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                $claim->status
            );
            $this->assertSame(
                "provider-payment-{$claim->id}",
                $claim->provider_reference
            );
            $this->assertSame(2, ClaimFake::$interactiveCalls);
            $this->assertCount(
                1,
                array_unique(ClaimFake::$idempotencyKeys)
            );
        }

        public function test_provider_claim_blocks_saved_method_charge(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );

            $this->assertBlocked(
                fn () => ExtensionHelper::charge(
                    $fixture['gateway']->fresh(),
                    $fixture['invoice']->fresh(),
                    $fixture['agreement']->fresh()
                ),
                'provider payment initiation'
            );
            $this->assertFalse(
                BillingChargeAttempt::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->exists()
            );
            $this->assertSame(0, ClaimFake::$savedCalls);
        }

        public function test_repeated_execution_fails_closed_when_invoice_enters_external_attention(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            $fixture['invoice']->forceFill([
                'payment_attention_required_at' => now(),
                'payment_attention_reason' => 'Unrelated operator review.',
            ])->save();

            $this->assertBlocked(
                fn () => app(
                    InvoicePaymentInitiationService::class
                )->execute($claim),
                'changed or entered payment review'
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $claim->fresh()->status
            );
        }

        public function test_saved_method_claim_blocks_provider_open(): void
        {
            $fixture = $this->createFixture();
            $this->assertTrue(
                ExtensionHelper::charge(
                    $fixture['gateway'],
                    $fixture['invoice'],
                    $fixture['agreement']
                )
            );

            $this->assertBlocked(
                fn () => ExtensionHelper::pay(
                    $fixture['gateway']->fresh(),
                    $fixture['invoice']->fresh()
                ),
                'saved-payment-method charge'
            );
            $this->assertFalse(
                InvoicePaymentInitiation::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->exists()
            );
            $this->assertSame(0, ClaimFake::$interactiveCalls);
        }

        public function test_provider_evidence_settles_the_exact_claim(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();

            ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: (string) $claim->provider_reference,
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );

            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_SUCCEEDED,
                $claim->status
            );
            $this->assertSame(
                (string) $claim->provider_reference,
                (string) $claim->provider_transaction_id
            );
            $this->assertNull($claim->active_invoice_id);
            $this->assertSame(
                Invoice::STATUS_PAID,
                $fixture['invoice']->fresh()->status
            );
            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $fixture['invoice']->transactions()
                    ->sole()
                    ->status
            );

            ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: (string) $claim->provider_reference,
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );
            $this->assertSame(
                1,
                $fixture['invoice']->transactions()->count(),
                'An exact callback replay for a closed succeeded generation must be read-only.'
            );
        }

        public function test_failed_provider_evidence_stays_blocked_for_reconciliation(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();

            ExtensionHelper::addFailedPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: (string) $claim->provider_reference
            );

            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $claim->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertBlocked(
                fn () => ExtensionHelper::charge(
                    $fixture['gateway']->fresh(),
                    $fixture['invoice']->fresh(),
                    $fixture['agreement']->fresh()
                ),
                'payment review'
            );
        }

        public function test_unresolved_provider_claim_blocks_invoice_cancellation(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );

            $this->assertBlocked(
                fn () => app(CancelInvoiceService::class)
                    ->handle($fixture['invoice']),
                'provider payment initiation'
            );
            $this->assertSame(
                Invoice::STATUS_PENDING,
                $fixture['invoice']->fresh()->status
            );
        }

        public function test_terminal_provider_failure_releases_only_the_active_slot_and_preserves_history(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $first = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            ClaimFake::$reconciliationStatus = 'failed';
            ClaimFake::$providerStatus = 'canceled';

            $this->assertSame(
                'released',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($first, true)
            );
            $first->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_FAILED,
                $first->status
            );
            $this->assertNull($first->active_invoice_id);
            $this->assertNotNull($first->released_at);

            ExtensionHelper::pay(
                $fixture['gateway']->fresh(),
                $fixture['invoice']->fresh()
            );
            $claims = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->orderBy('generation')
                ->get();
            $this->assertCount(2, $claims);
            $this->assertSame(1, $claims[0]->generation);
            $this->assertSame(2, $claims[1]->generation);
            $this->assertNull($claims[0]->active_invoice_id);
            $this->assertSame(
                $fixture['invoice']->id,
                $claims[1]->active_invoice_id
            );
        }

        public function test_exact_failed_replay_for_released_generation_is_a_no_op(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            ClaimFake::$reconciliationStatus = 'failed';
            ClaimFake::$providerStatus = 'canceled';
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true);

            ExtensionHelper::addFailedPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );

            $this->assertSame(
                0,
                $fixture['invoice']->transactions()->count()
            );
            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_FAILED,
                $claim->fresh()->status
            );
        }

        public function test_late_success_for_released_generation_is_retained_under_attention_and_quarantines_the_new_generation(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $first = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            ClaimFake::$reconciliationStatus = 'failed';
            ClaimFake::$providerStatus = 'canceled';
            app(InvoicePaymentInitiationService::class)
                ->reconcile($first, true);
            ExtensionHelper::pay(
                $fixture['gateway']->fresh(),
                $fixture['invoice']->fresh()
            );
            $second = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->where('generation', 2)
                ->sole();

            ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'late-capture-one',
                providerCurrency: 'USD',
                providerResourceReference: (string) $first->provider_reference
            );

            $invoice = $fixture['invoice']->fresh();
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
            $this->assertNotNull(
                $invoice->payment_attention_required_at
            );
            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $invoice->transactions()
                    ->where(
                        'transaction_id',
                        'late-capture-one'
                    )
                    ->sole()
                    ->status
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $first->fresh()->status
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $second->fresh()->status
            );
            $this->assertSame(
                $invoice->id,
                $second->fresh()->active_invoice_id
            );
        }

        public function test_claim_owned_failed_attention_can_clear_only_after_terminal_provider_proof(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            ExtensionHelper::addFailedPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'failed-capture',
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );

            ClaimFake::$reconciliationStatus = 'failed';
            ClaimFake::$providerStatus = 'canceled';
            ClaimFake::$reconciliationTransactionId =
                'failed-capture';
            $this->assertSame(
                'released',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );

            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertSame(
                InvoiceTransactionStatus::Failed,
                $fixture['invoice']->transactions()
                    ->where(
                        'transaction_id',
                        'failed-capture'
                    )
                    ->sole()
                    ->status
            );
            $this->assertNull($claim->fresh()->active_invoice_id);
        }

        public function test_conflicting_provider_evidence_is_persisted_without_settling_the_invoice(): void
        {
            $cases = [
                'wrong gateway' => function (
                    array $fixture,
                    InvoicePaymentInitiation $claim
                ): void {
                    $otherGateway = $this->createGateway(
                        'Other claim fake',
                        'ClaimFake'
                    );
                    ExtensionHelper::addPayment(
                        $fixture['invoice']->id,
                        $otherGateway,
                        '10.00',
                        transactionId: 'wrong-gateway-capture',
                        providerCurrency: 'USD',
                        providerResourceReference: (string) $claim
                            ->provider_reference
                    );
                },
                'wrong amount' => function (
                    array $fixture,
                    InvoicePaymentInitiation $claim
                ): void {
                    ExtensionHelper::addPayment(
                        $fixture['invoice']->id,
                        $fixture['gateway'],
                        '11.00',
                        transactionId: 'wrong-amount-capture',
                        providerCurrency: 'USD',
                        providerResourceReference: (string) $claim
                            ->provider_reference
                    );
                },
                'wrong currency' => function (
                    array $fixture,
                    InvoicePaymentInitiation $claim
                ): void {
                    ExtensionHelper::addPayment(
                        $fixture['invoice']->id,
                        $fixture['gateway'],
                        '10.00',
                        transactionId: 'wrong-currency-capture',
                        providerCurrency: 'EUR',
                        providerResourceReference: (string) $claim
                            ->provider_reference
                    );
                },
            ];

            foreach ($cases as $label => $recordEvidence) {
                $fixture = $this->createFixture();
                ExtensionHelper::pay(
                    $fixture['gateway'],
                    $fixture['invoice']
                );
                $claim = InvoicePaymentInitiation::query()
                    ->where(
                        'invoice_id',
                        $fixture['invoice']->id
                    )
                    ->sole();

                $recordEvidence($fixture, $claim);

                $invoice = $fixture['invoice']->fresh();
                $this->assertSame(
                    Invoice::STATUS_PENDING,
                    $invoice->status,
                    $label
                );
                $this->assertNotNull(
                    $invoice->payment_attention_required_at,
                    $label
                );
                $this->assertSame(
                    InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                    $claim->fresh()->status,
                    $label
                );
                $this->assertSame(
                    1,
                    $invoice->transactions()->count(),
                    $label
                );
                $this->assertSame(
                    InvoiceTransactionStatus::Succeeded,
                    $invoice->transactions()->sole()->status,
                    $label
                );
            }
        }

        public function test_different_transaction_after_processing_is_persisted_under_attention(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            ExtensionHelper::addProcessingPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'expected-capture',
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );

            ExtensionHelper::addPayment(
                $fixture['invoice']->id,
                $fixture['gateway'],
                '10.00',
                transactionId: 'different-capture',
                providerCurrency: 'USD',
                providerResourceReference: (string) $claim->provider_reference
            );

            $invoice = $fixture['invoice']->fresh();
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
            $this->assertNotNull(
                $invoice->payment_attention_required_at
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $claim->fresh()->status
            );
            $this->assertSame(2, $invoice->transactions()->count());
            $this->assertSame(
                InvoiceTransactionStatus::Succeeded,
                $invoice->transactions()
                    ->where(
                        'transaction_id',
                        'different-capture'
                    )
                    ->sole()
                    ->status
            );
        }

        public function test_duplicate_provider_reference_quarantines_both_generations(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $first = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            $secondInvoice = $this->createInvoice(
                $fixture['user']
            );
            $second = app(
                InvoicePaymentInitiationService::class
            )->create($secondInvoice, $fixture['gateway']);

            $this->assertBlocked(
                fn () => app(
                    InvoicePaymentInitiationService::class
                )->recordProviderReference(
                    $second,
                    (string) $first->provider_reference
                ),
                'already belongs'
            );

            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $first->fresh()->status
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $second->fresh()->status
            );
            $this->assertNotNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
            $this->assertNotNull(
                $secondInvoice->fresh()
                    ->payment_attention_required_at
            );
        }

        public function test_signed_generation_metadata_recovers_a_lost_create_response_identity(): void
        {
            $fixture = $this->createFixture();
            $claim = app(
                InvoicePaymentInitiationService::class
            )->create(
                $fixture['invoice'],
                $fixture['gateway']
            );
            $this->assertNull($claim->provider_reference);

            $this->assertTrue(
                app(
                    InvoicePaymentInitiationService::class
                )->verifyProviderGenerationMetadata(
                    $fixture['invoice']->id,
                    'ClaimFake',
                    'provider-recovered-from-signed-metadata',
                    $claim->id,
                    $claim->idempotency_key
                )
            );
            $this->assertSame(
                'provider-recovered-from-signed-metadata',
                $claim->fresh()->provider_reference
            );
            $this->assertNull(
                $fixture['invoice']->fresh()
                    ->payment_attention_required_at
            );
        }

        public function test_server_side_capture_preflight_rejects_invoice_drift(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $fixture['invoice']->id)
                ->sole();
            DB::table('invoice_items')
                ->where('invoice_id', $fixture['invoice']->id)
                ->update(['price' => '11.00']);

            $this->assertBlocked(
                fn () => app(
                    InvoicePaymentInitiationService::class
                )->assertProviderCompletionAllowed(
                    $fixture['invoice'],
                    'ClaimFake',
                    (string) $claim->provider_reference
                ),
                'amount or currency changed'
            );
        }

        public function test_gateway_setting_locks_parent_before_claim_checks_and_cannot_mutate_active_generation(): void
        {
            $fixture = $this->createFixture();
            $destination = $this->createGateway(
                'Second claim fake',
                'ClaimFake'
            );
            DB::table('settings')->insert([
                'key' => 'provider_api_key',
                'value' => 'original',
                'type' => 'string',
                'encrypted' => false,
                'settingable_id' => $fixture['gateway']->id,
                'settingable_type' => $fixture['gateway']
                    ->getMorphClass(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $setting = Setting::query()
                ->where('settingable_id', $fixture['gateway']->id)
                ->where('key', 'provider_api_key')
                ->sole();
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $sql = strtolower($query->sql);
                if (
                    str_contains($sql, 'extensions')
                    || str_contains(
                        $sql,
                        'billing_charge_attempts'
                    )
                    || str_contains(
                        $sql,
                        'invoice_payment_initiations'
                    )
                ) {
                    $queries[] = [
                        'sql' => $sql,
                        'bindings' => $query->bindings,
                    ];
                }
            });

            $setting->settingable_id = $destination->id;
            $setting->value = 'rotated';
            $setting->save();

            $gatewayLock = collect($queries)
                ->search(
                    fn (array $query): bool => str_contains(
                        $query['sql'],
                        'extensions'
                    )
                        && str_contains(
                            $query['sql'],
                            'order by'
                        )
                );
            $billingClaimCheck = collect($queries)
                ->search(
                    fn (array $query): bool => str_contains(
                        $query['sql'],
                        'billing_charge_attempts'
                    )
                );
            $interactiveClaimCheck = collect($queries)
                ->search(
                    fn (array $query): bool => str_contains(
                        $query['sql'],
                        'invoice_payment_initiations'
                    )
                );
            $this->assertIsInt($gatewayLock);
            $this->assertIsInt($billingClaimCheck);
            $this->assertIsInt($interactiveClaimCheck);
            $this->assertLessThan(
                $billingClaimCheck,
                $gatewayLock
            );
            $this->assertLessThan(
                $interactiveClaimCheck,
                $gatewayLock
            );
            $this->assertSame(
                [
                    $fixture['gateway']->id,
                    $destination->id,
                ],
                collect($queries[$gatewayLock]['bindings'])
                    ->filter(fn ($binding): bool => is_int($binding))
                    ->values()
                    ->all()
            );
            if (
                DB::connection()->getDriverName() !== 'sqlite'
            ) {
                $this->assertStringContainsString(
                    'for update',
                    $queries[$gatewayLock]['sql']
                );
            }

            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );
            $setting->settingable_id = $fixture['gateway']->id;
            $setting->value = 'forbidden';
            $this->assertBlocked(
                fn () => $setting->save(),
                'pinned by provider payment initiation'
            );
            $this->assertSame(
                'rotated',
                (string) $setting->fresh()->value
            );
            $this->assertSame(
                $destination->id,
                (int) $setting->fresh()->settingable_id
            );
        }

        public function test_gateway_lifecycle_mutation_locks_parent_before_claim_checks(): void
        {
            $fixture = $this->createFixture();
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $sql = strtolower($query->sql);
                if (
                    str_contains($sql, 'extensions')
                    || str_contains(
                        $sql,
                        'billing_charge_attempts'
                    )
                    || str_contains(
                        $sql,
                        'invoice_payment_initiations'
                    )
                ) {
                    $queries[] = $sql;
                }
            });

            $fixture['gateway']->extension = 'ClaimFakeRenamed';
            $fixture['gateway']->save();

            $gatewayLock = collect($queries)
                ->search(
                    fn (string $sql): bool => str_starts_with(
                        ltrim($sql),
                        'select'
                    )
                        && str_contains($sql, 'extensions')
                        && str_contains($sql, 'order by')
                );
            $billingClaimCheck = collect($queries)
                ->search(
                    fn (string $sql): bool => str_contains(
                        $sql,
                        'billing_charge_attempts'
                    )
                );
            $interactiveClaimCheck = collect($queries)
                ->search(
                    fn (string $sql): bool => str_contains(
                        $sql,
                        'invoice_payment_initiations'
                    )
                );
            $this->assertIsInt($gatewayLock);
            $this->assertIsInt($billingClaimCheck);
            $this->assertIsInt($interactiveClaimCheck);
            $this->assertLessThan(
                $billingClaimCheck,
                $gatewayLock
            );
            $this->assertLessThan(
                $interactiveClaimCheck,
                $gatewayLock
            );
            if (
                DB::connection()->getDriverName() !== 'sqlite'
            ) {
                $this->assertStringContainsString(
                    'for update',
                    $queries[$gatewayLock]
                );
            }
            $this->assertSame(
                'ClaimFakeRenamed',
                (string) DB::table('extensions')
                    ->where('id', $fixture['gateway']->id)
                    ->value('extension')
            );
        }

        public function test_active_generation_blocks_gateway_disable_identity_changes_and_delete(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );

            $gateway = Gateway::query()
                ->whereKey($fixture['gateway']->id)
                ->sole();
            $gateway->enabled = false;
            $this->assertBlocked(
                fn () => $gateway->save(),
                'pinned by provider payment initiation'
            );

            $gateway = Gateway::query()
                ->whereKey($fixture['gateway']->id)
                ->sole();
            $gateway->extension = 'ClaimFakeRenamed';
            $this->assertBlocked(
                fn () => $gateway->save(),
                'pinned by provider payment initiation'
            );

            $gateway = Gateway::query()
                ->whereKey($fixture['gateway']->id)
                ->sole();
            $gateway->type = 'server';
            $this->assertBlocked(
                fn () => $gateway->save(),
                'pinned by provider payment initiation'
            );

            $gateway = Gateway::query()
                ->whereKey($fixture['gateway']->id)
                ->sole();
            $this->assertBlocked(
                fn () => $gateway->delete(),
                'pinned by provider payment initiation'
            );

            $persisted = DB::table('extensions')
                ->where('id', $fixture['gateway']->id)
                ->sole();
            $this->assertTrue((bool) $persisted->enabled);
            $this->assertSame(
                'ClaimFake',
                (string) $persisted->extension
            );
            $this->assertSame('gateway', (string) $persisted->type);
            $this->assertNull($persisted->deleted_at);
        }

        public function test_unsafe_interactive_gateway_is_hidden_and_blocked_before_provider_execution(): void
        {
            $fixture = $this->createFixture();
            $unsafe = $this->createGateway(
                'Unsafe claim fake',
                'UnsafeClaimFake'
            );

            foreach (['cart', 'invoice', 'credits'] as $type) {
                $this->assertFalse(
                    collect(
                        ExtensionHelper::getCheckoutGateways(
                            '10.00',
                            'USD',
                            $type
                        )
                    )->contains(
                        fn (Gateway $gateway): bool => (int) $gateway->id
                                === (int) $unsafe->id
                    ),
                    $type
                );
            }

            $this->assertBlocked(
                fn () => ExtensionHelper::pay(
                    $unsafe,
                    $fixture['invoice']
                ),
                'cannot safely create'
            );
            $this->assertSame(0, UnsafeClaimFake::$payCalls);
            $this->assertFalse(
                InvoicePaymentInitiation::query()
                    ->where('invoice_id', $fixture['invoice']->id)
                    ->exists()
            );
        }

        public function test_wrong_provider_reference_is_rejected(): void
        {
            $fixture = $this->createFixture();
            ExtensionHelper::pay(
                $fixture['gateway'],
                $fixture['invoice']
            );

            $this->assertBlocked(
                fn () => app(
                    InvoicePaymentInitiationService::class
                )->assertProviderReferenceMatches(
                    $fixture['invoice'],
                    'ClaimFake',
                    'provider-payment-other'
                ),
                'does not belong'
            );
        }

        /**
         * @return array{
         *   user: User,
         *   gateway: Gateway,
         *   agreement: BillingAgreement,
         *   invoice: Invoice
         * }
         */
        private function createFixture(): array
        {
            $user = User::factory()->create();
            $gateway = $this->createGateway(
                'Claim fake',
                'ClaimFake'
            );
            $agreement = BillingAgreement::create([
                'user_id' => $user->id,
                'gateway_id' => $gateway->id,
                'name' => 'Saved method',
                'external_reference' => 'saved-method',
                'type' => 'card',
            ]);
            $invoice = $this->createInvoice($user);

            return compact(
                'user',
                'gateway',
                'agreement',
                'invoice'
            );
        }

        private function createGateway(
            string $name,
            string $extension
        ): Gateway {
            $gateway = Gateway::create([
                'name' => $name,
                'extension' => $extension,
                'type' => 'gateway',
                'enabled' => false,
            ]);
            DB::table('extensions')
                ->where('id', $gateway->id)
                ->update(['enabled' => true]);

            return $gateway->fresh();
        }

        private function createInvoice(User $user): Invoice
        {
            $invoice = Invoice::factory()->create([
                'user_id' => $user->id,
                'status' => Invoice::STATUS_PENDING,
                'currency_code' => 'USD',
                'due_at' => now()->addDay(),
            ]);
            $invoice->items()->create([
                'price' => '10.00',
                'quantity' => 1,
                'description' => 'Provider claim test',
            ]);

            return $invoice;
        }

        private function assertBlocked(
            callable $callback,
            string $messageFragment
        ): void {
            try {
                $callback();
                $this->fail('Expected the payment action to be blocked.');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString(
                    strtolower($messageFragment),
                    strtolower($exception->getMessage())
                );
            }
        }
    }
}
