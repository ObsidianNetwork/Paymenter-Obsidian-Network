<?php

namespace Tests\Feature;

use App\Events\Auth\Login;
use App\Listeners\UserAuthListener;
use App\Models\Cart;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Tests\TestCase;

class DynamicSliderReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_browser_reservation_protocol_is_absent(): void
    {
        $checkoutView = file_get_contents(resource_path('../themes/default/views/products/checkout.blade.php'));
        $themeJavascript = file_get_contents(resource_path('../themes/default/js/app.js'));

        $this->assertStringNotContainsString('dynamicSliderGroup', $checkoutView);
        $this->assertStringNotContainsString('dp_reservation_token', $checkoutView);
        $this->assertStringNotContainsString('dynamicSliderGroup', $themeJavascript);
    }

    public function test_checkout_uses_server_owned_refresh_and_binding(): void
    {
        $contents = file_get_contents(app_path('Livewire/Cart.php'));

        $this->assertStringContainsString('->reserveForCartItem($item)', $contents);
        $this->assertStringContainsString('->bindCartItemToService(', $contents);
        $this->assertStringNotContainsString('dp_reservation_token', $contents);
        $this->assertStringNotContainsString('->confirm(', $contents);
    }

    public function test_guest_login_transfers_cart_and_holds_in_one_path(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create([
            'ulid' => (string) Str::ulid(),
            'currency_code' => 'USD',
        ]);

        Cookie::queue('cart', $cart->ulid);
        app('request')->cookies->set('cart', $cart->ulid);

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('transferCartOwnership')
            ->once()
            ->with($cart->id, $user->id)
            ->andReturn(0);
        $this->app->instance(ReservationService::class, $reservationService);
        Extension::create([
            'name' => 'Dynamic Pterodactyl',
            'extension' => 'DynamicPterodactyl',
            'type' => 'other',
            'enabled' => true,
        ]);

        (new UserAuthListener)->handle(new Login($user));

        $this->assertSame($user->id, $cart->fresh()->user_id);
    }

    public function test_free_service_dispatch_is_deferred_until_commit(): void
    {
        $contents = file_get_contents(app_path('Livewire/Cart.php'));

        $this->assertStringContainsString(
            'DB::afterCommit(fn () => CreateJob::dispatch($service))',
            $contents
        );
    }

    public function test_paid_service_dispatch_is_deferred_until_payment_commit(): void
    {
        $contents = file_get_contents(app_path('Services/Service/RenewServiceService.php'));

        $this->assertStringContainsString(
            'CreateJob::dispatch($service)->afterCommit()',
            $contents
        );
    }
}
