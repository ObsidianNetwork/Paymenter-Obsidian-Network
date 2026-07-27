<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->createProduct();
    }

    public function test_product_visible_on_overview_page(): void
    {
        $response = $this->get(route('category.show', [
            $this->product->product->category->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_product_visible_on_show_page(): void
    {
        $response = $this->get(route('products.show', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));
        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_page_redirects_to_cart_if_one_plan(): void
    {
        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertRedirect(route('cart'));
        $cart = Cart::where('currency_code', 'USD')->first();
        $this->assertNotNull($cart);

        $this->assertNotNull($cart->items()->first());
        $response->assertCookie('cart', $cart->ulid);

        Once::flush();

        $response = $this->withCookie('cart', $cart->ulid)->get(route('cart'));
        $response->assertCookie('cart', $cart->ulid);
        $response->assertStatus(200);
        $response->assertSeeText($this->product->product->name);

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
        ]);
    }

    public function test_checkout_page_with_multiple_plans(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        $response = $this->get(route('products.checkout', [
            $this->product->product->category->slug,
            $this->product->product->slug,
        ]));

        $response->assertStatus(200);
        $response->assertSee($this->product->product->name);
    }

    public function test_checkout_page_with_changed_plan(): void
    {
        // Add plan
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Change plan
        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->call('updatePricing')
            ->assertSee($this->product->plan->name)
            ->assertSee('$20.00')
            ->call('checkout');

        $this->assertDatabaseHas('carts', [
            'currency_code' => 'USD',
        ]);
    }

    public function test_checkout_page_with_plan_not_in_product(): void
    {
        $plan = $this->product->product->plans()->create([
            'name' => 'Test Plan 2',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $plan->prices()->create([
            'price' => 20.00,
            'currency_code' => 'USD',
        ]);

        // Add plan
        $plan = $this->createProduct()->plan;

        Livewire::test('products.checkout', ['category' => $this->product->product->category, 'product' => $this->product->product->slug])
            ->assertSee($this->product->product->name)
            ->assertSee('$10.00')
            ->set('plan_id', $plan->id)
            ->call('checkout')
            ->assertHasErrors(['plan_id' => 'in']);
    }

    public function test_checkout_atomically_consumes_the_cart_and_cannot_be_replayed(): void
    {
        config([
            'settings.mail_must_verify' => false,
            'settings.tos' => false,
        ]);
        $user = User::factory()->create();
        $cart = Cart::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
        ]);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'config_options' => [],
            'checkout_config' => [],
            'quantity' => 1,
        ]);
        Cookie::queue('cart', $cart->ulid);
        app('request')->cookies->set('cart', $cart->ulid);
        Once::flush();

        Livewire::actingAs($user)
            ->test('cart')
            ->call('checkout');

        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Service::query()->count());

        // Recreate the stale browser cookie that a concurrent request already
        // carried before the first checkout committed.
        app('request')->cookies->set('cart', $cart->ulid);
        Once::flush();
        Livewire::actingAs($user)
            ->test('cart')
            ->call('checkout');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Service::query()->count());
    }

    public function test_checkout_uses_the_locked_cart_coupon_for_every_price(): void
    {
        config([
            'settings.mail_must_verify' => false,
            'settings.tos' => false,
        ]);
        $user = User::factory()->create();
        $firstCycleCoupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'FIRST-CYCLE',
            'value' => 50,
            'recurring' => 1,
        ]);
        $recurringCoupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'RECURRING',
            'value' => 50,
            'recurring' => 2,
        ]);
        $cart = Cart::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'coupon_id' => $firstCycleCoupon->id,
        ]);
        $cart->items()->create([
            'product_id' => $this->product->product->id,
            'plan_id' => $this->product->plan->id,
            'config_options' => [],
            'checkout_config' => [],
            'quantity' => 1,
        ]);
        Cookie::queue('cart', $cart->ulid);
        app('request')->cookies->set('cart', $cart->ulid);
        Once::flush();

        $component = Livewire::actingAs($user)->test('cart');
        $this->assertSame(
            $firstCycleCoupon->id,
            $component->get('coupon')->id
        );

        // Simulate another tab changing the persisted cart after this
        // component mounted. Checkout must use the row reloaded under lock.
        Cart::query()->whereKey($cart->id)->update([
            'coupon_id' => $recurringCoupon->id,
        ]);
        $component->call('checkout');

        $service = Service::query()->sole();
        $invoice = Invoice::query()->sole();
        $this->assertSame(
            $recurringCoupon->id,
            $service->coupon_id
        );
        $this->assertSame('5.00', (string) $service->price);
        $this->assertSame(
            '5.00',
            (string) $invoice->items()->sole()->price
        );
    }

    public function test_coupon_validation_refreshes_the_locked_coupon_model(): void
    {
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'LOCKED-COUPON',
            'value' => 50,
            'recurring' => 1,
        ]);
        $cart = Cart::create([
            'currency_code' => 'USD',
            'coupon_id' => $coupon->id,
        ]);
        $cart->load('coupon');
        $this->assertSame(50.0, $cart->coupon->value);

        // Model an administrator update that commits after the relation was
        // loaded but before checkout obtains the coupon row lock.
        DB::table('coupons')->where('id', $coupon->id)->update([
            'value' => 25,
            'recurring' => 2,
            'updated_at' => now(),
        ]);

        $this->assertTrue(
            \App\Classes\Cart::validateAndRefreshCoupon(
                $cart,
                false
            )
        );
        $this->assertSame(25.0, $cart->coupon->value);
        $this->assertSame(2, (int) $cart->coupon->recurring);
    }

    public function test_dynamic_resource_values_must_be_integers_on_an_anchored_step(): void
    {
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'MEMORY',
            'type' => 'dynamic_slider',
            'sort' => 1,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'min' => 1024,
                'max' => 32768,
                'step' => 1024,
                'default' => 2048,
                'display_divisor' => 1024,
                'resource_type' => 'memory',
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 1,
                ],
            ],
        ]);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $this->product->product->id,
        ]);
        $alternatePlan = $this->product->product->plans()->create([
            'name' => 'Alternate Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $alternatePlan->prices()->create([
            'price' => 20,
            'currency_code' => 'USD',
        ]);

        $component = Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ]);
        $initialTotal = $component->get('total')->total;

        $component->set("configOptions.{$option->id}", '2048.5');
        $this->assertSame($initialTotal, $component->get('total')->total);
        $component
            ->call('checkout')
            ->assertHasErrors(["configOptions.{$option->id}"])
            ->set("configOptions.{$option->id}", 1536);
        $this->assertSame($initialTotal, $component->get('total')->total);
        $component
            ->call('checkout')
            ->assertHasErrors(["configOptions.{$option->id}"])
            ->set('plan_id', $alternatePlan->id);
        $alternateDefaultTotal = $component->get('total')->total;
        $this->assertNotSame($initialTotal, $alternateDefaultTotal);
        $component
            ->set("configOptions.{$option->id}", 3072);
        $this->assertNotSame(
            $alternateDefaultTotal,
            $component->get('total')->total
        );

        $instance = $component->instance();
        $instance->total = null;
        $instance->configOptions[$option->id] = 'invalid';
        $instance->updatePricing();
        $this->assertInstanceOf(
            Price::class,
            $instance->total
        );
    }

    public function test_custom_or_non_pterodactyl_slider_does_not_enable_stock_gate(): void
    {
        $option = ConfigOption::create([
            'name' => 'Custom amount',
            'env_variable' => 'CUSTOM_AMOUNT',
            'type' => 'dynamic_slider',
            'sort' => 1,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'min' => 1,
                'max' => 10,
                'step' => 1,
                'default' => 1,
                'display_divisor' => 1,
                'resource_type' => 'custom',
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 1,
                ],
            ],
        ]);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $this->product->product->id,
        ]);

        Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ])
            ->assertSee('dynamicResourceStock(', false)
            ->assertDontSee('/resource-quote', false);

        $option->update([
            'metadata' => [
                ...$option->metadata,
                'resource_type' => 'memory',
            ],
        ]);

        Livewire::test('products.checkout', [
            'category' => $this->product->product->category,
            'product' => $this->product->product->slug,
        ])
            ->assertSee('dynamicResourceStock(', false)
            ->assertDontSee('/resource-quote', false);
    }
}
