<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\User;
use App\Services\Invoice\InvoicePaymentInitiationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InteractiveGatewayReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mollie_terminal_state_releases_only_after_exact_generation_proof(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'Mollie',
            ['api_key' => 'test_mollie_reconcile'],
            'tr_mollie_terminal'
        );
        Http::fake([
            'https://api.mollie.com/v2/payments/tr_mollie_terminal' => Http::response([
                'id' => 'tr_mollie_terminal',
                'status' => 'canceled',
                'isCancelable' => false,
                'amount' => [
                    'value' => '10.00',
                    'currency' => 'USD',
                ],
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_payment_initiation_id' => $claim->id,
                ],
            ]),
        ]);

        $this->assertSame(
            'released',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_FAILED,
            $claim->status
        );
        $this->assertNull($claim->active_invoice_id);
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_mollie_wrong_currency_never_releases_or_settles_the_generation(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'Mollie',
            ['api_key' => 'test_mollie_wrong_currency'],
            'tr_mollie_wrong_currency'
        );
        Http::fake([
            'https://api.mollie.com/v2/payments/tr_mollie_wrong_currency' => Http::response([
                'id' => 'tr_mollie_wrong_currency',
                'status' => 'paid',
                'amount' => [
                    'value' => '10.00',
                    'currency' => 'EUR',
                ],
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_payment_initiation_id' => $claim->id,
                ],
            ]),
        ]);

        $this->assertSame(
            'reconciled',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            $claim->status
        );
        $this->assertSame(
            $invoice->id,
            $claim->active_invoice_id
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_paypal_voided_order_releases_the_exact_generation(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'PayPal',
            [
                'client_id' => 'paypal_client',
                'client_secret' => 'paypal_secret',
                'test_mode' => '0',
            ],
            'PAYPAL-VOIDED-ORDER'
        );
        $this->fakePayPalOrder($invoice, $claim, 'VOIDED');

        $this->assertSame(
            'released',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_FAILED,
            $claim->status
        );
        $this->assertNull($claim->active_invoice_id);
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    public function test_paypal_voided_order_with_financial_or_malformed_payments_retains_generation(): void
    {
        $paymentSets = [
            [
                'authorizations' => [[
                    'id' => 'PAYPAL-VOIDED-AUTHORIZATION',
                    'status' => 'PENDING',
                ]],
            ],
            'malformed-payments',
            ['captures' => 'malformed-captures'],
        ];

        foreach ($paymentSets as $index => $payments) {
            [$invoice, $claim] = $this->createClaim(
                'PayPal',
                [
                    'client_id' => "paypal_client_voided_evidence_{$index}",
                    'client_secret' => "paypal_secret_voided_evidence_{$index}",
                    'test_mode' => '0',
                ],
                "PAYPAL-VOIDED-EVIDENCE-{$index}"
            );
            $this->fakePayPalOrder(
                $invoice,
                $claim,
                'VOIDED',
                ['payments' => $payments]
            );

            $this->assertSame(
                'reconciled',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );
            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $claim->status
            );
            $this->assertSame(
                $invoice->id,
                $claim->active_invoice_id
            );
            $this->assertTrue(
                (bool) $claim->attention_reconcilable
            );
            $this->assertNotNull(
                $invoice->fresh()->payment_attention_required_at
            );
            $this->assertSame(
                0,
                $invoice->transactions()->count()
            );

            $this->fakePayPalOrder(
                $invoice,
                $claim,
                'VOIDED'
            );
            $this->assertSame(
                'released',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );
            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_FAILED,
                $claim->status
            );
            $this->assertNull($claim->active_invoice_id);
        }
    }

    public function test_paypal_approved_order_remains_open_even_after_abandonment(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'PayPal',
            [
                'client_id' => 'paypal_client_open',
                'client_secret' => 'paypal_secret_open',
                'test_mode' => '0',
            ],
            'PAYPAL-APPROVED-ORDER'
        );
        $this->fakePayPalOrder($invoice, $claim, 'APPROVED');

        $this->assertSame(
            'reconciled',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
            $claim->status
        );
        $this->assertSame(
            $invoice->id,
            $claim->active_invoice_id
        );
        $this->assertSame('APPROVED', $claim->provider_status);
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    public function test_paypal_capture_free_order_past_maximum_validity_releases(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'PayPal',
            [
                'client_id' => 'paypal_client_expired',
                'client_secret' => 'paypal_secret_expired',
                'test_mode' => '0',
            ],
            'PAYPAL-EXPIRED-ORDER'
        );
        $this->fakePayPalOrder(
            $invoice,
            $claim,
            'APPROVED',
            [
                'create_time' => now()
                    ->subHours(73)
                    ->toIso8601String(),
            ]
        );

        $this->assertSame(
            'released',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_FAILED,
            $claim->status
        );
        $this->assertNull($claim->active_invoice_id);
        $this->assertStringContainsString(
            'maximum settlement validity',
            (string) $claim->last_error
        );
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_paypal_missing_or_malformed_create_time_retains_generation_for_attention(): void
    {
        foreach ([
            ['include_create_time' => false],
            ['create_time' => 'not-a-provider-timestamp'],
        ] as $index => $options) {
            [$invoice, $claim] = $this->createClaim(
                'PayPal',
                [
                    'client_id' => "paypal_client_timestamp_{$index}",
                    'client_secret' => "paypal_secret_timestamp_{$index}",
                    'test_mode' => '0',
                ],
                "PAYPAL-TIMESTAMP-{$index}"
            );
            $this->fakePayPalOrder(
                $invoice,
                $claim,
                'APPROVED',
                $options
            );

            $this->assertSame(
                'reconciled',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );
            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
                $claim->status
            );
            $this->assertSame(
                $invoice->id,
                $claim->active_invoice_id
            );
            $this->assertTrue(
                (bool) $claim->attention_reconcilable
            );
            $this->assertStringContainsString(
                'missing or malformed creation timestamp',
                (string) $claim->last_error
            );
            $this->assertNotNull(
                $invoice->fresh()->payment_attention_required_at
            );
            $this->assertSame(
                0,
                $invoice->transactions()->count()
            );

            $this->fakePayPalOrder(
                $invoice,
                $claim,
                'APPROVED'
            );
            $this->assertSame(
                'reconciled',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );
            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
                $claim->status
            );
            $this->assertSame(
                $invoice->id,
                $claim->active_invoice_id
            );
            $this->assertFalse(
                (bool) $claim->attention_reconcilable
            );
            $this->assertNull($claim->attention_reason);
            $this->assertNull($claim->last_error);
            $this->assertNull(
                $invoice->fresh()->payment_attention_required_at
            );
        }
    }

    public function test_paypal_non_capture_financial_evidence_never_releases_expired_order(): void
    {
        [$invoice, $claim] = $this->createClaim(
            'PayPal',
            [
                'client_id' => 'paypal_client_evidence',
                'client_secret' => 'paypal_secret_evidence',
                'test_mode' => '0',
            ],
            'PAYPAL-FINANCIAL-EVIDENCE'
        );
        $this->fakePayPalOrder(
            $invoice,
            $claim,
            'APPROVED',
            [
                'create_time' => now()
                    ->subDays(7)
                    ->toIso8601String(),
                'payments' => [
                    'authorizations' => [[
                        'id' => 'PAYPAL-AUTHORIZATION',
                        'status' => 'PENDING',
                    ]],
                ],
            ]
        );

        $this->assertSame(
            'reconciled',
            app(InvoicePaymentInitiationService::class)
                ->reconcile($claim, true)
        );
        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            $claim->status
        );
        $this->assertSame($invoice->id, $claim->active_invoice_id);
        $this->assertTrue((bool) $claim->attention_reconcilable);
        $this->assertStringContainsString(
            'financial evidence',
            (string) $claim->last_error
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_paypal_provider_valid_timestamp_variants_release_expired_capture_free_orders(): void
    {
        $expiredAt = now()->subDays(7);
        $timestamps = [
            strtolower($expiredAt->format('Y-m-d\TH:i:s\Z')),
            '2016-12-31T23:59:60Z',
            $expiredAt->format('Y-m-d\TH:i:s')
                . '.'
                . str_repeat('1', 43)
                . 'Z',
        ];
        $this->assertSame(64, strlen($timestamps[2]));

        foreach ($timestamps as $index => $timestamp) {
            [$invoice, $claim] = $this->createClaim(
                'PayPal',
                [
                    'client_id' => "paypal_client_timestamp_variant_{$index}",
                    'client_secret' => "paypal_secret_timestamp_variant_{$index}",
                    'test_mode' => '0',
                ],
                "PAYPAL-TIMESTAMP-VARIANT-{$index}"
            );
            $this->fakePayPalOrder(
                $invoice,
                $claim,
                'APPROVED',
                ['create_time' => $timestamp]
            );

            $this->assertSame(
                'released',
                app(InvoicePaymentInitiationService::class)
                    ->reconcile($claim, true)
            );
            $claim->refresh();
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_FAILED,
                $claim->status
            );
            $this->assertNull($claim->active_invoice_id);
            $this->assertSame(
                0,
                $invoice->transactions()->count()
            );
        }
    }

    /**
     * @param  array<string, string>  $settings
     * @return array{0: Invoice, 1: InvoicePaymentInitiation}
     */
    private function createClaim(
        string $extension,
        array $settings,
        string $providerReference
    ): array {
        $gateway = Gateway::create([
            'name' => "{$extension} reconciliation",
            'extension' => $extension,
            'type' => 'gateway',
            'enabled' => false,
        ]);
        foreach ($settings as $key => $value) {
            $gateway->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }
        DB::table('extensions')
            ->where('id', $gateway->id)
            ->update(['enabled' => true]);
        $gateway = $gateway->fresh();
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
            'due_at' => now()->addDay(),
        ]);
        $invoice->items()->create([
            'price' => '10.00',
            'quantity' => 1,
            'description' => "{$extension} reconciliation",
        ]);
        $claim = app(
            InvoicePaymentInitiationService::class
        )->create($invoice, $gateway);
        app(InvoicePaymentInitiationService::class)
            ->recordProviderReference(
                $claim,
                $providerReference
            );
        $claim->forceFill([
            'status' => InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
            'provider_status' => 'provider_pending',
            'initiated_at' => now(),
        ])->save();

        return [$invoice->fresh(), $claim->fresh()];
    }

    /**
     * @param  array{
     *   include_create_time?: bool,
     *   create_time?: string,
     *   payments?: array<string, mixed>
     * }  $options
     */
    private function fakePayPalOrder(
        Invoice $invoice,
        InvoicePaymentInitiation $claim,
        string $status,
        array $options = []
    ): void {
        Http::fake(function (HttpRequest $request) use (
            $invoice,
            $claim,
            $status,
            $options
        ) {
            if (
                $request->method() === 'POST'
                && $request->url()
                    === 'https://api-m.paypal.com/v1/oauth2/token'
            ) {
                return Http::response([
                    'access_token' => 'paypal_access_token',
                ]);
            }
            if (
                $request->method() === 'GET'
                && $request->url()
                    === 'https://api-m.paypal.com/v2/checkout/orders/'
                        . $claim->provider_reference
            ) {
                $order = [
                    'id' => $claim->provider_reference,
                    'intent' => 'CAPTURE',
                    'status' => $status,
                    'purchase_units' => [[
                        'invoice_id' => (string) $invoice->id,
                        'custom_id' => 'paymenter-initiation:'
                            . $claim->id
                            . ':'
                            . $claim->idempotency_key,
                        'amount' => [
                            'value' => '10.00',
                            'currency_code' => 'USD',
                        ],
                    ]],
                ];
                if (
                    ($options['include_create_time'] ?? true) === true
                ) {
                    $order['create_time'] =
                        $options['create_time']
                        ?? now()->toIso8601String();
                }
                if (array_key_exists('payments', $options)) {
                    $order['purchase_units'][0]['payments'] =
                        $options['payments'];
                }

                return Http::response($order);
            }

            return Http::response([
                'message' => 'Unexpected PayPal request.',
            ], 500);
        });
    }
}
