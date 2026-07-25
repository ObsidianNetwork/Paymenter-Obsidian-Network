<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Http\Requests\Api\Admin\Services\CreateServiceRequest;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\Server;
use App\Models\User;
use App\Services\Service\CapacityServiceCreationCoordinator;
use App\Support\StrictInteger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DynamicResourcePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_maximum_accepted_slider_value_round_trips_exactly(): void
    {
        $option = $this->dynamicOption();

        $config = ServiceConfig::create([
            'configurable_id' => 1,
            'configurable_type' => Service::class,
            'config_option_id' => $option->id,
            'config_value_id' => null,
            'slider_value' => StrictInteger::MAX_STORED_SLIDER_VALUE,
        ]);

        $stored = rtrim(
            rtrim((string) $config->fresh()->slider_value, '0'),
            '.'
        );
        $this->assertSame('99999999', $stored);
        $this->assertSame(
            StrictInteger::MAX_STORED_SLIDER_VALUE,
            $option->normalizeDynamicSliderValue(
                StrictInteger::MAX_STORED_SLIDER_VALUE
            )
        );
        $this->expectException(\InvalidArgumentException::class);
        $option->normalizeDynamicSliderValue(
            StrictInteger::MAX_STORED_SLIDER_VALUE + 1
        );
    }

    public function test_hidden_only_slider_does_not_make_product_dynamic(): void
    {
        $product = $this->dynamicProduct();
        $option = $this->dynamicOption(['hidden' => true]);
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        $this->assertFalse($product->usesDynamicResources());

        $option->hidden = false;
        $option->save();

        $this->assertTrue($product->usesDynamicResources());
    }

    public function test_direct_model_write_rejects_dynamic_quantity_above_one(): void
    {
        $product = $this->dynamicProduct();
        $option = $this->dynamicOption();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        $this->expectException(ValidationException::class);
        Service::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 10,
            'currency_code' => 'USD',
            'status' => Service::STATUS_PENDING,
        ]);
    }

    public function test_admin_api_request_rejects_dynamic_quantity_above_one(): void
    {
        $product = $this->dynamicProduct();
        $option = $this->dynamicOption();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Monthly',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10,
            'currency_code' => 'USD',
        ]);
        $request = CreateServiceRequest::create('/api/admin/services', 'POST', [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'user_id' => User::factory()->create()->id,
            'quantity' => 2,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
            'price' => 10,
        ]);
        $request->setContainer(app());

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());
    }

    public function test_direct_model_backstop_rejects_pending_dynamic_service_creation(): void
    {
        $product = $this->dynamicProduct();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $this->dynamicOption()->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be created directly');

        Service::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10,
            'currency_code' => 'USD',
            'status' => Service::STATUS_PENDING,
        ]);
    }

    public function test_admin_api_rejects_pending_dynamic_service_creation(): void
    {
        $product = $this->dynamicProduct();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $this->dynamicOption()->id,
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Monthly',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $request = CreateServiceRequest::create(
            '/api/admin/services',
            'POST',
            [
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'user_id' => User::factory()->create()->id,
                'quantity' => 1,
                'status' => Service::STATUS_PENDING,
                'currency_code' => 'USD',
                'price' => 10,
            ]
        );
        $request->setContainer(app());

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey(
            'product_id',
            $validator->errors()->toArray()
        );
    }

    public function test_explicit_capacity_coordinator_can_create_dynamic_service_shell(): void
    {
        $product = $this->dynamicProduct();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $this->dynamicOption()->id,
        ]);

        $service = CapacityServiceCreationCoordinator::run(
            fn () => Service::create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10,
                'currency_code' => 'USD',
                'status' => Service::STATUS_PENDING,
            ])
        );

        $this->assertTrue($service->exists);
        $this->assertSame(Service::STATUS_PENDING, $service->status);
    }

    public function test_filament_rejects_pending_dynamic_service_creation(): void
    {
        $product = $this->dynamicProduct();
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $this->dynamicOption()->id,
        ]);
        $page = (new \ReflectionClass(CreateService::class))
            ->newInstanceWithoutConstructor();
        $guard = new \ReflectionMethod(
            CreateService::class,
            'mutateFormDataBeforeCreate'
        );
        $guard->setAccessible(true);

        try {
            $guard->invoke($page, ['product_id' => $product->id]);
            $this->fail(
                'Expected direct Filament dynamic service creation to fail.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'product_id',
                $exception->errors()
            );
        }
    }

    public function test_admin_api_backstop_rejects_conversion_to_dynamic_product(): void
    {
        $dynamicProduct = $this->dynamicProduct();
        ConfigOptionProduct::create([
            'product_id' => $dynamicProduct->id,
            'config_option_id' => $this->dynamicOption()->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity-aware upgrade coordinator');

        $service->product_id = $dynamicProduct->id;
        $service->save();
    }

    private function dynamicOption(array $attributes = []): ConfigOption
    {
        return ConfigOption::create(array_merge([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 0,
                'max' => StrictInteger::MAX_STORED_SLIDER_VALUE,
                'step' => 1,
                'default' => 0,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 0,
                ],
            ],
        ], $attributes));
    }

    private function dynamicProduct(): Product
    {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);

        return Product::factory()->create(['server_id' => $server->id]);
    }
}
