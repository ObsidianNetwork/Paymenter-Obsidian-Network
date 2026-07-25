<?php

namespace Tests\Unit;

use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Servers\Pterodactyl\Pterodactyl;
use Tests\TestCase;

class PterodactylReservationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reserved_node_and_limits_drive_the_create_request(): void
    {
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
                'provisioning_lease_id' => 'lease-91',
                'already_consumed' => false,
            ]);
        $reservationService->shouldReceive('completeProvisioning')
            ->once()
            ->with($service->id, 'lease-91')
            ->andReturn(true);
        $reservationService->shouldNotReceive('failProvisioning');
        $this->app->instance(ReservationService::class, $reservationService);
        $this->enableReservationExtension();

        $pterodactyl = $this->fakeProvisioner();
        $result = $pterodactyl->createServer($service, $this->baseSettings(), [
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
            'node' => 2,
        ]);

        $serverRequest = collect($pterodactyl->requests)
            ->first(fn (array $request) => $request['url'] === '/api/application/servers'
                && strtolower($request['method']) === 'post');
        $deployableRequest = collect($pterodactyl->requests)
            ->first(fn (array $request) => $request['url'] === '/api/application/nodes/deployable');

        $this->assertSame(71, $result['server']);
        $this->assertSame(8192, $serverRequest['data']['limits']['memory']);
        $this->assertSame(300, $serverRequest['data']['limits']['cpu']);
        $this->assertSame(61440, $serverRequest['data']['limits']['disk']);
        $this->assertSame(8192, $deployableRequest['data']['memory']);
        $this->assertSame(61440, $deployableRequest['data']['disk']);
        $this->assertSame([3], $deployableRequest['data']['location_ids']);
        $this->assertSame(7001, $serverRequest['data']['allocation']['default']);
    }

    public function test_existing_external_server_reconciles_the_pending_hold(): void
    {
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
            'provisioning_lease_id' => 'lease-92',
            'already_consumed' => false,
        ]);
        $reservationService->shouldReceive('completeProvisioning')
            ->once()
            ->with($service->id, 'lease-92')
            ->andReturn(true);
        $this->app->instance(ReservationService::class, $reservationService);
        $this->enableReservationExtension();

        $pterodactyl = new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public function request($url, $method = 'get', $data = []): array
            {
                if (str_starts_with($url, '/api/application/servers/external/')) {
                    return ['attributes' => ['id' => 72, 'identifier' => 'existing']];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };

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

    public function test_panel_identity_mismatch_releases_the_attempt_lease_and_stops(): void
    {
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

    private function fakeProvisioner(): Pterodactyl
    {
        return new class(['host' => 'https://panel.example.com', 'api_key' => 'secret']) extends Pterodactyl
        {
            public array $requests = [];

            public function request($url, $method = 'get', $data = []): array
            {
                $this->requests[] = compact('url', 'method', 'data');

                if (str_starts_with($url, '/api/application/servers/external/')) {
                    throw new \Exception('Server not found');
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
                    return ['data' => [['attributes' => ['id' => 44]]]];
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
                    return ['attributes' => ['id' => 71, 'identifier' => 'created']];
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        };
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

    private function enableReservationExtension(): void
    {
        Extension::create([
            'name' => 'Dynamic Pterodactyl',
            'extension' => 'DynamicPterodactyl',
            'type' => 'other',
            'enabled' => true,
        ]);
    }
}
