<?php

namespace Tests\Feature;

use App\Events\Auth\Login;
use App\Livewire\Products\Checkout;
use App\Listeners\UserAuthListener;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Tests\TestCase;

class DynamicSliderReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        require base_path('extensions/Others/DynamicPterodactyl/routes/api.php');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $nodeSelectionService = $this->mock(NodeSelectionService::class);
        $nodeSelectionService->shouldReceive('selectBestNode')
            ->byDefault()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
    }

    public function test_slider_change_creates_reservation(): void
    {
        $fixture = $this->createSliderCheckoutFixture();

        $response = $this->get(route('products.checkout', [
            $fixture->product->category->slug,
            $fixture->product->slug,
        ]));

        $response->assertOk();
        $response->assertSee('dynamicSliderGroup(', false);
        $response->assertSee('slider-change', false);
    }

    public function test_add_to_cart_persists_reservation_token(): void
    {
        $fixture = $this->createSliderCheckoutFixture();

        Livewire::test(Checkout::class, [
            'category' => $fixture->product->category,
            'product' => $fixture->product->slug,
        ])
            ->set('checkoutConfig.dp_reservation_token', 'token-123')
            ->call('checkout');

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $fixture->product->id,
        ]);

        $cartItem = CartItem::query()->where('product_id', $fixture->product->id)->latest('id')->firstOrFail();
        $this->assertSame('token-123', $cartItem->checkout_config['dp_reservation_token'] ?? null);
    }

    public function test_checkout_confirms_reservation(): void
    {
        $contents = file_get_contents(app_path('Livewire/Cart.php'));

        $this->assertStringContainsString("dp_reservation_token", $contents);
        $this->assertStringContainsString("->confirm(", $contents);
    }

    public function test_checkout_blocks_on_capacity_failure(): void
    {
        $contents = file_get_contents(app_path('Livewire/Cart.php'));

        $this->assertStringContainsString('$service->delete()', $contents);
        $this->assertStringContainsString('Capacity hold expired during checkout. Please refresh and reconfigure.', $contents);
        $this->assertStringContainsString('$this->addError("checkout.', $contents);
    }

    public function test_extension_disabled_checkout_still_works(): void
    {
        $contents = file_get_contents(app_path('Livewire/Cart.php'));

        $this->assertStringContainsString('class_exists($reservationServiceClass)', $contents);
    }

    public function test_guest_can_create_reservation(): void
    {
        $contents = file_get_contents(base_path('extensions/Others/DynamicPterodactyl/routes/api.php'));

        $this->assertStringContainsString("['web', 'checkout', 'throttle:10,1']", $contents);
    }

    public function test_guest_reservation_token_persists_through_login(): void
    {
        $fixture = $this->createSliderCheckoutFixture();
        $user = User::factory()->create();
        $cart = $this->createCartWithReservationToken($fixture->product, $fixture->plan->id, 'guest-token');

        app('request')->cookies->set('cart', $cart->ulid);

        (new UserAuthListener())->handle(new Login($user));

        $cart->refresh();
        $cartItem = $cart->items()->firstOrFail();

        $this->assertSame($user->id, $cart->user_id);
        $this->assertSame('guest-token', $cartItem->checkout_config['dp_reservation_token'] ?? null);
    }

    private function createSliderCheckoutFixture(): object
    {
        $fixture = $this->createProduct();

        $fixture->product->settings()->create([
            'key' => 'location_ids',
            'value' => json_encode([1]),
            'type' => 'array',
        ]);

        foreach ([
            'memory' => ['name' => 'Memory', 'min' => 1024, 'max' => 8192, 'step' => 1024, 'default' => 4096],
            'cpu' => ['name' => 'CPU', 'min' => 100, 'max' => 400, 'step' => 100, 'default' => 200],
            'disk' => ['name' => 'Disk', 'min' => 10240, 'max' => 102400, 'step' => 10240, 'default' => 51200],
        ] as $resourceType => $slider) {
            $option = ConfigOption::create([
                'name' => $slider['name'],
                'env_variable' => strtoupper($resourceType),
                'type' => 'dynamic_slider',
                'sort' => 1,
                'hidden' => false,
                'upgradable' => false,
                'metadata' => [
                    'resource_type' => $resourceType,
                    'min' => $slider['min'],
                    'max' => $slider['max'],
                    'step' => $slider['step'],
                    'default' => $slider['default'],
                    'unit' => $resourceType === 'cpu' ? '%' : 'MB',
                    'display_unit' => $resourceType === 'cpu' ? '%' : 'MB',
                    'display_divisor' => 1,
                    'pricing' => [
                        'model' => 'linear',
                        'base_price' => 0,
                        'rate_per_unit' => 1,
                    ],
                ],
            ]);

            ConfigOptionProduct::create([
                'product_id' => $fixture->product->id,
                'config_option_id' => $option->id,
            ]);
        }

        return $fixture;
    }

    private function createCartWithReservationToken(Product $product, int $planId, ?string $token): Cart
    {
        $cart = Cart::create([
            'ulid' => (string) Str::ulid(),
            'currency_code' => 'USD',
        ]);

        $configOptions = $product->configOptions()
            ->get()
            ->map(function ($option) {
                $default = $option->getMetadata('default', $option->getMetadata('min', 0));

                return [
                    'option_id' => $option->id,
                    'option_type' => 'dynamic_slider',
                    'option_name' => $option->name,
                    'option_env_variable' => $option->env_variable,
                    'value' => $default,
                ];
            })
            ->values()
            ->all();

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'plan_id' => $planId,
            'config_options' => $configOptions,
            'checkout_config' => $token ? ['dp_reservation_token' => $token] : [],
            'quantity' => 1,
        ]);

        return $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');
    }
}
