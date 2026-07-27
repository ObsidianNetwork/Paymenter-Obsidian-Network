<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\CreateGateway;
use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

class StripeGatewaySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            !Route::has(
                'extensions.gateways.stripe.webhook'
            )
        ) {
            Route::post(
                '/test-stripe-webhook',
                static fn () => null
            )->name(
                'extensions.gateways.stripe.webhook'
            );
        }
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_empty_key_cannot_forge_a_stripe_webhook_signature(): void
    {
        $payload = json_encode([
            'type' => 'unhandled.event',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            ''
        );
        $request = Request::create(
            '/extensions/stripe/webhook',
            'POST',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload
        );

        $response = (new Stripe([
            'stripe_webhook_secret' => '',
        ]))->webhook($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Invalid signature'],
            $response->getData(true)
        );
    }

    public function test_whitespace_key_cannot_forge_a_stripe_webhook_signature(): void
    {
        $payload = json_encode([
            'type' => 'unhandled.event',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $secret = " \t\n";
        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            $secret
        );
        $request = Request::create(
            '/extensions/stripe/webhook',
            'POST',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload
        );

        $response = (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_stale_valid_hmac_is_rejected(): void
    {
        $secret = 'whsec_freshness_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'unhandled.event',
                'data' => ['object' => []],
            ],
            $secret,
            now()->subSeconds(301)->timestamp
        );

        $response = (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Invalid signature'],
            $response->getData(true)
        );
    }

    public function test_duplicate_setup_intent_delivery_creates_one_subscription(): void
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $user->properties()->create([
            'key' => 'stripe_id',
            'value' => 'cus_subscription_test',
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
            'price' => '10.00',
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
            'description' => 'Stripe subscription',
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (
                $url
                    === 'https://api.stripe.com/v1/customers/cus_subscription_test'
            ) {
                return Http::response([
                    'id' => 'cus_subscription_test',
                ]);
            }
            if (str_starts_with(
                $url,
                'https://api.stripe.com/v1/products/search'
            )) {
                return Http::response([
                    'data' => [[
                        'id' => 'prod_subscription_test',
                    ]],
                ]);
            }
            if (str_starts_with(
                $url,
                'https://api.stripe.com/v1/subscription_schedules'
            )) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'data' => [],
                        'has_more' => false,
                    ]);
                }

                return Http::response([
                    'id' => 'sub_sched_test',
                    'subscription' => 'sub_subscription_test',
                ]);
            }

            return Http::response([
                'error' => ['message' => 'Unexpected Stripe request'],
            ], 500);
        });

        $setupIntentId = 'seti_subscription_replay_test';
        $secret = 'whsec_subscription_replay_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'setup_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $setupIntentId,
                        'payment_method' => 'pm_subscription_test',
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                        ],
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );
        $stripe = new Stripe([
            'stripe_secret_key' => 'sk_subscription_test',
            'stripe_webhook_secret' => $secret,
        ]);

        $stripe->webhook($request);
        $stripe->webhook($request);

        $this->assertSame(
            'sub_subscription_test',
            $service->fresh()->subscription_id
        );
        $this->assertSame(
            'sub_sched_test',
            $service->fresh()->properties()
                ->where('key', 'stripe_subscription_schedule_id')
                ->value('value')
        );
        $scheduleRequests = Http::recorded(
            fn (HttpRequest $request): bool => $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/subscription_schedules'
        );
        $this->assertCount(1, $scheduleRequests);
        $expectedIdempotencyKey = 'paymenter:setup-subscription:'
            . hash(
                'sha256',
                $setupIntentId . ':' . $service->id
            );
        /** @var HttpRequest $scheduleRequest */
        $scheduleRequest = $scheduleRequests->first()[0];
        $this->assertTrue(
            $scheduleRequest->hasHeader(
                'Idempotency-Key',
                $expectedIdempotencyKey
            )
        );
        $this->assertSame(
            $setupIntentId,
            data_get(
                $scheduleRequest->data(),
                'metadata.setup_intent_id'
            )
        );
    }

    public function test_setup_intent_reconciles_a_remote_schedule_before_posting(): void
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $user->properties()->create([
            'key' => 'stripe_id',
            'value' => 'cus_recovery_test',
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
            'price' => '10.00',
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
            'description' => 'Stripe subscription recovery',
        ]);
        $setupIntentId = 'seti_subscription_recovery_test';

        Http::fake(function (HttpRequest $request) use (
            $service,
            $setupIntentId
        ) {
            $url = $request->url();
            if (
                $url
                    === 'https://api.stripe.com/v1/customers/cus_recovery_test'
            ) {
                return Http::response(['id' => 'cus_recovery_test']);
            }
            if (
                str_starts_with(
                    $url,
                    'https://api.stripe.com/v1/subscription_schedules'
                )
                && $request->method() === 'GET'
            ) {
                if (
                    ($request->data()['starting_after'] ?? null)
                        === 'sub_sched_unrelated'
                ) {
                    return Http::response([
                        'data' => [[
                            'id' => 'sub_sched_recovered',
                            'customer' => 'cus_recovery_test',
                            'subscription' => 'sub_recovered',
                            'status' => 'active',
                            'metadata' => [
                                'service_id' => (string) $service->id,
                                'setup_intent_id' => $setupIntentId,
                            ],
                        ]],
                        'has_more' => false,
                    ]);
                }

                return Http::response([
                    'data' => [[
                        'id' => 'sub_sched_unrelated',
                        'customer' => 'cus_recovery_test',
                        'subscription' => 'sub_unrelated',
                        'status' => 'active',
                        'metadata' => [
                            'service_id' => '999999',
                            'setup_intent_id' => 'seti_unrelated',
                        ],
                    ]],
                    'has_more' => true,
                ]);
            }

            return Http::response([
                'error' => ['message' => 'Unexpected Stripe request'],
            ], 500);
        });

        $secret = 'whsec_subscription_recovery_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'setup_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $setupIntentId,
                        'payment_method' => 'pm_recovery_test',
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                        ],
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );

        (new Stripe([
            'stripe_secret_key' => 'sk_recovery_test',
            'stripe_webhook_secret' => $secret,
        ]))->webhook($request);

        $service->refresh();
        $this->assertSame('sub_recovered', $service->subscription_id);
        $this->assertSame(
            'sub_sched_recovered',
            $service->properties()
                ->where('key', 'stripe_subscription_schedule_id')
                ->value('value')
        );
        Http::assertSent(
            fn (HttpRequest $request): bool => $request->method() === 'GET'
                && $request->url()
                    === 'https://api.stripe.com/v1/subscription_schedules'
                && ($request->data()['starting_after'] ?? null)
                    === 'sub_sched_unrelated'
        );
        Http::assertNotSent(
            fn (HttpRequest $request): bool => $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/subscription_schedules'
        );
    }

    public function test_setup_intent_retry_uses_the_durable_frozen_schedule_payload(): void
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $user->properties()->create([
            'key' => 'stripe_id',
            'value' => 'cus_frozen_attempt_test',
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
            'price' => '10.00',
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
            'description' => 'Stripe frozen subscription attempt',
        ]);

        $schedulePosts = 0;
        Http::fake(function (HttpRequest $request) use (&$schedulePosts) {
            $url = $request->url();
            if (
                $url
                    === 'https://api.stripe.com/v1/customers/cus_frozen_attempt_test'
            ) {
                return Http::response([
                    'id' => 'cus_frozen_attempt_test',
                ]);
            }
            if (
                str_starts_with(
                    $url,
                    'https://api.stripe.com/v1/products/search'
                )
            ) {
                return Http::response([
                    'data' => [[
                        'id' => 'prod_frozen_attempt_test',
                    ]],
                ]);
            }
            if (str_starts_with(
                $url,
                'https://api.stripe.com/v1/subscription_schedules'
            )) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'data' => [],
                        'has_more' => false,
                    ]);
                }

                $schedulePosts++;

                return Http::response(
                    $schedulePosts === 1
                        ? ['id' => 'sub_sched_remote_created']
                        : [
                            'id' => 'sub_sched_frozen_attempt',
                            'subscription' => 'sub_frozen_attempt',
                        ]
                );
            }

            return Http::response([
                'error' => ['message' => 'Unexpected Stripe request'],
            ], 500);
        });

        $setupIntentId = 'seti_frozen_subscription_attempt';
        $secret = 'whsec_frozen_subscription_attempt';
        $request = $this->signedStripeRequest(
            [
                'type' => 'setup_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $setupIntentId,
                        'payment_method' => 'pm_frozen_attempt',
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                        ],
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );
        $stripe = new Stripe([
            'stripe_secret_key' => 'sk_frozen_attempt',
            'stripe_webhook_secret' => $secret,
        ]);

        try {
            $stripe->webhook($request);
            $this->fail(
                'The incomplete remote schedule unexpectedly bound locally.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'complete subscription schedule identities',
                $exception->getMessage()
            );
        }
        $this->assertTrue(
            $service->properties()
                ->where('key', 'stripe_subscription_setup_attempt')
                ->exists()
        );

        // A retry must not rebuild the remote request from mutable live state.
        app(ServiceBillingAnchorMutationCoordinator::class)
            ->update($service, ['price' => '25.00']);
        $stripe->webhook($request);

        $scheduleRequests = Http::recorded(
            fn (HttpRequest $request): bool => $request->method() === 'POST'
                && $request->url()
                    === 'https://api.stripe.com/v1/subscription_schedules'
        );
        $this->assertCount(2, $scheduleRequests);
        $this->assertSame(
            $scheduleRequests[0][0]->data(),
            $scheduleRequests[1][0]->data()
        );
        $service->refresh();
        $this->assertSame(
            'sub_frozen_attempt',
            $service->subscription_id
        );
        $this->assertFalse(
            $service->properties()
                ->where('key', 'stripe_subscription_setup_attempt')
                ->exists()
        );
    }

    public function test_delayed_setup_intent_cannot_revive_ineligible_service_states(): void
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $user->properties()->create([
            'key' => 'stripe_id',
            'value' => 'cus_canceled_setup_test',
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_CANCELLED,
            'currency_code' => 'USD',
            'price' => '10.00',
            'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_CANCELLED,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => '10.00',
            'quantity' => 1,
            'description' => 'Canceled Stripe subscription',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/customers/cus_canceled_setup_test' => Http::response(['id' => 'cus_canceled_setup_test']),
        ]);

        $secret = 'whsec_canceled_setup_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'setup_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'seti_canceled_setup_test',
                        'payment_method' => 'pm_canceled_setup_test',
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                        ],
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );

        $stripe = new Stripe([
            'stripe_secret_key' => 'sk_canceled_setup_test',
            'stripe_webhook_secret' => $secret,
        ]);

        try {
            $stripe->webhook($request);
            $this->fail(
                'A canceled subscription service was revived by a delayed webhook.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'no longer eligible for setup',
                $exception->getMessage()
            );
        }

        $this->assertNull($service->fresh()->subscription_id);

        $invoice->status = Invoice::STATUS_PENDING;
        $invoice->save();
        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($service): void {
                $service->status =
                    Service::STATUS_PROVISIONING_FAILED;
                $service->save();
            }
        );
        try {
            $stripe->webhook($request);
            $this->fail(
                'A failed subscription service was revived by a delayed webhook.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'no longer eligible for setup',
                $exception->getMessage()
            );
        }

        $this->assertNull($service->fresh()->subscription_id);
        Http::assertNotSent(
            fn (HttpRequest $request): bool => $request->method() === 'POST'
        );
    }

    public function test_canceled_schedule_clears_the_matching_subscription(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'status' => Service::STATUS_ACTIVE,
            'subscription_id' => 'sub_canceled_test',
        ]);
        $service->properties()->create([
            'key' => 'has_stripe_subscription',
            'value' => true,
        ]);
        $service->properties()->create([
            'key' => 'stripe_subscription_schedule_id',
            'value' => 'sub_sched_canceled_test',
        ]);
        $secret = 'whsec_schedule_canceled_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'subscription_schedule.canceled',
                'data' => [
                    'object' => [
                        'id' => 'sub_sched_canceled_test',
                        'subscription' => 'sub_canceled_test',
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );

        (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($request);

        $service->refresh();
        $this->assertNull($service->subscription_id);
        $this->assertFalse(
            $service->properties()
                ->whereIn('key', [
                    'has_stripe_subscription',
                    'stripe_subscription_schedule_id',
                ])
                ->exists()
        );
    }

    public function test_canceled_schedule_supports_legacy_subscription_identity(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'status' => Service::STATUS_ACTIVE,
            'subscription_id' => 'sub_legacy_canceled_test',
        ]);
        $service->properties()->create([
            'key' => 'has_stripe_subscription',
            'value' => true,
        ]);
        $secret = 'whsec_legacy_schedule_canceled_test';
        $request = $this->signedStripeRequest(
            [
                'type' => 'subscription_schedule.canceled',
                'data' => [
                    'object' => [
                        'id' => 'sub_sched_legacy_canceled_test',
                        'subscription' => 'sub_legacy_canceled_test',
                    ],
                ],
            ],
            $secret,
            now()->timestamp
        );

        (new Stripe([
            'stripe_webhook_secret' => $secret,
        ]))->webhook($request);

        $service->refresh();
        $this->assertNull($service->subscription_id);
        $this->assertFalse(
            $service->properties()
                ->where('key', 'has_stripe_subscription')
                ->exists()
        );
    }

    public function test_stripe_creation_rolls_back_when_webhook_secret_is_blank(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints' => Http::sequence()
                ->push(['data' => []])
                ->push([
                    'id' => 'we_blank_secret',
                    'secret' => " \t\n",
                ]),
        ]);

        try {
            $this->createGateway([
                'name' => 'Stripe without signing secret',
                'extension' => 'Stripe',
                'settings' => [
                    'stripe_secret_key' => 'rk_test',
                    'stripe_publishable_key' => 'pk_test',
                    'stripe_webhook_secret' => null,
                ],
            ]);
            $this->fail(
                'Stripe activation accepted a blank webhook signing secret.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'without returning a usable signing secret',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('extensions', [
            'name' => 'Stripe without signing secret',
            'type' => 'gateway',
        ]);
        $this->assertDatabaseMissing('settings', [
            'value' => 'rk_test',
        ]);
        $this->assertDatabaseMissing('settings', [
            'value' => 'pk_test',
        ]);
        Http::assertSentCount(2);
    }

    public function test_gateway_without_enabled_hook_can_still_be_created(): void
    {
        $gateway = $this->createGateway([
            'name' => 'PayPal IPN without activation hook',
            'extension' => 'PayPal_IPN',
            'settings' => [],
        ]);

        $this->assertInstanceOf(Gateway::class, $gateway);
        $this->assertTrue((bool) $gateway->enabled);
        $this->assertDatabaseHas('extensions', [
            'id' => $gateway->id,
            'type' => 'gateway',
            'extension' => 'PayPal_IPN',
            'enabled' => true,
        ]);
    }

    public function test_stripe_edit_rolls_back_when_secret_rotation_fails(): void
    {
        $gateway = Gateway::create([
            'name' => 'Working Stripe',
            'extension' => 'Stripe',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        foreach ([
            'stripe_secret_key' => 'rk_existing',
            'stripe_publishable_key' => 'pk_existing',
            'stripe_webhook_secret' => 'whsec_existing',
        ] as $key => $value) {
            $gateway->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }
        // The edit form resolves this relation while populating its inputs.
        // Reproduce that cache so the hook cannot accidentally reuse it after
        // settings are written.
        $this->assertSame(
            'rk_existing',
            $gateway->settings->firstWhere(
                'key',
                'stripe_secret_key'
            )?->value
        );
        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 'we_existing',
                        'url' => route(
                            'extensions.gateways.stripe.webhook'
                        ),
                    ]],
                ])
                ->push([
                    'id' => 'we_failed_rotation',
                    'secret' => ' ',
                ]),
        ]);

        try {
            $this->updateGateway($gateway, [
                'name' => 'Broken Stripe edit',
                'extension' => 'Stripe',
                'type' => 'gateway',
                'enabled' => true,
                'settings' => [
                    'stripe_secret_key' => 'rk_replacement',
                    'stripe_publishable_key' => 'pk_replacement',
                    'stripe_create_customers' => false,
                    'stripe_webhook_secret' => '',
                ],
            ]);
            $this->fail(
                'Stripe retained an enabled gateway after secret rotation failed.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'without returning a usable signing secret',
                $exception->getMessage()
            );
        }

        $this->assertSame('Working Stripe', $gateway->fresh()->name);
        $this->assertSame(
            'whsec_existing',
            $gateway->settings()
                ->where('key', 'stripe_webhook_secret')
                ->value('value')
        );
        $this->assertSame(
            'rk_existing',
            $gateway->settings()
                ->where('key', 'stripe_secret_key')
                ->value('value')
        );
        Http::assertSent(
            fn (HttpRequest $request): bool => $request->hasHeader(
                'Authorization',
                'Bearer rk_replacement'
            )
        );
        Http::assertNotSent(
            fn (HttpRequest $request): bool => $request->hasHeader(
                'Authorization',
                'Bearer rk_existing'
            )
        );
        Http::assertNotSent(
            fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        );
    }

    public function test_outer_rollback_keeps_existing_stripe_webhook(): void
    {
        $gateway = $this->stripeGateway();
        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 'we_existing',
                        'url' => route(
                            'extensions.gateways.stripe.webhook'
                        ),
                    ]],
                ])
                ->push([
                    'id' => 'we_replacement',
                    'secret' => 'whsec_replacement',
                ]),
        ]);

        try {
            DB::transaction(function () use ($gateway): void {
                $gateway->settings()
                    ->where('key', 'stripe_secret_key')
                    ->update(['value' => 'rk_replacement']);
                $gateway->settings()
                    ->where('key', 'stripe_webhook_secret')
                    ->update(['value' => '']);
                $gateway->unsetRelation('settings');

                (new Stripe([
                    'stripe_secret_key' => 'rk_replacement',
                    'stripe_publishable_key' => 'pk_replacement',
                    'stripe_webhook_secret' => '',
                ]))->updated($gateway);

                throw new \RuntimeException('force outer rollback');
            });
            $this->fail('The forced outer transaction unexpectedly committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'force outer rollback',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            'rk_existing',
            $gateway->settings()
                ->where('key', 'stripe_secret_key')
                ->value('value')
        );
        $this->assertSame(
            'whsec_existing',
            $gateway->settings()
                ->where('key', 'stripe_webhook_secret')
                ->value('value')
        );
        Http::assertSentCount(2);
        Http::assertNotSent(
            fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        );
    }

    public function test_committed_rotation_deletes_old_webhook_afterward(): void
    {
        $gateway = $this->stripeGateway();
        $webhookUrl = 'https://api.stripe.com/v1/webhook_endpoints';
        Http::fake([
            $webhookUrl => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 'we_existing',
                        'url' => route(
                            'extensions.gateways.stripe.webhook'
                        ),
                    ]],
                ])
                ->push([
                    'id' => 'we_replacement',
                    'secret' => 'whsec_replacement',
                ]),
            "{$webhookUrl}/we_existing" => Http::response([], 200),
        ]);

        $this->updateGateway($gateway, [
            'name' => 'Rotated Stripe',
            'extension' => 'Stripe',
            'type' => 'gateway',
            'enabled' => true,
            'settings' => [
                'stripe_secret_key' => 'rk_replacement',
                'stripe_publishable_key' => 'pk_replacement',
                'stripe_create_customers' => false,
                'stripe_webhook_secret' => '',
            ],
        ]);

        $this->assertSame(
            'whsec_replacement',
            $gateway->settings()
                ->where('key', 'stripe_webhook_secret')
                ->value('value')
        );
        Http::assertSent(
            fn (HttpRequest $request): bool => $request->method() === 'DELETE'
                && $request->url() === "{$webhookUrl}/we_existing"
                && $request->hasHeader(
                    'Authorization',
                    'Bearer rk_replacement'
                )
        );
    }

    private function signedStripeRequest(
        array $event,
        string $secret,
        int $timestamp
    ): Request {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
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

    private function createGateway(array $data): Model
    {
        $page = (new \ReflectionClass(CreateGateway::class))
            ->newInstanceWithoutConstructor();
        $mutator = new \ReflectionMethod(
            CreateGateway::class,
            'mutateFormDataBeforeCreate'
        );
        $mutator->setAccessible(true);
        $creator = new \ReflectionMethod(
            CreateGateway::class,
            'handleRecordCreation'
        );
        $creator->setAccessible(true);

        return $creator->invoke(
            $page,
            $mutator->invoke($page, $data)
        );
    }

    private function updateGateway(Gateway $gateway, array $data): Model
    {
        $page = (new \ReflectionClass(EditGateway::class))
            ->newInstanceWithoutConstructor();
        $updater = new \ReflectionMethod(
            EditGateway::class,
            'handleRecordUpdate'
        );
        $updater->setAccessible(true);

        return $updater->invoke($page, $gateway, $data);
    }

    private function stripeGateway(): Gateway
    {
        $gateway = Gateway::create([
            'name' => 'Working Stripe',
            'extension' => 'Stripe',
            'type' => 'gateway',
            'enabled' => true,
        ]);
        foreach ([
            'stripe_secret_key' => 'rk_existing',
            'stripe_publishable_key' => 'pk_existing',
            'stripe_webhook_secret' => 'whsec_existing',
        ] as $key => $value) {
            $gateway->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'encrypted' => false,
            ]);
        }

        return $gateway;
    }
}
