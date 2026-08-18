<?php

namespace Tests\Unit;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\MarkInvoicePaidService;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeReconciliationService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeFailureAlertService;
use App\Support\LegacyServiceUpgradeMigration;
use App\Support\PanelEndpointIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Servers\Pterodactyl\Pterodactyl;
use Tests\TestCase;

class PterodactylReservationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pterodactyl_requests_have_bounded_network_timeouts(): void
    {
        $source = file_get_contents(
            base_path('extensions/Servers/Pterodactyl/Pterodactyl.php')
        );

        $this->assertStringContainsString(
            '->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)',
            $source
        );
        $this->assertStringContainsString(
            '->timeout(self::REQUEST_TIMEOUT_SECONDS)',
            $source
        );
        $this->assertStringNotContainsString('->retry(', $source);
    }

    public function test_pterodactyl_request_failures_do_not_expose_upstream_details(): void
    {
        $provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
        Http::fakeSequence()
            ->push([
                'errors' => [[
                    'detail' => 'provider token=upstream-secret',
                ]],
            ], 422)
            ->push([
                'errors' => [[
                    'detail' => 'provider token=upstream-secret',
                ]],
            ], 500);

        foreach ([
            [422, PermanentProvisioningException::class],
            [500, \Exception::class],
        ] as [$status, $expectedClass]) {
            try {
                $provisioner->request('/api/application/servers', 'post');
                $this->fail("Expected a Pterodactyl {$status} failure.");
            } catch (\Throwable $exception) {
                $this->assertSame($expectedClass, $exception::class);
                $this->assertSame(
                    "Pterodactyl API request failed with status {$status}.",
                    $exception->getMessage()
                );
                $this->assertStringNotContainsString(
                    'upstream-secret',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_product_configuration_loads_every_option_page_in_order(): void
    {
        $pages = [
            '/api/application/nodes' => PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nodes',
                ['id' => 1, 'name' => 'Node One'],
                ['id' => 2, 'name' => 'Node Two']
            ),
            '/api/application/locations' => PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/locations',
                ['id' => 11, 'short' => 'SYD'],
                ['id' => 12, 'short' => 'MEL']
            ),
            '/api/application/nests' => PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nests',
                ['id' => 21, 'name' => 'Games'],
                ['id' => 22, 'name' => 'Applications']
            ),
            '/api/application/nests/21/eggs' => PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nests/21/eggs',
                ['id' => 31, 'name' => 'Minecraft'],
                ['id' => 32, 'name' => 'Terraria']
            ),
        ];
        $provisioner = new PterodactylConfigurationCollectionStub($pages);

        $config = collect($provisioner->getProductConfig([
            'nest_id' => 21,
        ]))->keyBy('name');

        $this->assertSame(
            [1 => 'Node One', 2 => 'Node Two'],
            $config['node']['options']
        );
        $this->assertSame(
            [11 => 'SYD', 12 => 'MEL'],
            $config['location_ids']['options']
        );
        $this->assertSame(
            [21 => 'Games', 22 => 'Applications'],
            $config['nest_id']['options']
        );
        $this->assertSame(
            [31 => 'Minecraft', 32 => 'Terraria'],
            $config['egg_id']['options']
        );
        $this->assertSame([
            ['/api/application/nodes', 1],
            ['/api/application/nodes', 2],
            ['/api/application/locations', 1],
            ['/api/application/locations', 2],
            ['/api/application/nests', 1],
            ['/api/application/nests', 2],
            ['/api/application/nests/21/eggs', 1],
            ['/api/application/nests/21/eggs', 2],
        ], $provisioner->requests);
    }

    public function test_product_configuration_rejects_malformed_pagination(): void
    {
        $nodes =
            PterodactylConfigurationCollectionResponse::singlePage(
                '/api/application/nodes',
                [['id' => 1, 'name' => 'Node One']]
            );
        $nodes[1]['meta']['pagination']['current_page'] = 2;
        $provisioner = new PterodactylConfigurationCollectionStub([
            '/api/application/nodes' => $nodes,
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'node configuration lookup returned invalid pagination metadata'
        );

        $provisioner->getProductConfig();
    }

    public function test_product_configuration_rejects_pagination_that_changes_between_pages(): void
    {
        $nodes =
            PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nodes',
                ['id' => 1, 'name' => 'Node One'],
                ['id' => 2, 'name' => 'Node Two']
            );
        $nodes[2]['meta']['pagination']['total'] = 3;
        $nodes[2]['meta']['pagination']['per_page'] = 2;
        $provisioner = new PterodactylConfigurationCollectionStub([
            '/api/application/nodes' => $nodes,
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'node configuration pagination changed during traversal'
        );

        $provisioner->getProductConfig();
    }

    public function test_product_configuration_rejects_non_monotonic_pagination_links(): void
    {
        $nodes =
            PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nodes',
                ['id' => 1, 'name' => 'Node One'],
                ['id' => 2, 'name' => 'Node Two']
            );
        $nodes[1]['meta']['pagination']['links']['next'] =
            'https://panel.example.com/api/application/nodes?page=3';
        $provisioner = new PterodactylConfigurationCollectionStub([
            '/api/application/nodes' => $nodes,
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'node configuration lookup returned non-monotonic pagination metadata'
        );

        $provisioner->getProductConfig();
    }

    public function test_product_configuration_rejects_a_truncated_page(): void
    {
        $provisioner = new PterodactylConfigurationCollectionStub([
            '/api/application/nodes' => [
                1 => PterodactylUserCollectionResponse::page(
                    [[
                        'attributes' => [
                            'id' => 1,
                            'name' => 'Node One',
                        ],
                    ]],
                    total: 2,
                    perPage: 1,
                    currentPage: 1,
                    totalPages: 2,
                    endpoint: '/api/application/nodes'
                ),
                2 => PterodactylUserCollectionResponse::page(
                    [],
                    total: 2,
                    perPage: 1,
                    currentPage: 2,
                    totalPages: 2,
                    endpoint: '/api/application/nodes'
                ),
            ],
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'node configuration lookup returned invalid pagination metadata'
        );

        $provisioner->getProductConfig();
    }

    public function test_product_configuration_rejects_duplicate_ids_across_pages(): void
    {
        $provisioner = new PterodactylConfigurationCollectionStub([
            '/api/application/nodes' => PterodactylConfigurationCollectionResponse::twoPages(
                '/api/application/nodes',
                ['id' => 1, 'name' => 'Node One'],
                ['id' => 1, 'name' => 'Node One Again']
            ),
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'node configuration lookup returned a duplicate option identity'
        );

        $provisioner->getProductConfig();
    }

    public function test_product_configuration_surfaces_egg_lookup_failures(): void
    {
        $provisioner = new PterodactylConfigurationCollectionStub(
            [
                '/api/application/nodes' => PterodactylConfigurationCollectionResponse::singlePage(
                    '/api/application/nodes',
                    [['id' => 1, 'name' => 'Node One']]
                ),
                '/api/application/locations' => PterodactylConfigurationCollectionResponse::singlePage(
                    '/api/application/locations',
                    [['id' => 11, 'short' => 'SYD']]
                ),
                '/api/application/nests' => PterodactylConfigurationCollectionResponse::singlePage(
                    '/api/application/nests',
                    [['id' => 21, 'name' => 'Games']]
                ),
            ],
            [
                '/api/application/nests/21/eggs' => new \Exception('Panel egg endpoint unavailable.'),
            ]
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'Pterodactyl egg configuration lookup failed: Panel egg endpoint unavailable.'
        );

        $provisioner->getProductConfig(['nest_id' => 21]);
    }

    public function test_customer_lookup_finds_an_exact_match_on_page_two(): void
    {
        $expectedExternalId = 'paymenter-user-42';
        $provisioner = new PterodactylUserLookupStub([
            1 => PterodactylUserCollectionResponse::page(
                [[
                    'attributes' => [
                        'id' => 44,
                        'external_id' => "{$expectedExternalId}-similar",
                    ],
                ]],
                total: 2,
                perPage: 1,
                currentPage: 1,
                totalPages: 2
            ),
            2 => PterodactylUserCollectionResponse::page(
                [[
                    'attributes' => [
                        'id' => 45,
                        'external_id' => $expectedExternalId,
                    ],
                ]],
                total: 2,
                perPage: 1,
                currentPage: 2,
                totalPages: 2
            ),
        ]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'pterodactylUserMatches'
        );
        $lookup->setAccessible(true);

        $matches = $lookup->invoke(
            $provisioner,
            ['external_id' => $expectedExternalId],
            'external_id',
            $expectedExternalId
        );

        $this->assertSame([45], array_column($matches, 'id'));
        $this->assertSame(
            [1, 2],
            array_column($provisioner->requests, 'page')
        );
        foreach ($provisioner->requests as $request) {
            $this->assertSame(
                ['external_id' => $expectedExternalId],
                $request['filter']
            );
        }
    }

    public function test_customer_lookup_rejects_a_duplicate_on_page_two(): void
    {
        $expectedExternalId = 'paymenter-user-42';
        $user = fn (int $id): array => [
            'attributes' => [
                'id' => $id,
                'external_id' => $expectedExternalId,
            ],
        ];
        $provisioner = new PterodactylUserLookupStub([
            1 => PterodactylUserCollectionResponse::page(
                [$user(44)],
                total: 2,
                perPage: 1,
                currentPage: 1,
                totalPages: 2
            ),
            2 => PterodactylUserCollectionResponse::page(
                [$user(45)],
                total: 2,
                perPage: 1,
                currentPage: 2,
                totalPages: 2
            ),
        ]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'matchingPterodactylUsers'
        );
        $lookup->setAccessible(true);

        try {
            $lookup->invoke(
                $provisioner,
                ['external_id' => $expectedExternalId],
                'external_id',
                $expectedExternalId
            );
            $this->fail('Expected a page-two duplicate to fail closed.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'multiple customers',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            [1, 2],
            array_column($provisioner->requests, 'page')
        );
    }

    public function test_customer_lookup_rejects_repeated_nonmatching_identity(): void
    {
        $expectedExternalId = 'paymenter-user-42';
        $nonmatch = [
            'attributes' => [
                'id' => 44,
                'external_id' => "{$expectedExternalId}-similar",
            ],
        ];
        $provisioner = new PterodactylUserLookupStub([
            1 => PterodactylUserCollectionResponse::page(
                [$nonmatch],
                total: 2,
                perPage: 1,
                currentPage: 1,
                totalPages: 2
            ),
            2 => PterodactylUserCollectionResponse::page(
                [$nonmatch],
                total: 2,
                perPage: 1,
                currentPage: 2,
                totalPages: 2
            ),
        ]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'pterodactylUserMatches'
        );
        $lookup->setAccessible(true);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'duplicate customer identity'
        );

        $lookup->invoke(
            $provisioner,
            ['external_id' => $expectedExternalId],
            'external_id',
            $expectedExternalId
        );
    }

    public function test_customer_lookup_rejects_noncanonical_customer_id(): void
    {
        $response = PterodactylUserCollectionResponse::single([[
            'attributes' => [
                'id' => '44.0',
                'email' => 'customer@example.com',
            ],
        ]]);
        $provisioner = new PterodactylUserLookupStub([1 => $response]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'pterodactylUserMatches'
        );
        $lookup->setAccessible(true);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('invalid customer identity');

        $lookup->invoke(
            $provisioner,
            ['email' => 'customer@example.com'],
            'email',
            'customer@example.com',
            true
        );
    }

    public function test_customer_lookup_rejects_malformed_pagination(): void
    {
        $response = PterodactylUserCollectionResponse::single([]);
        $response['meta']['pagination']['current_page'] = 2;
        $provisioner = new PterodactylUserLookupStub([1 => $response]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'pterodactylUserMatches'
        );
        $lookup->setAccessible(true);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'invalid customer lookup pagination metadata'
        );

        $lookup->invoke(
            $provisioner,
            ['email' => 'customer@example.com'],
            'email',
            'customer@example.com',
            true
        );
    }

    public function test_customer_lookup_rejects_a_truncated_second_page(): void
    {
        $expectedExternalId = 'paymenter-user-42';
        $provisioner = new PterodactylUserLookupStub([
            1 => PterodactylUserCollectionResponse::page(
                [[
                    'attributes' => [
                        'id' => 44,
                        'external_id' => "{$expectedExternalId}-similar",
                    ],
                ]],
                total: 2,
                perPage: 1,
                currentPage: 1,
                totalPages: 2
            ),
            2 => PterodactylUserCollectionResponse::page(
                [],
                total: 2,
                perPage: 1,
                currentPage: 2,
                totalPages: 2
            ),
        ]);
        $lookup = new \ReflectionMethod(
            Pterodactyl::class,
            'pterodactylUserMatches'
        );
        $lookup->setAccessible(true);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'invalid customer lookup pagination metadata'
        );

        $lookup->invoke(
            $provisioner,
            ['external_id' => $expectedExternalId],
            'external_id',
            $expectedExternalId
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_core_panel_identity_matches_inventory_canonicalization_without_path_collision(): void
    {
        $identity = new \ReflectionMethod(
            Pterodactyl::class,
            'panelIdentity'
        );
        $identity->setAccessible(true);
        $provisioner = new Pterodactyl([
            'host' => 'HTTPS://Panel.Example.com:443/PanelA/',
            'api_key' => 'secret',
        ]);

        $this->assertSame(
            PanelEndpointIdentity::hash(
                'https://panel.example.com/PanelA'
            ),
            $identity->invoke($provisioner)
        );
        $this->assertNotSame(
            PanelEndpointIdentity::hash(
                'https://panel.example.com/panela'
            ),
            $identity->invoke($provisioner)
        );
    }

    public function test_upgrade_reservation_history_remains_authoritative_after_product_metadata_is_ordinary(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_CANCELLED
        );
        $this->insertUpgradeReservation($upgrade, [
            'status' => 'cancelled',
            'upgrade_guard_id' => null,
        ]);

        $identity = app(CapacityUpgradeReservationIdentity::class);
        $this->assertTrue($identity->exists((int) $upgrade->id));
        $this->assertTrue($identity->requiresCoordinator($upgrade->fresh()));
    }

    public function test_ordinary_upgrade_without_reservation_history_does_not_require_coordinator(): void
    {
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT
        );

        $identity = app(CapacityUpgradeReservationIdentity::class);
        $this->assertFalse($identity->exists((int) $upgrade->id));
        $this->assertFalse(
            $identity->requiresCoordinator($upgrade->fresh())
        );
    }

    public function test_legacy_migration_cannot_abandon_an_active_dynamic_upgrade_reservation(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT
        );
        $reservationId = $this->insertUpgradeReservation($upgrade);

        try {
            DB::transaction(
                fn () => LegacyServiceUpgradeMigration::reconcile()
            );
            $this->fail(
                'Legacy reconciliation abandoned dynamic capacity.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "dynamic capacity reservation {$reservationId}",
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            $upgrade->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
            'service_upgrade_id' => $upgrade->id,
        ]);
    }

    public function test_refund_only_reconciliation_cannot_abandon_dynamic_capacity(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION
        );
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'quoted_amount' => '0.00',
                'legacy_refund_only_at' => now(),
            ]);
        $reservationId = $this->insertUpgradeReservation(
            $upgrade->fresh(),
            ['status' => 'paid_committed']
        );

        try {
            app(ServiceUpgradeReconciliationService::class)
                ->reconcile(
                    $upgrade->id,
                    ServiceUpgradeReconciliationService::ACTION_REFUNDED_NOT_APPLIED,
                    'Refund checked without releasing capacity.',
                    'admin@example.test'
                );
            $this->fail(
                'Refund-only reconciliation abandoned dynamic capacity.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "dynamic capacity reservation {$reservationId}",
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'paid_committed',
            'service_upgrade_id' => $upgrade->id,
        ]);
    }

    public function test_cancelling_unpaid_capacity_upgrade_preserves_active_service(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_AWAITING_PAYMENT
        );
        $service = $upgrade->service;
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
        ]);
        $invoice->items()->create([
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
            'description' => 'Capacity upgrade',
            'quantity' => 1,
            'price' => 10,
        ]);
        $upgrade->invoice_id = $invoice->id;
        ServiceUpgradeMutationCoordinator::save($upgrade);
        $reservationId = $this->insertUpgradeReservation($upgrade);

        app(CancelInvoiceService::class)->handle($invoice);

        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $invoice->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'cancelled',
            'service_id' => $service->id,
            'service_upgrade_id' => $upgrade->id,
        ]);
    }

    public function test_confirmed_capacity_service_can_pay_its_exact_active_renewal(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $originalExpiry = $service->expires_at->copy();
        $renewal = $this->renewalInvoice($service);
        $runtime = Mockery::mock(ReservationService::class);
        $runtime->shouldNotReceive('preflightPaidService');
        $this->app->instance(ReservationService::class, $runtime);

        app(MarkInvoicePaidService::class)->handle($renewal);

        $this->assertSame(
            Invoice::STATUS_PAID,
            $renewal->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertTrue(
            $service->fresh()->expires_at->greaterThan($originalExpiry)
        );
    }

    public function test_confirmed_capacity_service_can_pay_its_exact_suspended_renewal(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_SUSPENDED
        );
        $renewal = $this->renewalInvoice($service);

        app(MarkInvoicePaidService::class)->handle($renewal);

        $this->assertSame(
            Invoice::STATUS_PAID,
            $renewal->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertTrue(
            $service->fresh()->expires_at->greaterThan(now())
        );
    }

    public function test_zero_price_confirmed_capacity_service_can_renew_without_checkout_invoice(): void
    {
        [$service, $reservationId] =
            $this->confirmedRenewalFixture(Service::STATUS_ACTIVE);
        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->firstOrFail();
        $payload = json_decode(
            (string) $reservation->configuration_payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['calculated_price'] = '0.00';

        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'invoice_id' => null,
                'calculated_price' => '0.00',
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                'configuration_fingerprint' => app(
                    ReservationConfigurationService::class
                )->fingerprint($payload),
            ]);
        DB::table('services')
            ->where('id', $service->id)
            ->update(['price' => '0.00']);
        $service = $service->fresh(['product', 'plan']);
        $originalExpiry = $service->expires_at->copy();

        app(RenewServiceService::class)->handle($service);

        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertTrue(
            $service->fresh()->expires_at->greaterThan($originalExpiry)
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'invoice_id' => null,
            'calculated_price' => '0.00',
            'status' => 'confirmed',
        ]);
    }

    public function test_positive_signed_checkout_amount_requires_original_invoice_identity_for_renewal(): void
    {
        [$service, $reservationId] =
            $this->confirmedRenewalFixture(Service::STATUS_ACTIVE);
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update(['invoice_id' => null]);
        $renewal = $this->renewalInvoice($service);

        $failure = app(DurableFulfillmentService::class)
            ->preflightPaidService($service, $renewal);

        $this->assertIsString($failure);
        $this->assertStringContainsString(
            'incomplete confirmed checkout billing commitment',
            $failure
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
    }

    public function test_capacity_renewal_requires_payment_coordinator_without_reusing_checkout_deadline(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);
        $payments = app(CapacityInvoicePaymentService::class);

        $this->assertFalse($payments->isCapacityBacked($renewal));
        $this->assertTrue(
            $payments->requiresFulfillmentCoordinator($renewal)
        );
        $this->assertNull($payments->effectiveDeadline($renewal));

        try {
            $renewal->status = Invoice::STATUS_PAID;
            $renewal->save();
            $this->fail(
                'Expected a direct renewal paid transition to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment coordinator',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
    }

    public function test_capacity_renewal_succeeded_evidence_requires_atomic_coordinator(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);

        try {
            $renewal->transactions()->create([
                'amount' => $service->price,
                'status' => InvoiceTransactionStatus::Succeeded,
                'transaction_id' => 'unsafe-renewal-success',
            ]);
            $this->fail(
                'Expected direct renewal payment evidence to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'atomic payment coordinator',
                $exception->getMessage()
            );
        }

        $this->assertFalse(
            InvoiceTransaction::query()
                ->where('transaction_id', 'unsafe-renewal-success')
                ->exists()
        );
    }

    public function test_capacity_renewal_line_cannot_be_changed_to_bypass_coordination(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);
        $line = $renewal->items()->firstOrFail();

        try {
            $line->price = 1;
            $line->save();
            $this->fail(
                'Expected renewal line mutation to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment lines are immutable',
                $exception->getMessage()
            );
        }
        $line->refresh();

        try {
            $line->delete();
            $this->fail(
                'Expected renewal line deletion to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'invoice lines cannot be deleted',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('invoice_items', [
            'id' => $line->id,
            'invoice_id' => $renewal->id,
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => number_format((float) $service->price, 2, '.', ''),
        ]);
        $this->assertTrue(
            app(CapacityInvoicePaymentService::class)
                ->requiresFulfillmentCoordinator($renewal)
        );
    }

    public function test_capacity_renewal_invoice_cannot_be_deleted_with_its_payment_evidence(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);
        $transaction = ExtensionHelper::addProcessingPayment(
            $renewal,
            null,
            $service->price,
            transactionId: 'renewal-delete-guard'
        );

        try {
            $renewal->delete();
            $this->fail(
                'Expected capacity renewal invoice deletion to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'durable fulfillment records',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('invoices', [
            'id' => $renewal->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('invoice_transactions', [
            'id' => $transaction->id,
            'invoice_id' => $renewal->id,
            'transaction_id' => 'renewal-delete-guard',
            'status' => InvoiceTransactionStatus::Processing->value,
        ]);
    }

    public function test_processing_capacity_renewal_cannot_be_cancelled_or_bypassed(): void
    {
        [$service, $commitmentId] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);
        $transaction = ExtensionHelper::addProcessingPayment(
            $renewal,
            null,
            $service->price,
            transactionId: 'renewal-cancel-guard'
        );

        try {
            $transaction->delete();
            $this->fail(
                'Expected processing capacity payment evidence deletion to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'payment evidence cannot be deleted',
                $exception->getMessage()
            );
        }

        try {
            $transaction->status = InvoiceTransactionStatus::Failed;
            $transaction->save();
            $this->fail(
                'Expected direct processing payment finalization to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'atomic payment coordinator',
                $exception->getMessage()
            );
        }

        try {
            app(CancelInvoiceService::class)
                ->handle($renewal);
            $this->fail(
                'Expected processing capacity renewal cancellation to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'requires refund or credit reconciliation',
                $exception->getMessage()
            );
        }

        try {
            $renewal->status = Invoice::STATUS_CANCELLED;
            $renewal->save();
            $this->fail(
                'Expected direct capacity renewal cancellation to be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment coordinator',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $commitmentId,
            'status' => 'confirmed',
            'service_id' => $service->id,
        ]);
    }

    public function test_unpaid_capacity_renewal_can_be_cancelled_without_releasing_service_capacity(): void
    {
        [$service, $commitmentId] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);

        app(CancelInvoiceService::class)
            ->handle($renewal);

        $this->assertSame(
            Invoice::STATUS_CANCELLED,
            $renewal->fresh()->status
        );
        $this->assertSame(
            Service::STATUS_ACTIVE,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $commitmentId,
            'status' => 'confirmed',
            'service_id' => $service->id,
        ]);
    }

    public function test_external_renewal_payment_survives_mutation_failure_as_attention(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $renewal = $this->renewalInvoice($service);
        $originalExpiry = $service->expires_at->copy();
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

                public function assertRenewalMutationAllowed(
                    Service $service,
                    ?Invoice $invoice
                ): Service {
                    throw new \RuntimeException(
                        'Deterministic renewal mutation failure.'
                    );
                }
            }
        );

        $transaction = ExtensionHelper::addPayment(
            $renewal,
            null,
            $service->price,
            transactionId: 'captured-renewal-failure'
        );

        $this->assertSame(
            InvoiceTransactionStatus::Succeeded,
            $transaction->fresh()->status
        );
        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
        $this->assertNotNull(
            $renewal->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'Deterministic renewal mutation failure',
            (string) $renewal->fresh()->payment_attention_reason
        );
        $this->assertTrue(
            $service->fresh()->expires_at->equalTo($originalExpiry)
        );
    }

    public function test_nonrenewal_service_invoice_cannot_use_confirmed_commitment_bypass(): void
    {
        [$service] = $this->confirmedRenewalFixture(
            Service::STATUS_ACTIVE
        );
        $originalExpiry = $service->expires_at->copy();
        $forged = $this->renewalInvoice(
            $service,
            dueAt: $service->expires_at->copy()->addDay()
        );

        $failure = app(DurableFulfillmentService::class)
            ->preflightPaidService($service, $forged);
        $this->assertIsString($failure);
        $this->assertStringContainsString(
            'exact renewal obligation',
            $failure
        );
        app(MarkInvoicePaidService::class)->handle($forged);

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $forged->fresh()->status
        );
        DB::table('invoices')
            ->where('id', $forged->id)
            ->update(['status' => Invoice::STATUS_PAID]);
        try {
            app(RenewServiceService::class)->handle(
                $service->fresh(['product', 'plan']),
                $forged->fresh()
            );
            $this->fail(
                'Expected the mutation boundary to reject the forged renewal.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'exact renewal obligation',
                $exception->getMessage()
            );
        }
        $this->assertTrue(
            $service->fresh()->expires_at->equalTo($originalExpiry)
        );
    }

    public function test_corrupted_confirmed_commitment_cannot_authorize_renewal(): void
    {
        [$service, $reservationId] =
            $this->confirmedRenewalFixture(Service::STATUS_ACTIVE);
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update(['configuration_fingerprint' => str_repeat('0', 64)]);
        $renewal = $this->renewalInvoice($service);

        $failure = app(DurableFulfillmentService::class)
            ->preflightPaidService($service, $renewal);
        $this->assertIsString($failure);
        $this->assertStringContainsString(
            'corrupted confirmed checkout commitment',
            $failure
        );
        app(MarkInvoicePaidService::class)->handle($renewal);

        $this->assertSame(
            Invoice::STATUS_PENDING,
            $renewal->fresh()->status
        );
    }

    public function test_renewal_is_blocked_by_every_committed_or_problem_upgrade_state(): void
    {
        foreach ([
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            ServiceUpgrade::STATUS_PROVISIONING,
            ServiceUpgrade::STATUS_RETRYABLE_FAILED,
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
        ] as $status) {
            [$service] = $this->confirmedRenewalFixture(
                Service::STATUS_ACTIVE
            );
            $originalExpiry = $service->expires_at->copy();
            $upgrade = ServiceUpgrade::create([
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'plan_id' => $service->plan_id,
                'status' => $status,
                'type' => 'product',
                'active_service_guard_id' => $service->id,
                'provisioning_attempts' => $status === ServiceUpgrade::STATUS_PROVISIONING
                        ? 1
                        : 0,
            ]);
            $renewal = $this->renewalInvoice($service);

            $failure = app(DurableFulfillmentService::class)
                ->preflightPaidService($service, $renewal);
            $this->assertIsString($failure);
            $this->assertStringContainsString(
                "upgrade {$upgrade->id} is {$status}",
                $failure
            );
            app(MarkInvoicePaidService::class)->handle($renewal);

            $this->assertSame(
                Invoice::STATUS_PENDING,
                $renewal->fresh()->status
            );
            DB::table('invoices')
                ->where('id', $renewal->id)
                ->update(['status' => Invoice::STATUS_PAID]);
            try {
                app(RenewServiceService::class)->handle(
                    $service->fresh(['product', 'plan']),
                    $renewal->fresh()
                );
                $this->fail(
                    "Expected mutation to remain blocked by {$status}."
                );
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    "upgrade {$upgrade->id} is {$status}",
                    $exception->getMessage()
                );
            }
            $this->assertTrue(
                $service->fresh()->expires_at->equalTo($originalExpiry)
            );
            $this->assertSame($status, $upgrade->fresh()->status);
        }
    }

    public function test_dynamic_upgrade_without_reservation_history_still_requires_coordinator(): void
    {
        $fixture = $this->createProduct();
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $fixture->product->server_id = $server->id;
        $fixture->product->save();
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'hidden' => false,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1024,
                'max' => 32768,
                'step' => 1024,
                'default' => 4096,
            ],
        ]);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $fixture->product->id,
        ]);
        $service = CapacityServiceCreationCoordinator::run(
            fn () => Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $fixture->product->id,
                'plan_id' => $fixture->plan->id,
                'quantity' => 1,
                'currency_code' => 'USD',
            ])
        );
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'type' => 'product',
            'active_service_guard_id' => $service->id,
        ]);

        $identity = app(CapacityUpgradeReservationIdentity::class);
        $this->assertFalse($identity->exists((int) $upgrade->id));
        $this->assertTrue(
            $identity->requiresCoordinator($upgrade->fresh())
        );
    }

    public function test_throwing_failure_coordinator_rolls_back_reservation_mutation_and_alerts_core(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $upgrade = $this->ordinaryUpgrade(
            ServiceUpgrade::STATUS_PROVISIONING
        );
        $reservationId = $this->insertUpgradeReservation($upgrade, [
            'status' => 'paid_committed',
            'upgrade_guard_id' => $upgrade->id,
            'provisioning_lease_id' => 'lease-savepoint',
        ]);
        $alert = Mockery::mock(UpgradeFailureAlertService::class);
        $alert->shouldReceive('notify')
            ->once()
            ->with($upgrade->id);
        $this->app->instance(UpgradeFailureAlertService::class, $alert);

        $coordinator = new class($reservationId)
        {
            public function __construct(private int $reservationId) {}

            public function failProvisioning(
                ServiceUpgrade $upgrade,
                \Throwable $exception,
                ?string $reservationLeaseId
            ): bool {
                DB::table('ptero_resource_reservations')
                    ->where('id', $this->reservationId)
                    ->update([
                        'status' => 'cancelled',
                        'provisioning_lease_id' => null,
                    ]);

                throw new \RuntimeException(
                    'Coordinator failed after a partial reservation update.'
                );
            }
        };
        $upgrades = new class($coordinator) extends ServiceUpgradeService
        {
            public function __construct(private object $coordinator) {}

            protected function capacityService(): object
            {
                return $this->coordinator;
            }
        };
        $upgrades->recordFailure(
            $upgrade,
            new \RuntimeException('Panel upgrade failed.'),
            reservationLeaseId: 'lease-savepoint'
        );

        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->firstOrFail();
        $this->assertSame('paid_committed', $reservation->status);
        $this->assertSame(
            'lease-savepoint',
            $reservation->provisioning_lease_id
        );
        $upgrade->refresh();
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->status
        );
        $this->assertNotNull($upgrade->failure_alerted_at);
        $this->assertStringContainsString(
            'partial reservation update',
            (string) $upgrade->last_error
        );
    }

    public function test_reserved_node_and_limits_drive_the_create_request(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
        ]);
        $this->markServiceReservationBacked($service);

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('beginProvisioning')
            ->once()
            ->with(Mockery::on(fn (Service $candidate) => $candidate->is($service)))
            ->andReturn([
                'reservation_id' => 91,
                'panel_identity' => hash('sha256', 'https://panel.example.com'),
                'node_id' => 7,
                'location_id' => 3,
                'memory' => 8192,
                'cpu' => 300,
                'disk' => 61440,
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$service->user_id}",
                'provisioning_lease_id' => 'lease-91',
                'already_consumed' => false,
                'allocations' => [
                    [
                        'allocation_id' => 7001,
                        'ip' => '192.0.2.10',
                        'port' => 25565,
                        'environment_key' => 'SERVER_PORT',
                        'is_primary' => true,
                    ],
                    [
                        'allocation_id' => 7002,
                        'ip' => '192.0.2.10',
                        'port' => 25566,
                        'environment_key' => 'QUERY_PORT',
                        'is_primary' => false,
                    ],
                    [
                        'allocation_id' => 7003,
                        'ip' => '192.0.2.10',
                        'port' => 25567,
                        'environment_key' => 'NONE',
                        'is_primary' => false,
                    ],
                    [
                        'allocation_id' => 7004,
                        'ip' => '192.0.2.10',
                        'port' => 25568,
                        'environment_key' => 'NONE',
                        'is_primary' => false,
                    ],
                ],
            ]);
        $reservationService->shouldReceive('provisioningMayContinue')
            ->once()
            ->with($service->id, 'lease-91')
            ->andReturnTrue();
        $reservationService->shouldReceive('completeProvisioning')
            ->once()
            ->with(
                Mockery::on(fn (Service $candidate) => $candidate->is($service)),
                'lease-91',
                Mockery::on(fn (array $server) => data_get($server, 'attributes.external_id') === (string) $service->id)
            )
            ->andReturn(true);
        $reservationService->shouldNotReceive('failProvisioning');
        $this->app->instance(ReservationService::class, $reservationService);
        $this->enableReservationExtension();

        $pterodactyl = $this->fakeProvisioner((string) $service->user->email);
        $settings = $this->baseSettings();
        $settings['additional_allocations'] = 9;
        $result = $pterodactyl->createServer($service, $settings, [
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
            'node' => 2,
        ]);

        $serverRequest = collect($pterodactyl->requests)
            ->first(fn (array $request) => $request['url'] === '/api/application/servers'
                && strtolower($request['method']) === 'post');

        $this->assertSame(71, $result['server']);
        $this->assertSame(8192, $serverRequest['data']['limits']['memory']);
        $this->assertSame(300, $serverRequest['data']['limits']['cpu']);
        $this->assertSame(61440, $serverRequest['data']['limits']['disk']);
        $this->assertSame(44, $serverRequest['data']['user']);
        $this->assertSame(2, $serverRequest['data']['egg']);
        $this->assertSame(7001, $serverRequest['data']['allocation']['default']);
        $this->assertSame(
            [7002, 7003, 7004],
            $serverRequest['data']['allocation']['additional']
        );
        $this->assertArrayNotHasKey(
            'NONE',
            $serverRequest['data']['environment']
        );
        $this->assertSame(
            25565,
            $serverRequest['data']['environment']['SERVER_PORT']
        );
        $this->assertSame(
            25566,
            $serverRequest['data']['environment']['QUERY_PORT']
        );
        foreach ($serverRequest['data']['environment'] as $value) {
            $this->assertIsScalar($value);
        }
        $this->assertSame(0, $serverRequest['data']['feature_limits']['allocations']);
        $userLookup = collect($pterodactyl->requests)
            ->first(fn (array $request) => $request['url'] === '/api/application/users'
                && strtolower($request['method']) === 'get');
        $this->assertSame(
            "paymenter-user-{$service->user_id}",
            data_get($userLookup, 'data.filter.external_id')
        );
        $this->assertNull(
            collect($pterodactyl->requests)
                ->first(fn (array $request) => $request['url'] === '/api/application/users'
                    && strtolower($request['method']) === 'post')
        );
        $this->assertNull(
            collect($pterodactyl->requests)
                ->first(fn (array $request) => $request['url'] === '/api/application/nodes/deployable')
        );
    }

    public function test_reserved_deployment_rejects_multiple_ports_for_one_egg_environment_key(): void
    {
        $provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'generateReservedDeploymentData'
        );
        $method->setAccessible(true);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage(
            'QUERY_PORT has multiple claims'
        );

        $method->invoke($provisioner, [
            'allocations' => [
                [
                    'allocation_id' => 7001,
                    'port' => 25565,
                    'environment_key' => 'SERVER_PORT',
                    'is_primary' => true,
                ],
                [
                    'allocation_id' => 7002,
                    'port' => 25566,
                    'environment_key' => 'QUERY_PORT',
                    'is_primary' => false,
                ],
                [
                    'allocation_id' => 7003,
                    'port' => 25567,
                    'environment_key' => 'QUERY_PORT',
                    'is_primary' => false,
                ],
            ],
        ], []);
    }

    public function test_existing_external_server_reconciles_the_pending_hold(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $this->markServiceReservationBacked($service);

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('beginProvisioning')
            ->once()
            ->with(Mockery::on(fn (Service $candidate) => $candidate->is($service)))
            ->andReturn([
                'reservation_id' => 92,
                'panel_identity' => hash('sha256', 'https://panel.example.com'),
                'node_id' => 7,
                'location_id' => 3,
                'memory' => 8192,
                'cpu' => 300,
                'disk' => 61440,
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$service->user_id}",
                'provisioning_lease_id' => 'lease-92',
                'already_consumed' => false,
                'allocations' => [[
                    'allocation_id' => 7001,
                    'ip' => '192.0.2.10',
                    'port' => 25565,
                    'environment_key' => 'SERVER_PORT',
                    'is_primary' => true,
                ]],
            ]);
        $reservationService->shouldReceive('completeProvisioning')
            ->once()
            ->with(
                Mockery::on(fn (Service $candidate) => $candidate->is($service)),
                'lease-92',
                Mockery::type('array')
            )
            ->andReturn(true);
        $this->app->instance(ReservationService::class, $reservationService);
        $this->enableReservationExtension();

        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public array $requests = [];

            public string $expectedEmail = '';

            public function request($url, $method = 'get', $data = []): array
            {
                $this->requests[] = compact('url', 'method', 'data');

                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    $externalId = (string) data_get($data, 'filter.external_id', '');

                    return PterodactylUserCollectionResponse::single(
                        $externalId !== '' ? [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => $externalId,
                                'email' => $this->expectedEmail,
                            ],
                        ]] : []
                    );
                }
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    return [
                        'attributes' => [
                            'id' => 72,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'existing',
                            'external_id' => (string) basename($url),
                            'status' => null,
                            'user' => 44,
                            'egg' => 2,
                            'nest' => 1,
                            'node' => 7,
                            'allocation' => 7001,
                            'feature_limits' => ['allocations' => 0],
                            'limits' => [
                                'memory' => 8192,
                                'cpu' => 300,
                                'disk' => 61440,
                            ],
                            'relationships' => [
                                'allocations' => [
                                    'data' => [[
                                        'attributes' => ['id' => 7001],
                                    ]],
                                ],
                            ],
                        ],
                    ];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };
        $pterodactyl->expectedEmail = (string) $service->user->email;

        $result = $pterodactyl->createServer($service, $this->baseSettings(), []);

        $this->assertSame(72, $result['server']);
        $this->assertSame('https://panel.example.com/server/existing', $result['link']);
        $userLookup = collect($pterodactyl->requests)
            ->first(fn (array $request) => $request['url'] === '/api/application/users'
                && strtolower($request['method']) === 'get');
        $this->assertSame(
            "paymenter-user-{$service->user_id}",
            data_get($userLookup, 'data.filter.external_id')
        );
        $this->assertNull(
            collect($pterodactyl->requests)
                ->first(fn (array $request) => strtolower($request['method']) === 'post')
        );
    }

    public function test_installing_external_server_retries_without_consuming_the_reservation(): void
    {
        $exception = $this->reservationServerStatusFailure(
            'installing'
        );

        $this->assertNotInstanceOf(
            PermanentProvisioningException::class,
            $exception
        );
        $this->assertStringContainsString(
            'provisioning will retry',
            $exception->getMessage()
        );
    }

    public function test_failed_install_states_are_permanent_and_never_consume_the_reservation(): void
    {
        foreach (['install_failed', 'reinstall_failed'] as $status) {
            $exception = $this->reservationServerStatusFailure($status);

            $this->assertInstanceOf(
                PermanentProvisioningException::class,
                $exception
            );
            $this->assertStringContainsString(
                "terminal state {$status}",
                $exception->getMessage()
            );
        }
    }

    public function test_reservation_activation_uses_the_exact_pterodactyl_status_contract(): void
    {
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'assertReservationServerInstallationReady'
        );
        $method->setAccessible(true);
        $provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);

        $method->invoke(
            $provisioner,
            ['attributes' => ['status' => null]]
        );
        $this->addToAssertionCount(1);

        try {
            $method->invoke(
                $provisioner,
                ['attributes' => ['status' => 'restoring_backup']]
            );
            $this->fail('Expected transient restore state retry.');
        } catch (\Exception $exception) {
            $this->assertNotInstanceOf(
                PermanentProvisioningException::class,
                $exception
            );
        }

        foreach ([
            ['attributes' => ['status' => 'suspended']],
            ['attributes' => ['status' => 'unknown']],
            ['attributes' => []],
        ] as $server) {
            try {
                $method->invoke($provisioner, $server);
                $this->fail('Expected non-ready status rejection.');
            } catch (PermanentProvisioningException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_dynamic_resource_service_fails_closed_when_reservations_are_unavailable(): void
    {
        $fixture = $this->createProduct();
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'metadata' => ['resource_type' => 'memory'],
        ]);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $fixture->product->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('provisioning was stopped');

        $this->fakeProvisioner()->createServer($service, $this->baseSettings(), []);
    }

    public function test_row_backed_service_cannot_fall_through_after_dynamic_metadata_is_removed(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        DB::table('ptero_resource_reservations')->insert([
            'token' => str_repeat('r', 64),
            'purpose' => 'checkout',
            'service_id' => $service->id,
            'service_guard_id' => $service->id,
            'user_id' => $service->user_id,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'calculated_price' => 12.50,
            'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'paid_committed',
            'expires_at' => now()->addDays(7),
            'guaranteed_until' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('provisioning was stopped');

        $this->fakeProvisioner()->createServer(
            $service,
            $this->baseSettings(),
            []
        );
    }

    public function test_panel_identity_mismatch_releases_the_attempt_lease_and_stops(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $this->markServiceReservationBacked($service);

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('beginProvisioning')->once()->andReturn([
            'reservation_id' => 93,
            'panel_identity' => hash('sha256', 'https://other-panel.example.com'),
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'provisioning_lease_id' => 'lease-93',
            'already_consumed' => false,
        ]);
        $reservationService->shouldReceive('failProvisioning')
            ->once()
            ->with(
                $service->id,
                'lease-93',
                Mockery::on(fn (\Throwable $exception) => str_contains(
                    $exception->getMessage(),
                    'different Pterodactyl panel'
                ))
            );
        $reservationService->shouldNotReceive('completeProvisioning');
        $this->app->instance(ReservationService::class, $reservationService);
        $this->enableReservationExtension();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('different Pterodactyl panel');

        $this->fakeProvisioner()->createServer($service, $this->baseSettings(), []);
    }

    public function test_termination_is_idempotent_and_verifies_external_absence(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public bool $deleted = false;

            public function request($url, $method = 'get', $data = []): array
            {
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    if ($this->deleted) {
                        throw new \Exception('Server not found', 404);
                    }

                    return ['attributes' => ['id' => 72]];
                }
                if ($url === '/api/application/servers/72' && strtolower($method) === 'delete') {
                    $this->deleted = true;

                    return [];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };

        $this->assertTrue($pterodactyl->terminateServer($service, [], []));
        $this->assertTrue($pterodactyl->deleted);
        $this->assertTrue($pterodactyl->terminateServer($service, [], []));
    }

    public function test_unpinned_cancellation_completes_when_external_service_id_is_absent(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $context = $this->cancellationReconciliationContext($service);
        $this->bindUnpinnedCancellationRuntime(
            $service,
            $context,
            expectPin: false
        );
        $pterodactyl = $this->cancellationReconciliationProvisioner(
            null,
            $context['user_external_id']
        );

        $this->assertTrue(
            $pterodactyl->terminateServer($service, [], [])
        );
        $this->assertSame(1, $pterodactyl->externalLookups);
        $this->assertSame(0, $pterodactyl->userLookups);
        $this->assertFalse($pterodactyl->deleteAttempted);
    }

    public function test_timed_out_create_is_proven_pinned_and_deleted_by_numeric_id(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $context = $this->cancellationReconciliationContext($service);
        $server = $this->cancellationCandidate($service);
        $this->bindUnpinnedCancellationRuntime(
            $service,
            $context,
            $server,
            true
        );
        $pterodactyl = $this->cancellationReconciliationProvisioner(
            $server,
            $context['user_external_id']
        );

        $this->assertTrue(
            $pterodactyl->terminateServer($service, [], [])
        );
        $this->assertTrue($pterodactyl->deleteAttempted);
        $this->assertSame(71, $pterodactyl->deletedServerId);
        $this->assertSame(1, $pterodactyl->externalLookups);
        $this->assertSame(2, $pterodactyl->userLookups);
        $this->assertSame(1, $pterodactyl->numericAbsenceChecks);
    }

    public function test_unpinned_cancellation_fails_closed_on_server_mismatch(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $context = $this->cancellationReconciliationContext($service);
        $server = $this->cancellationCandidate($service);
        $server['attributes']['limits']['disk']++;
        $this->bindUnpinnedCancellationRuntime(
            $service,
            $context,
            expectPin: false
        );
        $pterodactyl = $this->cancellationReconciliationProvisioner(
            $server,
            $context['user_external_id']
        );

        try {
            $pterodactyl->terminateServer($service, [], []);
            $this->fail('Expected cancellation mismatch rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'reserved disk',
                $exception->getMessage()
            );
        }

        $this->assertFalse($pterodactyl->deleteAttempted);
        $this->assertSame(1, $pterodactyl->externalLookups);
        $this->assertSame(1, $pterodactyl->userLookups);
    }

    public function test_create_cancel_race_retries_without_external_lookup_or_delete(): void
    {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $context = $this->cancellationReconciliationContext(
            $service,
            provisioningInFlight: true
        );
        $this->bindUnpinnedCancellationRuntime(
            $service,
            $context,
            expectPin: false
        );
        $pterodactyl = $this->cancellationReconciliationProvisioner(
            $this->cancellationCandidate($service),
            $context['user_external_id']
        );

        try {
            $pterodactyl->terminateServer($service, [], []);
            $this->fail('Expected active create retry.');
        } catch (\Exception $exception) {
            $this->assertNotInstanceOf(
                PermanentProvisioningException::class,
                $exception
            );
            $this->assertStringContainsString(
                'still in flight',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $pterodactyl->externalLookups);
        $this->assertSame(0, $pterodactyl->userLookups);
        $this->assertFalse($pterodactyl->deleteAttempted);
    }

    public function test_termination_never_deletes_a_replacement_with_the_same_external_id(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $payload = [
            'customer_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'panel_identity' => hash(
                'sha256',
                'https://panel.example.com'
            ),
            'node_id' => 7,
            'location_id' => 3,
            'resources' => [
                'memory' => 8192,
                'cpu' => 300,
                'disk' => 61440,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$user->id}",
                'user_email' => strtolower((string) $user->email),
            ],
        ];
        DB::table('ptero_resource_reservations')->insert([
            'token' => str_repeat('t', 64),
            'purpose' => 'checkout',
            'service_id' => $service->id,
            'service_guard_id' => $service->id,
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'panel_identity' => $payload['panel_identity'],
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'calculated_price' => 12.50,
            'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'configuration_fingerprint' => app(
                ReservationConfigurationService::class
            )->fingerprint($payload),
            'status' => 'confirmed',
            'expires_at' => now()->addDays(7),
            'guaranteed_until' => now()->addDays(7),
            'consumed_at' => now(),
            'cancellation_requested_at' => now(),
            'external_server_id' => 72,
            'external_user_id' => 44,
            'external_server_uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableReservationExtension();

        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public bool $deleted = false;

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                if (
                    $url === '/api/application/servers/72'
                    && strtolower($method) === 'get'
                ) {
                    return [
                        'attributes' => [
                            'id' => 72,
                            'uuid' => 'dfef2717-ef29-4918-98d4-20630b00bdda',
                            'identifier' => 'replacement',
                            'external_id' => (string) $this->serviceId,
                            'user' => 44,
                            'node' => 7,
                            'nest' => 1,
                            'egg' => 2,
                        ],
                    ];
                }
                if (
                    str_starts_with(
                        $url,
                        '/api/application/servers/'
                    )
                    && strtolower($method) === 'delete'
                ) {
                    $this->deleted = true;

                    return [];
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }

            public int $serviceId;
        };
        $pterodactyl->serviceId = $service->id;

        try {
            $pterodactyl->terminateServer($service, [], []);
            $this->fail('Expected replacement identity rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'durable server identity',
                $exception->getMessage()
            );
        }

        $this->assertFalse($pterodactyl->deleted);
        $this->assertSame(
            Service::STATUS_CANCELLATION_PENDING,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'service_id' => $service->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_missing_pinned_server_never_falls_back_to_external_id_replacement(): void
    {
        $this->requireDynamicPterodactylRuntime();

        $fixture = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_CANCELLATION_PENDING,
        ]);
        $payload = [
            'customer_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'panel_identity' => hash(
                'sha256',
                'https://panel.example.com'
            ),
            'node_id' => 7,
            'location_id' => 3,
            'resources' => [
                'memory' => 8192,
                'cpu' => 300,
                'disk' => 61440,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$user->id}",
                'user_email' => strtolower((string) $user->email),
            ],
        ];
        DB::table('ptero_resource_reservations')->insert([
            'token' => str_repeat('m', 64),
            'purpose' => 'checkout',
            'service_id' => $service->id,
            'service_guard_id' => $service->id,
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'panel_identity' => $payload['panel_identity'],
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'calculated_price' => 12.50,
            'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'configuration_fingerprint' => app(
                ReservationConfigurationService::class
            )->fingerprint($payload),
            'status' => 'confirmed',
            'expires_at' => now()->addDays(7),
            'guaranteed_until' => now()->addDays(7),
            'consumed_at' => now(),
            'cancellation_requested_at' => now(),
            'external_server_id' => 72,
            'external_user_id' => 44,
            'external_server_uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableReservationExtension();

        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public bool $deleted = false;

            public bool $externalLookupAttempted = false;

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                if (
                    $url === '/api/application/servers/72'
                    && strtolower($method) === 'get'
                ) {
                    throw new \Exception('Server not found', 404);
                }
                if (str_contains($url, '/servers/external/')) {
                    $this->externalLookupAttempted = true;

                    return [
                        'attributes' => [
                            'id' => 99,
                            'external_id' => (string) basename($url),
                        ],
                    ];
                }
                if (strtolower($method) === 'delete') {
                    $this->deleted = true;

                    return [];
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }
        };

        $this->assertTrue($pterodactyl->terminateServer($service, [], []));
        $this->assertFalse($pterodactyl->externalLookupAttempted);
        $this->assertFalse($pterodactyl->deleted);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'service_id' => $service->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_reconciled_delete_proves_absence_by_numeric_id_without_external_lookup(): void
    {
        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public bool $deleted = false;

            public bool $externalLookupAttempted = false;

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                if (
                    $url === '/api/application/servers/72'
                    && strtolower($method) === 'delete'
                ) {
                    $this->deleted = true;

                    return [];
                }
                if (
                    $url === '/api/application/servers/72'
                    && strtolower($method) === 'get'
                    && $this->deleted
                ) {
                    throw new \Exception('Server not found', 404);
                }
                if (str_contains($url, '/servers/external/')) {
                    $this->externalLookupAttempted = true;

                    return [
                        'attributes' => [
                            'id' => 99,
                            'external_id' => (string) basename($url),
                        ],
                    ];
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }
        };
        $delete = new \ReflectionMethod(
            Pterodactyl::class,
            'deleteReconciledServer'
        );
        $delete->setAccessible(true);

        $delete->invoke(
            $pterodactyl,
            ['attributes' => ['id' => 72]]
        );

        $this->assertTrue($pterodactyl->deleted);
        $this->assertFalse($pterodactyl->externalLookupAttempted);
    }

    public function test_termination_does_not_treat_api_failures_as_absence(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public function request($url, $method = 'get', $data = []): array
            {
                throw new \Exception('Panel unavailable', 503);
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Panel unavailable');

        $pterodactyl->terminateServer($service, [], []);
    }

    public function test_raw_upgrade_is_blocked_for_reservation_backed_service(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
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
            }
        );

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('capacity-aware upgrade coordinator');

        (new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]))->upgradeServer($service, [], []);
    }

    public function test_dynamic_upgrade_preserves_non_resource_build_fields_and_is_idempotent(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public int $buildRequests = 0;

            public int $startupRequests = 0;

            public array $lastBuildData = [];

            private array $limits = [
                'memory' => 4096,
                'cpu' => 100,
                'disk' => 20480,
                'swap' => 64,
                'io' => 600,
                'threads' => '0-1',
            ];

            private array $featureLimits = [
                'databases' => 2,
                'allocations' => 0,
                'backups' => 3,
            ];

            public function request($url, $method = 'get', $data = []): array
            {
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    return PterodactylUserCollectionResponse::single([[
                        'attributes' => [
                            'id' => 44,
                            'external_id' => (string) data_get(
                                $data,
                                'filter.external_id'
                            ),
                            'email' => 'previous@example.com',
                        ],
                    ]]);
                }
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    return [
                        'attributes' => [
                            'id' => 71,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'server-71',
                            'external_id' => (string) basename($url),
                            'user' => 44,
                            'nest' => 1,
                            'egg' => 2,
                            'node' => 7,
                            'allocation' => 7001,
                            'limits' => $this->limits,
                            'feature_limits' => $this->featureLimits,
                            'container' => [
                                'environment' => [],
                                'image' => 'ghcr.io/example/image',
                                'startup_command' => './server',
                            ],
                            'relationships' => [
                                'allocations' => [
                                    'data' => [
                                        ['attributes' => ['id' => 7002]],
                                        ['attributes' => ['id' => 7001]],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                if ($url === '/api/application/servers/71/build') {
                    $this->buildRequests++;
                    $this->lastBuildData = $data;
                    $this->limits = [
                        'memory' => (int) $data['memory'],
                        'cpu' => (int) $data['cpu'],
                        'disk' => (int) $data['disk'],
                        'swap' => (int) $data['swap'],
                        'io' => (int) $data['io'],
                        'threads' => $data['threads'],
                    ];
                    $this->featureLimits = $data['feature_limits'];

                    return [];
                }
                if ($url === '/api/application/servers/71/startup') {
                    $this->startupRequests++;

                    return [];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };
        $settings = array_merge($this->baseSettings(), [
            'memory' => 8192,
            'cpu' => 200,
            'disk' => 30720,
            'swap' => 4096,
            'io' => 999,
            'cpu_pinning' => '8-9',
            'databases' => 9,
            'additional_allocations' => 9,
            'backups' => 9,
        ]);
        $properties = [
            '_dynamic_upgrade' => $this->dynamicUpgradeContract(
                $service,
                assignedAllocations: [7001, 7002]
            ),
        ];

        $this->assertTrue(
            $pterodactyl->upgradeServer($service, $settings, $properties)
        );
        $this->assertSame(1, $pterodactyl->buildRequests);
        $this->assertSame(64, $pterodactyl->lastBuildData['swap']);
        $this->assertSame(600, $pterodactyl->lastBuildData['io']);
        $this->assertSame('0-1', $pterodactyl->lastBuildData['threads']);
        $this->assertSame([
            'databases' => 2,
            'allocations' => 0,
            'backups' => 3,
        ], $pterodactyl->lastBuildData['feature_limits']);
        $this->assertSame(0, $pterodactyl->startupRequests);
        $this->assertTrue(
            $pterodactyl->upgradeServer($service, $settings, $properties)
        );
        $this->assertSame(1, $pterodactyl->buildRequests);
        $this->assertSame(0, $pterodactyl->startupRequests);
    }

    public function test_dynamic_upgrade_rejects_an_unreserved_allocation(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public function request($url, $method = 'get', $data = []): array
            {
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    return PterodactylUserCollectionResponse::single([[
                        'attributes' => [
                            'id' => 44,
                            'external_id' => (string) data_get(
                                $data,
                                'filter.external_id'
                            ),
                        ],
                    ]]);
                }
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    return [
                        'attributes' => [
                            'id' => 71,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'server-71',
                            'external_id' => (string) basename($url),
                            'user' => 44,
                            'nest' => 1,
                            'egg' => 2,
                            'node' => 7,
                            'allocation' => 7001,
                            'limits' => [
                                'memory' => 4096,
                                'cpu' => 100,
                                'disk' => 20480,
                                'swap' => 64,
                                'io' => 600,
                                'threads' => '0-1',
                            ],
                            'feature_limits' => [
                                'databases' => 2,
                                'allocations' => 0,
                                'backups' => 3,
                            ],
                            'container' => ['environment' => []],
                            'relationships' => [
                                'allocations' => [
                                    'data' => [
                                        ['attributes' => ['id' => 7001]],
                                        ['attributes' => ['id' => 7002]],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };

        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('allocation set changed');

        $pterodactyl->upgradeServer(
            $service,
            array_merge($this->baseSettings(), [
                'memory' => 8192,
                'cpu' => 200,
                'disk' => 30720,
            ]),
            [
                '_dynamic_upgrade' => $this->dynamicUpgradeContract(
                    $service,
                    assignedAllocations: [7001]
                ),
            ]
        );
    }

    public function test_dynamic_upgrade_identity_rejects_each_pinned_field_change(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $contract = $this->dynamicUpgradeContract($service);
        $attributes = [
            'id' => 71,
            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'identifier' => 'server-71',
            'external_id' => (string) $service->id,
            'user' => 44,
            'nest' => 1,
            'egg' => 2,
        ];
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'serverMatchesDynamicUpgradeIdentity'
        );
        $method->setAccessible(true);
        $provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);

        $this->assertTrue(
            $method->invoke($provisioner, $attributes, $contract)
        );
        foreach ([
            ['uuid', '6e78b165-2e53-4ce0-95b4-dc590f4250d3'],
            ['identifier', 'replacement'],
            ['external_id', 'different-service'],
            ['user', 45],
            ['nest', 7],
            ['egg', 8],
        ] as [$field, $value]) {
            $changed = $attributes;
            $changed[$field] = $value;
            $this->assertFalse(
                $method->invoke($provisioner, $changed, $contract),
                "Expected {$field} identity drift to fail closed."
            );
        }
    }

    public function test_reconciliation_rejects_a_different_primary_allocation(): void
    {
        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('reserved primary allocation');

        $this->invokeReservationProof(
            externalPrimary: 11,
            externalAllocations: [10, 11]
        );
    }

    public function test_reconciliation_rejects_an_unreserved_extra_allocation(): void
    {
        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('does not exactly match');

        $this->invokeReservationProof(
            externalPrimary: 10,
            externalAllocations: [10, 11, 12]
        );
    }

    public function test_reconciliation_rejects_client_allocation_mutation_permission(): void
    {
        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('unreserved client allocation changes');

        $this->invokeReservationProof(
            externalPrimary: 10,
            externalAllocations: [10, 11],
            allocationLimit: 2
        );
    }

    public function test_reconciliation_rejects_a_different_customer_owner(): void
    {
        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('reserved user');

        $this->invokeReservationProof(
            externalPrimary: 10,
            externalAllocations: [10, 11],
            serverUserId: 43
        );
    }

    public function test_reconciliation_rejects_a_different_egg(): void
    {
        $this->expectException(PermanentProvisioningException::class);
        $this->expectExceptionMessage('reserved egg');

        $this->invokeReservationProof(
            externalPrimary: 10,
            externalAllocations: [10, 11],
            serverEggId: 3
        );
    }

    public function test_reserved_external_customer_reconciles_a_changed_paymenter_email(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $provisioner = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public ?array $patchedUser = null;

            public function request($url, $method = 'get', $data = []): array
            {
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    if (data_get($data, 'filter.email') !== null) {
                        return PterodactylUserCollectionResponse::single([]);
                    }

                    return PterodactylUserCollectionResponse::single([[
                        'attributes' => [
                            'id' => 44,
                            'external_id' => (string) data_get(
                                $data,
                                'filter.external_id'
                            ),
                            'email' => 'another-customer@example.com',
                        ],
                    ]]);
                }
                if ($url === '/api/application/users/44' && strtolower($method) === 'patch') {
                    $this->patchedUser = $data;

                    return ['attributes' => [
                        'id' => 44,
                        ...$data,
                    ]];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'resolvePterodactylUser'
        );
        $method->setAccessible(true);

        $this->assertSame(44, $method->invoke(
            $provisioner,
            $service,
            "paymenter-user-{$service->user_id}"
        ));
        $this->assertSame(
            strtolower((string) $service->user->email),
            strtolower((string) $provisioner->patchedUser['email'])
        );
    }

    public function test_duplicate_external_customer_identity_fails_before_email_fallback(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $externalId = "paymenter-user-{$service->user_id}";
        $provisioner = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret'], $externalId, (string) $service->user->email) extends Pterodactyl
        {
            public int $externalIdReads = 0;

            public int $emailReads = 0;

            public int $postAttempts = 0;

            public function __construct(
                array $config,
                private string $expectedExternalId,
                private string $expectedEmail
            ) {
                parent::__construct($config);
            }

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                $method = strtolower($method);
                if (
                    $url === '/api/application/users'
                    && $method === 'get'
                    && data_get($data, 'filter.external_id') !== null
                ) {
                    $this->externalIdReads++;

                    return PterodactylUserCollectionResponse::single([
                        $this->user(44),
                        $this->user(45),
                    ]);
                }
                if (
                    $url === '/api/application/users'
                    && $method === 'get'
                    && data_get($data, 'filter.email') !== null
                ) {
                    $this->emailReads++;

                    return PterodactylUserCollectionResponse::single([
                        $this->user(44),
                    ]);
                }
                if (
                    $url === '/api/application/users'
                    && $method === 'post'
                ) {
                    $this->postAttempts++;

                    return $this->user(44);
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }

            /**
             * @return array{attributes: array<string, mixed>}
             */
            private function user(int $id): array
            {
                return [
                    'attributes' => [
                        'id' => $id,
                        'external_id' => $this->expectedExternalId,
                        'email' => $this->expectedEmail,
                    ],
                ];
            }
        };
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'resolvePterodactylUser'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($provisioner, $service, $externalId);
            $this->fail('Expected duplicate external identity rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'multiple customers for the immutable Paymenter external ID',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $provisioner->externalIdReads);
        $this->assertSame(0, $provisioner->emailReads);
        $this->assertSame(0, $provisioner->postAttempts);
    }

    public function test_concurrent_first_customer_create_422_rereads_one_exact_identity(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $externalId = "paymenter-user-{$service->user_id}";
        $provisioner = $this->userCreationConflictProvisioner(
            $externalId,
            strtoupper((string) $service->user->email),
            true
        );
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'resolvePterodactylUser'
        );
        $method->setAccessible(true);

        $this->assertSame(
            44,
            $method->invoke($provisioner, $service, $externalId)
        );
        $this->assertSame(1, $provisioner->postAttempts);
        $this->assertSame(2, $provisioner->externalIdReads);
        $this->assertSame(2, $provisioner->emailReads);
    }

    public function test_customer_create_422_with_different_email_fails_closed(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $externalId = "paymenter-user-{$service->user_id}";
        $provisioner = $this->userCreationConflictProvisioner(
            $externalId,
            'different-customer@example.com',
            true
        );
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'resolvePterodactylUser'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($provisioner, $service, $externalId);
            $this->fail('Expected conflicting customer rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'different email identity',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $provisioner->postAttempts);
        $this->assertSame(2, $provisioner->externalIdReads);
        $this->assertSame(1, $provisioner->emailReads);
    }

    public function test_unrelated_customer_create_422_is_not_broadly_retried(): void
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $externalId = "paymenter-user-{$service->user_id}";
        $provisioner = $this->userCreationConflictProvisioner(
            $externalId,
            (string) $service->user->email,
            false
        );
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'resolvePterodactylUser'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($provisioner, $service, $externalId);
            $this->fail('Expected unproven 422 rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'exact external identity could not be proven',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $provisioner->postAttempts);
        $this->assertSame(2, $provisioner->externalIdReads);
        $this->assertSame(1, $provisioner->emailReads);
    }

    /**
     * @return array{0: Service, 1: int}
     */
    private function confirmedRenewalFixture(string $status): array
    {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => $status,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => 10,
            'expires_at' => now()->addMonth()->startOfDay(),
        ]);
        $checkoutInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'due_at' => now()->subMonth()->startOfDay(),
        ]);
        $checkoutInvoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Original dynamic checkout',
            'quantity' => 1,
            'price' => 10,
        ]);

        $panelIdentity = hash(
            'sha256',
            'https://panel.example.com'
        );
        $payload = [
            'customer_id' => $user->id,
            'cart_id' => 1,
            'server_extension_id' => 1,
            'panel_identity' => $panelIdentity,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'location_id' => 3,
            'node_id' => 7,
            'resources' => [
                'memory' => 8192,
                'cpu' => 300,
                'disk' => 61440,
            ],
            'calculated_price' => '10.00',
            'pricing_version' => hash('sha256', 'renewal-fixture'),
            'formula_version' => 'dynamic-pterodactyl-v1',
            'config_options' => [],
            'allocation_requirements' => [
                'required_count' => 1,
                'mappings' => [],
                'allowed_port_ranges' => [],
                'dedicated_ip' => false,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$user->id}",
                'user_email' => strtolower((string) $user->email),
            ],
        ];
        $reservationId = (int) DB::table(
            'ptero_resource_reservations'
        )->insertGetId([
            'token' => hash(
                'sha256',
                "confirmed-renewal-{$service->id}"
            ),
            'purpose' => 'checkout',
            'service_id' => $service->id,
            'service_guard_id' => $service->id,
            'invoice_id' => $checkoutInvoice->id,
            'user_id' => $user->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'server_extension_id' => 1,
            'panel_identity' => $panelIdentity,
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'calculated_price' => 10,
            'pricing_breakdown' => json_encode(
                [],
                JSON_THROW_ON_ERROR
            ),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'configuration_fingerprint' => app(
                ReservationConfigurationService::class
            )->fingerprint($payload),
            'pricing_version' => $payload['pricing_version'],
            'formula_version' => $payload['formula_version'],
            'status' => 'confirmed',
            'expires_at' => now()->subDay(),
            'guaranteed_until' => now()->subDay(),
            'paid_committed_at' => now()->subMonth(),
            'consumed_at' => now()->subMonth()->addMinute(),
            'external_server_id' => 72,
            'external_user_id' => 44,
            'external_server_uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'renewal-fixture',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        return [$service->fresh(['product', 'plan']), $reservationId];
    }

    private function renewalInvoice(
        Service $service,
        mixed $dueAt = null,
        mixed $price = null
    ): Invoice {
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => $service->currency_code,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $dueAt ?? $service->expires_at,
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Service renewal',
            'quantity' => $service->quantity,
            'price' => $price ?? $service->price,
        ]);

        return $invoice->fresh();
    }

    private function ordinaryUpgrade(string $status): ServiceUpgrade
    {
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
            'currency_code' => 'USD',
            'price' => 10,
        ]);

        return ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'status' => $status,
            'type' => 'product',
            'active_service_guard_id' => in_array($status, [
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            ], true)
                ? $service->id
                : null,
            'provisioning_attempts' => $status === ServiceUpgrade::STATUS_PROVISIONING ? 1 : 0,
            'quoted_amount' => 10,
            'currency_code' => 'USD',
        ]);
    }

    private function insertUpgradeReservation(
        ServiceUpgrade $upgrade,
        array $overrides = []
    ): int {
        $status = (string) ($overrides['status'] ?? 'pending');

        return (int) DB::table('ptero_resource_reservations')
            ->insertGetId(array_merge([
                'token' => hash(
                    'sha256',
                    "upgrade-reservation-{$upgrade->id}-{$status}"
                ),
                'purpose' => 'upgrade',
                'service_id' => $upgrade->service_id,
                'service_upgrade_id' => $upgrade->id,
                'upgrade_guard_id' => in_array(
                    $status,
                    ['pending', 'paid_committed'],
                    true
                )
                    ? $upgrade->id
                    : null,
                'user_id' => $upgrade->service->user_id,
                'product_id' => $upgrade->product_id,
                'plan_id' => $upgrade->plan_id,
                'node_id' => 7,
                'location_id' => 3,
                'memory' => 8192,
                'reserved_memory' => 4096,
                'cpu' => 200,
                'reserved_cpu' => 100,
                'disk' => 30720,
                'reserved_disk' => 10240,
                'calculated_price' => 10,
                'pricing_breakdown' => json_encode(
                    [],
                    JSON_THROW_ON_ERROR
                ),
                'status' => $status,
                'expires_at' => now()->addDays(7),
                'guaranteed_until' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
    }

    private function reservationServerStatusFailure(
        string $status
    ): \Throwable {
        $this->requireDynamicPterodactylRuntime();
        $fixture = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
        ]);
        $this->markServiceReservationBacked($service);
        $reservation = [
            'reservation_id' => 94,
            'panel_identity' => hash(
                'sha256',
                'https://panel.example.com'
            ),
            'node_id' => 7,
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'nest_id' => 1,
            'egg_id' => 2,
            'user_external_id' => "paymenter-user-{$service->user_id}",
            'provisioning_lease_id' => 'lease-status',
            'already_consumed' => false,
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];
        $recordedFailure = null;
        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('beginProvisioning')
            ->once()
            ->andReturn($reservation);
        $reservationService->shouldNotReceive('completeProvisioning');
        $reservationService->shouldNotReceive(
            'provisioningMayContinue'
        );
        $reservationService->shouldReceive('failProvisioning')
            ->once()
            ->with(
                $service->id,
                'lease-status',
                Mockery::on(function (\Throwable $exception) use (
                    &$recordedFailure
                ): bool {
                    $recordedFailure = $exception;

                    return true;
                })
            );
        $this->app->instance(
            ReservationService::class,
            $reservationService
        );
        if (!Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->exists()
        ) {
            $this->enableReservationExtension();
        }

        $provisioner = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret'], $status, (string) $service->user->email) extends Pterodactyl
        {
            public function __construct(
                array $config,
                private string $serverStatus,
                private string $expectedEmail
            ) {
                parent::__construct($config);
            }

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                if (
                    $url === '/api/application/users'
                    && strtolower($method) === 'get'
                ) {
                    $externalId = (string) data_get(
                        $data,
                        'filter.external_id',
                        ''
                    );

                    return PterodactylUserCollectionResponse::single(
                        $externalId !== '' ? [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => $externalId,
                                'email' => $this->expectedEmail,
                            ],
                        ]] : []
                    );
                }
                if (
                    str_starts_with(
                        $url,
                        '/api/application/servers/external/'
                    )
                ) {
                    return [
                        'attributes' => [
                            'id' => 72,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'existing',
                            'external_id' => (string) basename($url),
                            'status' => $this->serverStatus,
                            'user' => 44,
                            'egg' => 2,
                            'nest' => 1,
                            'node' => 7,
                            'allocation' => 7001,
                            'feature_limits' => [
                                'allocations' => 0,
                            ],
                            'limits' => [
                                'memory' => 8192,
                                'cpu' => 300,
                                'disk' => 61440,
                            ],
                            'relationships' => [
                                'allocations' => [
                                    'data' => [[
                                        'attributes' => [
                                            'id' => 7001,
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ];
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }
        };

        try {
            $provisioner->createServer(
                $service,
                $this->baseSettings(),
                []
            );
        } catch (\Throwable $exception) {
            $this->assertSame($exception, $recordedFailure);

            return $exception;
        }

        $this->fail(
            "Expected reservation activation to reject status {$status}."
        );
    }

    private function userCreationConflictProvisioner(
        string $expectedExternalId,
        string $resolvedEmail,
        bool $exposeExactIdentity
    ): Pterodactyl {
        return new class(['host' => 'https://panel.example.com', 'api_key' => 'secret'], $expectedExternalId, $resolvedEmail, $exposeExactIdentity) extends Pterodactyl
        {
            public int $postAttempts = 0;

            public int $externalIdReads = 0;

            public int $emailReads = 0;

            private bool $postFailed = false;

            public function __construct(
                array $config,
                private string $expectedExternalId,
                private string $resolvedEmail,
                private bool $exposeExactIdentity
            ) {
                parent::__construct($config);
            }

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                $method = strtolower($method);
                if (
                    $url === '/api/application/users'
                    && $method === 'get'
                ) {
                    if (
                        data_get($data, 'filter.external_id') !== null
                    ) {
                        $this->externalIdReads++;

                        return PterodactylUserCollectionResponse::single(
                            $this->postFailed
                                && $this->exposeExactIdentity
                                    ? [$this->resolvedUser()]
                                    : []
                        );
                    }
                    if (data_get($data, 'filter.email') !== null) {
                        $this->emailReads++;

                        return PterodactylUserCollectionResponse::single(
                            $this->postFailed
                                && $this->exposeExactIdentity
                                    ? [$this->resolvedUser()]
                                    : []
                        );
                    }
                }
                if (
                    $url === '/api/application/users'
                    && $method === 'post'
                ) {
                    $this->postAttempts++;
                    $this->postFailed = true;

                    throw new PermanentProvisioningException(
                        'The external ID or email has already been taken.',
                        422
                    );
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }

            /**
             * @return array<string, mixed>
             */
            private function resolvedUser(): array
            {
                return [
                    'attributes' => [
                        'id' => 44,
                        'external_id' => $this->expectedExternalId,
                        'email' => $this->resolvedEmail,
                    ],
                ];
            }
        };
    }

    private function fakeProvisioner(string $expectedEmail = ''): Pterodactyl
    {
        $provisioner = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public array $requests = [];

            public string $expectedEmail = '';

            private bool $created = false;

            private array $assignedAllocationIds = [];

            public function request($url, $method = 'get', $data = []): array
            {
                $this->requests[] = compact('url', 'method', 'data');

                if (str_starts_with($url, '/api/application/servers/external/')) {
                    if (!$this->created) {
                        throw new \Exception('Server not found');
                    }

                    return [
                        'attributes' => [
                            'id' => 71,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'created',
                            'external_id' => (string) basename($url),
                            'status' => null,
                            'user' => 44,
                            'egg' => 2,
                            'nest' => 1,
                            'node' => 7,
                            'allocation' => 7001,
                            'feature_limits' => ['allocations' => 0],
                            'limits' => [
                                'memory' => 8192,
                                'cpu' => 300,
                                'disk' => 61440,
                            ],
                            'relationships' => [
                                'allocations' => [
                                    'data' => collect(
                                        $this->assignedAllocationIds
                                    )->map(fn (int $id): array => [
                                        'attributes' => ['id' => $id],
                                    ])->all(),
                                ],
                            ],
                        ],
                    ];
                }
                if (str_contains($url, '/eggs/')) {
                    return [
                        'attributes' => [
                            'docker_image' => 'ghcr.io/example/image',
                            'startup' => './server',
                            'relationships' => ['variables' => ['data' => []]],
                        ],
                    ];
                }
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    $externalId = (string) data_get($data, 'filter.external_id', '');

                    return PterodactylUserCollectionResponse::single(
                        $externalId !== '' ? [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => $externalId,
                                'email' => $this->expectedEmail,
                            ],
                        ]] : []
                    );
                }
                if ($url === '/api/application/nodes/deployable') {
                    return [
                        'data' => [[
                            'attributes' => [
                                'id' => 7,
                                'relationships' => [
                                    'allocations' => [
                                        'data' => [[
                                            'attributes' => [
                                                'id' => 7001,
                                                'port' => 25565,
                                                'assigned' => false,
                                            ],
                                        ]],
                                    ],
                                ],
                            ],
                        ]],
                    ];
                }
                if ($url === '/api/application/servers' && strtolower($method) === 'post') {
                    $this->created = true;
                    $this->assignedAllocationIds = [
                        (int) $data['allocation']['default'],
                        ...array_map(
                            'intval',
                            (array) $data['allocation']['additional']
                        ),
                    ];

                    return [
                        'attributes' => [
                            'id' => 71,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'created',
                        ],
                    ];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };
        $provisioner->expectedEmail = $expectedEmail;

        return $provisioner;
    }

    private function invokeReservationProof(
        int $externalPrimary,
        array $externalAllocations,
        int $allocationLimit = 0,
        int $serverUserId = 44,
        int $serverEggId = 2,
        int $serverNestId = 1
    ): void {
        $provisioner = new Pterodactyl([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
        $method = new \ReflectionMethod(
            Pterodactyl::class,
            'assertServerMatchesReservation'
        );
        $method->setAccessible(true);
        $method->invoke($provisioner, [
            'attributes' => [
                'id' => 71,
                'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                'identifier' => 'created',
                'external_id' => '42',
                'user' => $serverUserId,
                'egg' => $serverEggId,
                'nest' => $serverNestId,
                'node' => 7,
                'allocation' => $externalPrimary,
                'feature_limits' => ['allocations' => $allocationLimit],
                'limits' => [
                    'memory' => 8192,
                    'cpu' => 300,
                    'disk' => 61440,
                ],
                'relationships' => [
                    'allocations' => [
                        'data' => collect($externalAllocations)
                            ->map(fn (int $id): array => [
                                'attributes' => ['id' => $id],
                            ])
                            ->all(),
                    ],
                ],
            ],
        ], [
            'node_id' => 7,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'allocations' => [
                [
                    'allocation_id' => 10,
                    'is_primary' => true,
                ],
                [
                    'allocation_id' => 11,
                    'is_primary' => false,
                ],
            ],
        ], 42, 44, 2, 1);
    }

    private function baseSettings(): array
    {
        return [
            'nest_id' => 1,
            'egg_id' => 2,
            'location_ids' => [99],
            'node' => null,
            'memory' => 1024,
            'swap' => 0,
            'disk' => 10240,
            'io' => 500,
            'cpu' => 100,
            'databases' => 0,
            'additional_allocations' => 0,
            'backups' => 0,
            'port_array' => '',
        ];
    }

    /**
     * @param  list<int>  $assignedAllocations
     * @return array<string, mixed>
     */
    private function dynamicUpgradeContract(
        Service $service,
        array $assignedAllocations = [7001, 7002]
    ): array {
        return [
            'panel_identity' => hash(
                'sha256',
                'https://panel.example.com'
            ),
            'external_server_id' => 71,
            'external_server_uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
            'external_server_external_id' => (string) $service->id,
            'external_user_id' => 44,
            'user_external_id' => "paymenter-user-{$service->user_id}",
            'user_email' => strtolower((string) $service->user->email),
            'nest_id' => 1,
            'egg_id' => 2,
            'node_id' => 7,
            'allocation_id' => 7001,
            'assigned_allocation_ids' => $assignedAllocations,
            'preserved_build' => [
                'swap' => 64,
                'io' => 600,
                'threads' => '0-1',
                'databases' => 2,
                'allocations' => 0,
                'backups' => 3,
            ],
            'source' => [
                'memory' => 4096,
                'cpu' => 100,
                'disk' => 20480,
            ],
            'target' => [
                'memory' => 8192,
                'cpu' => 200,
                'disk' => 30720,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unpinnedCancellationIdentity(Service $service): array
    {
        return [
            'reservation_id' => 93,
            'status' => 'paid_committed',
            'panel_identity' => hash(
                'sha256',
                'https://panel.example.com'
            ),
            'node_id' => 7,
            'external_server_id' => null,
            'external_user_id' => null,
            'external_server_uuid' => null,
            'external_server_identifier' => null,
            'external_server_external_id' => (string) $service->id,
            'user_external_id' => "paymenter-user-{$service->user_id}",
            'user_email' => strtolower((string) $service->user->email),
            'nest_id' => 1,
            'egg_id' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellationReconciliationContext(
        Service $service,
        bool $provisioningInFlight = false
    ): array {
        return [
            ...$this->unpinnedCancellationIdentity($service),
            'configuration_fingerprint' => str_repeat('a', 64),
            'location_id' => 3,
            'memory' => 8192,
            'cpu' => 300,
            'disk' => 61440,
            'client_allocation_limit' => 0,
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
            'provisioning_in_flight' => $provisioningInFlight,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellationCandidate(Service $service): array
    {
        return [
            'attributes' => [
                'id' => 71,
                'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                'identifier' => 'created',
                'external_id' => (string) $service->id,
                'user' => 44,
                'node' => 7,
                'nest' => 1,
                'egg' => 2,
                'allocation' => 7001,
                'limits' => [
                    'memory' => 8192,
                    'cpu' => 300,
                    'disk' => 61440,
                ],
                'feature_limits' => [
                    'allocations' => 0,
                ],
                'relationships' => [
                    'allocations' => [
                        'data' => [[
                            'attributes' => ['id' => 7001],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $server
     */
    private function bindUnpinnedCancellationRuntime(
        Service $service,
        array $context,
        ?array $server = null,
        bool $expectPin = false
    ): void {
        $this->app->instance(
            DurableFulfillmentService::class,
            new class extends DurableFulfillmentService
            {
                public function isReservationBacked(
                    Service $service
                ): bool {
                    return true;
                }
            }
        );

        $runtime = Mockery::mock(ReservationService::class);
        $runtime->shouldReceive('serverLifecycleIdentity')
            ->once()
            ->with(Mockery::on(
                fn (Service $candidate): bool => (int) $candidate->id === (int) $service->id
            ))
            ->andReturn($this->unpinnedCancellationIdentity($service));
        $runtime->shouldReceive('cancellationReconciliationContext')
            ->once()
            ->with(Mockery::type(Service::class))
            ->andReturn($context);
        if ($expectPin) {
            $attributes = $server['attributes'] ?? [];
            $runtime->shouldReceive('pinCancellationServerIdentity')
                ->once()
                ->with(
                    Mockery::type(Service::class),
                    Mockery::on(
                        fn (array $candidate): bool => ($candidate['attributes']['id'] ?? null) === 71
                    ),
                    44
                )
                ->andReturn([
                    ...$context,
                    'external_server_id' => 71,
                    'external_user_id' => 44,
                    'external_server_uuid' => $attributes['uuid'],
                    'external_server_identifier' => $attributes['identifier'],
                ]);
        } else {
            $runtime->shouldNotReceive(
                'pinCancellationServerIdentity'
            );
        }
        $this->app->instance(ReservationService::class, $runtime);
        $this->enableReservationExtension();
    }

    private function cancellationReconciliationProvisioner(
        ?array $server,
        string $expectedUserExternalId
    ): Pterodactyl {
        return new class(['host' => 'https://panel.example.com', 'api_key' => 'secret'], $server, $expectedUserExternalId) extends Pterodactyl
        {
            public int $externalLookups = 0;

            public int $userLookups = 0;

            public int $numericAbsenceChecks = 0;

            public bool $deleteAttempted = false;

            public ?int $deletedServerId = null;

            public function __construct(
                array $config,
                private ?array $candidate,
                private string $expectedUserExternalId
            ) {
                parent::__construct($config);
            }

            public function request(
                $url,
                $method = 'get',
                $data = []
            ): array {
                $method = strtolower($method);
                if (
                    str_starts_with(
                        $url,
                        '/api/application/servers/external/'
                    )
                    && $method === 'get'
                ) {
                    $this->externalLookups++;
                    if ($this->candidate === null) {
                        throw new \Exception('Server not found', 404);
                    }

                    return $this->candidate;
                }
                if (
                    $url === '/api/application/users'
                    && $method === 'get'
                ) {
                    $this->userLookups++;

                    return PterodactylUserCollectionResponse::single([[
                        'attributes' => [
                            'id' => 44,
                            'external_id' => $this->expectedUserExternalId,
                        ],
                    ]]);
                }
                if (
                    $url === '/api/application/servers/71'
                    && $method === 'delete'
                ) {
                    $this->deleteAttempted = true;
                    $this->deletedServerId = 71;
                    $this->candidate = null;

                    return [];
                }
                if (
                    $url === '/api/application/servers/71'
                    && $method === 'get'
                ) {
                    $this->numericAbsenceChecks++;
                    if ($this->candidate === null) {
                        throw new \Exception('Server not found', 404);
                    }

                    return $this->candidate;
                }

                throw new \RuntimeException(
                    "Unexpected request: {$method} {$url}"
                );
            }
        };
    }

    private function enableReservationExtension(): void
    {
        Extension::create([
            'name' => 'Dynamic Pterodactyl',
            'extension' => 'DynamicPterodactyl',
            'type' => 'other',
            'enabled' => true,
        ]);
    }

    private function markServiceReservationBacked(Service $service): void
    {
        $this->app->instance(
            DurableFulfillmentService::class,
            new class((int) $service->id) extends DurableFulfillmentService
            {
                public function __construct(
                    private readonly int $serviceId
                ) {}

                public function isReservationBacked(
                    Service $service
                ): bool {
                    return (int) $service->id === $this->serviceId;
                }
            }
        );
    }

    protected function migrateDatabases(): void
    {
        // Dynamic Pterodactyl's oldest migration predates Paymenter's cart
        // tables but references them by foreign key. Production installs run
        // core migrations first and extension migrations second; preserve that
        // same ordering when RefreshDatabase rebuilds a MariaDB test schema.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        if ($this->dynamicPterodactylMigrationsAreAvailable()) {
            $this->migrateDynamicPterodactyl();
        }
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (
            !RefreshDatabaseState::$migrated
            || !$this->dynamicPterodactylMigrationsAreAvailable()
            || $this->dynamicPterodactylMigrationsAreCurrent()
        ) {
            return;
        }

        // RefreshDatabase's migrated flag is shared by the whole PHPUnit
        // process. If another test migrated only Paymenter first, install the
        // extension schema now, before this test opens its transaction.
        $this->migrateDynamicPterodactyl();
    }

    private function requireDynamicPterodactylRuntime(): void
    {
        if (!class_exists(ReservationService::class)) {
            $this->markTestSkipped(
                'The companion DynamicPterodactyl checkout is not available.'
            );
        }

        $this->assertTrue(
            Schema::hasTable('ptero_resource_reservations'),
            'The DynamicPterodactyl migration set was not loaded.'
        );
    }

    private function dynamicPterodactylMigrationsAreAvailable(): bool
    {
        return class_exists(ReservationService::class)
            && is_dir(base_path(
                'extensions/Others/DynamicPterodactyl/database/migrations'
            ));
    }

    private function dynamicPterodactylMigrationsAreCurrent(): bool
    {
        return Schema::hasTable('ptero_resource_reservations')
            && Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where(
                    'migration',
                    '2026_07_26_000030_enforce_one_checkout_commitment_per_service'
                )
                ->exists();
    }

    private function migrateDynamicPterodactyl(): void
    {
        $this->artisan('migrate', [
            '--path' => 'extensions/Others/DynamicPterodactyl/database/migrations',
            '--force' => true,
        ]);
    }
}

final class PterodactylUserCollectionResponse
{
    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function single(
        array $data,
        string $endpoint = '/api/application/users'
    ): array {
        return self::page(
            $data,
            total: count($data),
            perPage: 50,
            currentPage: 1,
            totalPages: 1,
            endpoint: $endpoint
        );
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function page(
        array $data,
        int $total,
        int $perPage,
        int $currentPage,
        int $totalPages,
        string $endpoint = '/api/application/users'
    ): array {
        $links = [];
        if ($currentPage > 1) {
            $links['previous'] =
                'https://panel.example.com' . $endpoint . '?page='
                . ($currentPage - 1);
        }
        if ($currentPage < $totalPages) {
            $links['next'] =
                'https://panel.example.com' . $endpoint . '?page='
                . ($currentPage + 1);
        }

        return [
            'data' => $data,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'count' => count($data),
                    'per_page' => $perPage,
                    'current_page' => $currentPage,
                    'total_pages' => $totalPages,
                    'links' => $links,
                ],
            ],
        ];
    }
}

final class PterodactylConfigurationCollectionResponse
{
    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<int, array<string, mixed>>
     */
    public static function singlePage(
        string $endpoint,
        array $attributes
    ): array {
        return [
            1 => PterodactylUserCollectionResponse::single(
                array_map(
                    fn (array $record): array => [
                        'attributes' => $record,
                    ],
                    $attributes
                ),
                $endpoint
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<int, array<string, mixed>>
     */
    public static function twoPages(
        string $endpoint,
        array $first,
        array $second
    ): array {
        return [
            1 => PterodactylUserCollectionResponse::page(
                [['attributes' => $first]],
                total: 2,
                perPage: 1,
                currentPage: 1,
                totalPages: 2,
                endpoint: $endpoint
            ),
            2 => PterodactylUserCollectionResponse::page(
                [['attributes' => $second]],
                total: 2,
                perPage: 1,
                currentPage: 2,
                totalPages: 2,
                endpoint: $endpoint
            ),
        ];
    }
}

final class PterodactylConfigurationCollectionStub extends Pterodactyl
{
    /**
     * @var list<array{string, int}>
     */
    public array $requests = [];

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $pages
     * @param  array<string, \Throwable>  $failures
     */
    public function __construct(
        private array $pages,
        private array $failures = []
    ) {
        parent::__construct([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
    }

    public function request(
        $url,
        $method = 'get',
        $data = []
    ): array {
        if (
            strtolower($method) !== 'get'
            || !is_int($data['page'] ?? null)
        ) {
            throw new \RuntimeException(
                "Unexpected request: {$method} {$url}"
            );
        }

        $page = $data['page'];
        $this->requests[] = [$url, $page];
        if (isset($this->failures[$url])) {
            throw $this->failures[$url];
        }
        if (!isset($this->pages[$url][$page])) {
            throw new \RuntimeException(
                "Unexpected configuration lookup page: {$url} page {$page}"
            );
        }

        return $this->pages[$url][$page];
    }
}

final class PterodactylUserLookupStub extends Pterodactyl
{
    /**
     * @var list<array{
     *     filter: array<string, string>,
     *     page: int
     * }>
     */
    public array $requests = [];

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function __construct(private array $pages)
    {
        parent::__construct([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]);
    }

    public function request(
        $url,
        $method = 'get',
        $data = []
    ): array {
        if (
            $url !== '/api/application/users'
            || strtolower($method) !== 'get'
            || !is_array($data['filter'] ?? null)
            || !is_int($data['page'] ?? null)
        ) {
            throw new \RuntimeException(
                "Unexpected request: {$method} {$url}"
            );
        }

        $page = $data['page'];
        $this->requests[] = [
            'filter' => $data['filter'],
            'page' => $page,
        ];
        if (!array_key_exists($page, $this->pages)) {
            throw new \RuntimeException(
                "Unexpected customer lookup page: {$page}"
            );
        }

        return $this->pages[$page];
    }
}
