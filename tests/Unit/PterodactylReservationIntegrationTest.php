<?php

namespace Tests\Unit;

use App\Exceptions\PermanentProvisioningException;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\DurableFulfillmentService;
use App\Support\PanelEndpointIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('beginProvisioning')->once()->andReturn([
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
            public string $expectedEmail = '';

            public function request($url, $method = 'get', $data = []): array
            {
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    $externalId = (string) data_get($data, 'filter.external_id', '');

                    return [
                        'data' => $externalId !== '' ? [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => $externalId,
                                'email' => $this->expectedEmail,
                            ],
                        ]] : [],
                    ];
                }
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    return [
                        'attributes' => [
                            'id' => 72,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'existing',
                            'external_id' => (string) basename($url),
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
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableReservationExtension();

        $pterodactyl = new class([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]) extends Pterodactyl
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
                            'uuid' =>
                                'dfef2717-ef29-4918-98d4-20630b00bdda',
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
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableReservationExtension();

        $pterodactyl = new class([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]) extends Pterodactyl
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
        $pterodactyl = new class([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]) extends Pterodactyl
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
                    return [
                        'data' => [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => (string) data_get(
                                    $data,
                                    'filter.external_id'
                                ),
                                'email' => 'previous@example.com',
                            ],
                        ]],
                    ];
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
                    return [
                        'data' => [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => (string) data_get(
                                    $data,
                                    'filter.external_id'
                                ),
                            ],
                        ]],
                    ];
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
        $provisioner = new class([
            'host' => 'https://panel.example.com',
            'api_key' => 'secret',
        ]) extends Pterodactyl
        {
            public ?array $patchedUser = null;

            public function request($url, $method = 'get', $data = []): array
            {
                if ($url === '/api/application/users' && strtolower($method) === 'get') {
                    if (data_get($data, 'filter.email') !== null) {
                        return ['data' => []];
                    }

                    return [
                        'data' => [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => (string) data_get(
                                    $data,
                                    'filter.external_id'
                                ),
                                'email' => 'another-customer@example.com',
                            ],
                        ]],
                    ];
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
                    if (! $this->created) {
                        throw new \Exception('Server not found');
                    }

                    return [
                        'attributes' => [
                            'id' => 71,
                            'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                            'identifier' => 'created',
                            'external_id' => (string) basename($url),
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

                    return [
                        'data' => $externalId !== '' ? [[
                            'attributes' => [
                                'id' => 44,
                                'external_id' => $externalId,
                                'email' => $this->expectedEmail,
                            ],
                        ]] : [],
                    ];
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
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
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

    private function enableReservationExtension(): void
    {
        Extension::create([
            'name' => 'Dynamic Pterodactyl',
            'extension' => 'DynamicPterodactyl',
            'type' => 'other',
            'enabled' => true,
        ]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        $migrationPath = base_path(
            'extensions/Others/DynamicPterodactyl/database/migrations'
        );
        if (
            class_exists(ReservationService::class)
            && is_dir($migrationPath)
        ) {
            $this->app->make('migrator')->path($migrationPath);
        }
    }

    private function requireDynamicPterodactylRuntime(): void
    {
        if (! class_exists(ReservationService::class)) {
            $this->markTestSkipped(
                'The companion DynamicPterodactyl checkout is not available.'
            );
        }

        $this->assertTrue(
            Schema::hasTable('ptero_resource_reservations'),
            'The DynamicPterodactyl migration set was not loaded.'
        );
    }
}
