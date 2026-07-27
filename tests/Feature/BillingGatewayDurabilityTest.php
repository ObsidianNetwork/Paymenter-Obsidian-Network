<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

class BillingGatewayDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_paypal_setup_declares_recurring_charge_consent(): void
    {
        $user = User::factory()->create();
        if (
            !Route::has(
                'extensions.gateways.paypal.setup-agreement'
            )
        ) {
            Route::get(
                '/test-paypal-setup-agreement',
                static fn () => null
            )->name(
                'extensions.gateways.paypal.setup-agreement'
            );
        }
        if (!Route::has('account.payment-methods')) {
            Route::get(
                '/test-account-payment-methods',
                static fn () => null
            )->name('account.payment-methods');
        }
        Http::fake(function (HttpRequest $request) {
            if (
                str_ends_with(
                    $request->url(),
                    '/v1/oauth2/token'
                )
            ) {
                return Http::response([
                    'access_token' => 'access-token',
                ]);
            }

            return Http::response([
                'id' => 'setup-token',
                'links' => [
                    [
                        'href' => 'https://api-m.sandbox.paypal.com/setup-token',
                        'rel' => 'self',
                    ],
                    [
                        'href' => 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=setup-token',
                        'rel' => 'approve',
                    ],
                ],
            ]);
        });
        $payPal = new PayPal([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'test_mode' => true,
        ]);

        $approvalUrl = $payPal->createBillingAgreement($user);

        $this->assertStringContainsString(
            'approval_session_id=setup-token',
            $approvalUrl
        );
        $setupRequest = Http::recorded()
            ->map(fn (array $record): HttpRequest => $record[0])
            ->first(
                fn (HttpRequest $request): bool => str_ends_with(
                    $request->url(),
                    '/v3/vault/setup-tokens'
                )
            );
        $this->assertInstanceOf(HttpRequest::class, $setupRequest);
        $this->assertSame(
            'MERCHANT',
            data_get(
                $setupRequest->data(),
                'payment_source.paypal.usage_type'
            )
        );
        $this->assertSame(
            'RECURRING_PREPAID',
            data_get(
                $setupRequest->data(),
                'payment_source.paypal.usage_pattern'
            )
        );
    }

    public function test_stripe_timeout_retry_uses_same_key_and_frozen_customer(): void
    {
        $fixture = $this->createProviderFixture(
            'Stripe',
            '10.29',
            'USD',
            'pm_saved_method',
            'cus_original'
        );
        $fixture['user']->properties()->create([
            'key' => 'stripe_id',
            'value' => 'cus_original',
        ]);
        $fixture['user']->properties()
            ->where('key', 'stripe_id')
            ->update(['value' => 'cus_rotated']);
        $metadata = [
            'invoice_id' => (string) $fixture['invoice']->id,
            'billing_agreement_id' => (string) $fixture['agreement']->id,
            'billing_agreement_reference' => 'pm_saved_method',
            'gateway_id' => (string) $fixture['gateway']->id,
            'billing_charge_attempt_id' => (string) $fixture['attempt']->id,
            'billing_charge_attempt_key' => (string) $fixture['attempt']->idempotency_key,
            'billing_charge_attempt_purpose' => BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
        ];
        Http::fakeSequence()
            ->push(['error' => ['message' => 'temporary']], 500)
            ->push([
                'id' => 'pi_durable_retry',
                'object' => 'payment_intent',
                'amount' => 1029,
                'amount_received' => 0,
                'currency' => 'usd',
                'customer' => 'cus_original',
                'payment_method' => 'pm_saved_method',
                'metadata' => $metadata,
                'status' => 'processing',
            ]);
        $stripe = new Stripe([
            'stripe_secret_key' => 'sk_test_durable',
        ]);

        try {
            $stripe->chargeBillingAttempt($fixture['attempt']);
            $this->fail('Expected the first Stripe request to fail.');
        } catch (RequestException) {
            // The provider response did not identify a PaymentIntent, so the
            // durable core will retry the same immutable request.
        }
        $outcome = $stripe->chargeBillingAttempt(
            $fixture['attempt']->fresh()
        );

        $this->assertSame('processing', $outcome['evidence_status']);
        $this->assertSame(
            'pi_durable_retry',
            $outcome['provider_reference']
        );
        $requests = Http::recorded()
            ->map(fn (array $record): HttpRequest => $record[0]);
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSame(
                (string) $fixture['attempt']->idempotency_key,
                $request->header('Idempotency-Key')[0] ?? null
            );
            $this->assertSame(
                1029,
                $request->data()['amount'] ?? null
            );
            $this->assertSame(
                'cus_original',
                $request->data()['customer'] ?? null
            );
            $this->assertSame(
                (string) $fixture['attempt']->id,
                data_get(
                    $request->data(),
                    'metadata.billing_charge_attempt_id'
                )
            );
        }
    }

    public function test_paypal_non_success_response_throws_and_reuses_request_id(): void
    {
        $fixture = $this->createProviderFixture(
            'PayPal',
            '100.00',
            'JPY',
            'vault-token'
        );
        Http::fake(function (HttpRequest $request) {
            if (
                str_ends_with(
                    $request->url(),
                    '/v1/oauth2/token'
                )
            ) {
                return Http::response([
                    'access_token' => 'access-token',
                ]);
            }

            return Http::response([
                'name' => 'SERVICE_UNAVAILABLE',
            ], 503);
        });
        $payPal = new PayPal([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'test_mode' => true,
        ]);

        for ($retry = 0; $retry < 2; $retry++) {
            try {
                $payPal->chargeBillingAttempt(
                    $fixture['attempt']->fresh()
                );
                $this->fail(
                    'Expected PayPal non-success response to throw.'
                );
            } catch (RequestException $exception) {
                $this->assertSame(
                    503,
                    $exception->response->status()
                );
            }
        }

        $orders = Http::recorded()
            ->map(fn (array $record): HttpRequest => $record[0])
            ->filter(
                fn (HttpRequest $request): bool => str_contains(
                    $request->url(),
                    '/v2/checkout/orders'
                )
            )
            ->values();
        $this->assertCount(2, $orders);
        foreach ($orders as $request) {
            $this->assertSame(
                (string) $fixture['attempt']->idempotency_key,
                $request->header('PayPal-Request-Id')[0] ?? null
            );
            $this->assertSame(
                'return=representation',
                $request->header('Prefer')[0] ?? null
            );
            $this->assertSame(
                '100',
                data_get(
                    $request->data(),
                    'purchase_units.0.amount.value'
                )
            );
            $this->assertSame(
                'paymenter-attempt:'
                    . $fixture['attempt']->id
                    . ':'
                    . $fixture['attempt']->idempotency_key,
                data_get(
                    $request->data(),
                    'purchase_units.0.custom_id'
                )
            );
            $this->assertSame(
                'MERCHANT',
                data_get(
                    $request->data(),
                    'payment_source.paypal.stored_credential.payment_initiator'
                )
            );
            $this->assertSame(
                'SUBSEQUENT',
                data_get(
                    $request->data(),
                    'payment_source.paypal.stored_credential.usage'
                )
            );
            $this->assertSame(
                'RECURRING_PREPAID',
                data_get(
                    $request->data(),
                    'payment_source.paypal.stored_credential.usage_pattern'
                )
            );
        }
    }

    public function test_customer_initiated_paypal_attempt_uses_customer_stored_credential_context(): void
    {
        $fixture = $this->createProviderFixture(
            'PayPal',
            '10.00',
            'USD',
            'vault-token',
            purpose: BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD
        );
        Http::fake(function (HttpRequest $request) {
            if (
                str_ends_with(
                    $request->url(),
                    '/v1/oauth2/token'
                )
            ) {
                return Http::response([
                    'access_token' => 'access-token',
                ]);
            }

            return Http::response([
                'name' => 'SERVICE_UNAVAILABLE',
            ], 503);
        });
        $payPal = new PayPal([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'test_mode' => true,
        ]);

        try {
            $payPal->chargeBillingAttempt($fixture['attempt']);
            $this->fail('Expected PayPal to reject the fake request.');
        } catch (RequestException) {
            // Inspect the immutable request below.
        }

        $request = Http::recorded()
            ->map(fn (array $record): HttpRequest => $record[0])
            ->first(
                fn (HttpRequest $request): bool => str_contains(
                    $request->url(),
                    '/v2/checkout/orders'
                )
            );
        $this->assertInstanceOf(HttpRequest::class, $request);
        $this->assertSame(
            'CUSTOMER',
            data_get(
                $request->data(),
                'payment_source.paypal.stored_credential.payment_initiator'
            )
        );
        $this->assertSame(
            'SUBSEQUENT',
            data_get(
                $request->data(),
                'payment_source.paypal.stored_credential.usage'
            )
        );
        $this->assertNull(
            data_get(
                $request->data(),
                'payment_source.paypal.stored_credential.usage_pattern'
            )
        );
    }

    /**
     * @return array{
     *   user: User,
     *   gateway: Gateway,
     *   agreement: BillingAgreement,
     *   invoice: Invoice,
     *   attempt: BillingChargeAttempt
     * }
     */
    private function createProviderFixture(
        string $extension,
        string $amount,
        string $currency,
        string $billingReference,
        ?string $providerCustomerReference = null,
        string $purpose =
            BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL
    ): array {
        $user = User::factory()->create();
        $gateway = Gateway::create([
            'name' => "{$extension} durable test",
            'extension' => $extension,
            'type' => 'gateway',
            'enabled' => false,
        ]);
        DB::table('extensions')
            ->where('id', $gateway->id)
            ->update(['enabled' => true]);
        $gateway = $gateway->fresh();
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Saved provider method',
            'external_reference' => $billingReference,
            'type' => 'card',
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => $currency,
            'due_at' => now()->addDay(),
        ]);
        $invoice->items()->create([
            'price' => $amount,
            'quantity' => 1,
            'description' => 'Durable provider charge',
        ]);
        $attempt = BillingChargeAttempt::create([
            'invoice_id' => $invoice->id,
            'billing_agreement_id' => $agreement->id,
            'billing_agreement_snapshot_id' => $agreement->id,
            'billing_agreement_reference' => $billingReference,
            'gateway_id' => $gateway->id,
            'gateway_snapshot_id' => $gateway->id,
            'gateway_extension' => $gateway->extension,
            'provider_customer_reference' => $providerCustomerReference,
            'purpose' => $purpose,
            'amount' => $amount,
            'currency_code' => $currency,
            'idempotency_key' => (string) Str::uuid(),
            'status' => BillingChargeAttempt::STATUS_PENDING,
            'available_at' => now(),
        ]);

        return compact(
            'user',
            'gateway',
            'agreement',
            'invoice',
            'attempt'
        );
    }
}
