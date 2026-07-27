<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

class StripeInteractivePaymentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace(
            'gateways.stripe',
            base_path(
                'extensions/Gateways/Stripe/resources/views'
            )
        );
    }

    public function test_interactive_request_and_evidence_use_exact_currency_exponents(): void
    {
        $gateway = $this->createGateway('Stripe', [
            'stripe_secret_key' => 'sk_currency_exponents',
            'stripe_publishable_key' => 'pk_currency_exponents',
            'stripe_webhook_secret' => 'whsec_currency_exponents',
            'stripe_create_customers' => '0',
        ]);
        $cases = [
            'JPY' => [
                'amount' => '123.00',
                'minor' => 123,
                'intent' => 'pi_jpy_exact',
            ],
            'BHD' => [
                'amount' => '10.29',
                'minor' => 1029,
                'intent' => 'pi_bhd_exact',
            ],
        ];
        Http::fake(function (HttpRequest $request) use ($cases) {
            if (
                $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/payment_intents'
            ) {
                $currency = strtoupper(
                    (string) ($request->data()['currency'] ?? '')
                );

                return Http::response([
                    'id' => $cases[$currency]['intent'] ?? 'pi_unknown',
                    'client_secret' => 'secret',
                ]);
            }

            return Http::response([
                'error' => ['message' => 'Unexpected Stripe request'],
            ], 500);
        });

        $fixtures = [];
        foreach ($cases as $currency => $case) {
            $invoice = $this->createInvoice(
                $currency,
                $case['amount']
            );
            ExtensionHelper::pay($gateway->fresh(), $invoice);
            $claim = InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoice->id)
                ->sole();
            $fixtures[$currency] = compact('invoice', 'claim');
        }

        $intentRequests = Http::recorded(
            fn (HttpRequest $request): bool => $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/payment_intents'
        );
        $this->assertCount(2, $intentRequests);
        foreach ($intentRequests as [$request]) {
            $currency = strtoupper(
                (string) $request->data()['currency']
            );
            $claim = $fixtures[$currency]['claim'];
            $this->assertSame(
                $cases[$currency]['minor'],
                $request->data()['amount']
            );
            $this->assertArrayNotHasKey(
                'customer',
                $request->data()
            );
            $this->assertSame(
                (string) $claim->idempotency_key,
                $request->header('Idempotency-Key')[0] ?? null
            );
        }
        Http::assertNotSent(
            fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/customers')
        );

        $stripe = new Stripe([
            'stripe_webhook_secret' => 'whsec_currency_exponents',
        ]);
        foreach ($cases as $currency => $case) {
            $fixture = $fixtures[$currency];
            $stripe->webhook($this->signedStripeRequest([
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $case['intent'],
                        'amount' => $case['minor'],
                        'currency' => strtolower($currency),
                        'metadata' => [
                            'invoice_id' => (string) $fixture['invoice']->id,
                            'invoice_payment_initiation_id' => (string) $fixture['claim']->id,
                            'invoice_payment_initiation_key' => (string) $fixture['claim']
                                ->idempotency_key,
                        ],
                    ],
                ],
            ], 'whsec_currency_exponents'));

            $this->assertSame(
                Invoice::STATUS_PAID,
                $fixture['invoice']->fresh()->status
            );
            $this->assertSame(
                $case['amount'],
                (string) $fixture['invoice']->transactions()
                    ->sole()
                    ->amount
            );
            $this->assertSame(
                InvoicePaymentInitiation::STATUS_SUCCEEDED,
                $fixture['claim']->fresh()->status
            );
        }
    }

    public function test_signed_wrong_payment_intent_is_persisted_for_attention(): void
    {
        $secret = 'whsec_wrong_intent';
        $gateway = $this->createGateway('Stripe', [
            'stripe_secret_key' => 'sk_wrong_intent',
            'stripe_publishable_key' => 'pk_wrong_intent',
            'stripe_webhook_secret' => $secret,
        ]);
        $invoice = $this->createInvoice('USD', '10.00');
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_invoice_owned',
                'client_secret' => 'secret',
            ]),
        ]);
        ExtensionHelper::pay($gateway, $invoice);
        $claim = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoice->id)
            ->sole();

        (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($this->signedStripeRequest([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_stale_or_wrong',
                    'amount' => 1000,
                    'currency' => 'usd',
                    'metadata' => [
                        'invoice_id' => (string) $invoice->id,
                        'invoice_payment_initiation_id' => (string) $claim->id,
                        'invoice_payment_initiation_key' => (string) $claim->idempotency_key,
                    ],
                ],
            ],
        ], $secret));

        $transaction = $invoice->transactions()->sole();
        $this->assertSame(
            'pi_stale_or_wrong',
            (string) $transaction->transaction_id
        );
        $this->assertSame('10.00', (string) $transaction->amount);
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            $claim->fresh()->status
        );
        $this->assertSame(
            'pi_invoice_owned',
            (string) $claim->fresh()->provider_reference
        );
        $this->assertNull(
            $claim->fresh()->provider_transaction_id
        );
        $this->assertStringContainsString(
            'conflicts',
            strtolower((string) $claim->fresh()->last_error)
        );
    }

    public function test_signed_wrong_currency_is_persisted_for_attention(): void
    {
        $secret = 'whsec_wrong_currency';
        $gateway = $this->createGateway('Stripe', [
            'stripe_secret_key' => 'sk_wrong_currency',
            'stripe_publishable_key' => 'pk_wrong_currency',
            'stripe_webhook_secret' => $secret,
        ]);
        $invoice = $this->createInvoice('USD', '10.00');
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_currency_owned',
                'client_secret' => 'secret',
            ]),
        ]);
        ExtensionHelper::pay($gateway, $invoice);
        $claim = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoice->id)
            ->sole();

        (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($this->signedStripeRequest([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_currency_owned',
                    // Ten JPY and ten USD have the same major-unit display
                    // value. The provider currency must still make this
                    // evidence conflict with the frozen USD initiation.
                    'amount' => 10,
                    'currency' => 'jpy',
                    'metadata' => [
                        'invoice_id' => (string) $invoice->id,
                        'invoice_payment_initiation_id' => (string) $claim->id,
                        'invoice_payment_initiation_key' => (string) $claim->idempotency_key,
                    ],
                ],
            ],
        ], $secret));

        $transaction = $invoice->transactions()->sole();
        $this->assertSame(
            'pi_currency_owned',
            (string) $transaction->transaction_id
        );
        $this->assertSame('10.00', (string) $transaction->amount);
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $invoice->fresh()->status
        );
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION,
            $claim->fresh()->status
        );
    }

    public function test_payment_failed_keeps_the_same_reusable_intent_open(): void
    {
        $secret = 'whsec_reusable_failed_intent';
        $gateway = $this->createGateway('Stripe', [
            'stripe_secret_key' => 'sk_reusable_failed_intent',
            'stripe_publishable_key' => 'pk_reusable_failed_intent',
            'stripe_webhook_secret' => $secret,
        ]);
        $invoice = $this->createInvoice('USD', '10.00');
        $claim = null;
        Http::fake(function (HttpRequest $request) use (
            &$claim
        ) {
            if (
                $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/payment_intents'
            ) {
                return Http::response([
                    'id' => 'pi_reusable_failed_intent',
                    'client_secret' => 'secret',
                ]);
            }
            if (
                $request->method() === 'GET'
                && $request->url()
                    === 'https://api.stripe.com/v1/payment_intents/pi_reusable_failed_intent'
            ) {
                return Http::response([
                    'id' => 'pi_reusable_failed_intent',
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'amount' => 1000,
                    'amount_received' => 0,
                    'currency' => 'usd',
                    'metadata' => [
                        'invoice_id' => (string) $claim->invoice_id,
                        'invoice_payment_initiation_id' => (string) $claim->id,
                        'invoice_payment_initiation_key' => (string) $claim->idempotency_key,
                    ],
                ]);
            }

            return Http::response([
                'error' => ['message' => 'Unexpected Stripe request'],
            ], 500);
        });
        ExtensionHelper::pay($gateway, $invoice);
        $claim = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoice->id)
            ->sole();

        (new Stripe([
            'stripe_secret_key' => 'sk_reusable_failed_intent',
            'stripe_webhook_secret' => $secret,
        ]))->webhook($this->signedStripeRequest([
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_reusable_failed_intent',
                    'amount' => 1000,
                    'currency' => 'usd',
                    'metadata' => [
                        'invoice_id' => (string) $invoice->id,
                        'invoice_payment_initiation_id' => (string) $claim->id,
                        'invoice_payment_initiation_key' => (string) $claim->idempotency_key,
                    ],
                ],
            ],
        ], $secret));

        $claim->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_PROVIDER_PENDING,
            $claim->status
        );
        $this->assertSame(
            $invoice->id,
            $claim->active_invoice_id
        );
        $this->assertSame(
            'requires_payment_method',
            $claim->provider_status
        );
        $this->assertNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_stale_processing_webhook_after_success_is_read_only(): void
    {
        $secret = 'whsec_stale_processing';
        $gateway = $this->createGateway('Stripe', [
            'stripe_secret_key' => 'sk_stale_processing',
            'stripe_publishable_key' => 'pk_stale_processing',
            'stripe_webhook_secret' => $secret,
        ]);
        $invoice = $this->createInvoice('USD', '10.00');
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_stale_processing',
                'client_secret' => 'secret',
            ]),
        ]);
        ExtensionHelper::pay($gateway, $invoice);
        $claim = InvoicePaymentInitiation::query()
            ->where('invoice_id', $invoice->id)
            ->sole();
        $paymentIntent = [
            'id' => 'pi_stale_processing',
            'amount' => 1000,
            'currency' => 'usd',
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'invoice_payment_initiation_id' => (string) $claim->id,
                'invoice_payment_initiation_key' => (string) $claim
                    ->idempotency_key,
            ],
        ];
        $stripe = new Stripe([
            'stripe_secret_key' => 'sk_stale_processing',
            'stripe_webhook_secret' => $secret,
        ]);

        $stripe->webhook($this->signedStripeRequest([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => $paymentIntent],
        ], $secret));
        $stripe->webhook($this->signedStripeRequest([
            'type' => 'payment_intent.processing',
            'data' => ['object' => $paymentIntent],
        ], $secret));

        $claim->refresh();
        $invoice->refresh();
        $this->assertSame(
            InvoicePaymentInitiation::STATUS_SUCCEEDED,
            $claim->status
        );
        $this->assertNull($claim->active_invoice_id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNull($invoice->payment_attention_required_at);
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_paypal_ipn_is_rejected_before_generic_provider_initiation(): void
    {
        $gateway = $this->createGateway('PayPal_IPN', [
            'email' => 'payments@example.test',
            'test_mode' => '1',
        ]);
        $invoice = $this->createInvoice('USD', '10.00');
        Http::fake();

        try {
            ExtensionHelper::pay($gateway, $invoice);
            $this->fail(
                'PayPal IPN unexpectedly created a generic provider payment.'
            );
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'does not provide a provider-enforced idempotent payment identity',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            InvoicePaymentInitiation::query()
                ->where('invoice_id', $invoice->id)
                ->exists()
        );
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function createGateway(
        string $extension,
        array $settings
    ): Gateway {
        $gateway = Gateway::create([
            'name' => "{$extension} test",
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

        return $gateway->fresh();
    }

    private function createInvoice(
        string $currency,
        string $amount
    ): Invoice {
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => $currency,
            'due_at' => now()->addDay(),
        ]);
        $invoice->items()->create([
            'price' => $amount,
            'quantity' => 1,
            'description' => "Stripe {$currency} test",
        ]);

        return $invoice->fresh(['items', 'transactions', 'user']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function signedStripeRequest(
        array $event,
        string $secret
    ): Request {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            $secret
        );

        return Request::create(
            '/extensions/stripe/webhook',
            'POST',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload
        );
    }
}
