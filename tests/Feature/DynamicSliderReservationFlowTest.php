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

        $this->assertStringContainsString(
            'CapacityConfigurationLockService::class',
            $contents
        );
        $this->assertStringContainsString(
            ')->lockProduct((int) $item->product_id)',
            $contents
        );
        $this->assertStringNotContainsString(
            '$item->product->lockForUpdate();',
            $contents
        );
        $this->assertStringContainsString(
            '$requiresPayment = $cart->items->contains(',
            $contents
        );
        $this->assertStringNotContainsString(
            'if ($this->total->price > 0)',
            $contents
        );
        $this->assertStringContainsString('->reserveForCartItem($item)', $contents);
        $this->assertStringContainsString('->bindCartItemToService(', $contents);
        $invoiceLine = strpos(
            $contents,
            '$invoice->items()->create(['
        );
        $binding = strpos(
            $contents,
            '$binding[\'reservation_service\']->bindCartItemToService('
        );
        $bindingQueue = strpos($contents, '$capacityBindings = [];');
        $bindingSort = strpos($contents, 'usort(');
        $this->assertNotFalse($invoiceLine);
        $this->assertNotFalse($binding);
        $this->assertNotFalse($bindingQueue);
        $this->assertNotFalse($bindingSort);
        $this->assertLessThan($invoiceLine, $bindingQueue);
        $this->assertLessThan($bindingSort, $invoiceLine);
        $this->assertLessThan($binding, $invoiceLine);
        $this->assertStringContainsString(
            'CapacityServiceCreationCoordinator::run(',
            $contents
        );
        $this->assertStringNotContainsString('dp_reservation_token', $contents);
        $this->assertStringNotContainsString('->confirm(', $contents);
    }

    public function test_capacity_configuration_snapshot_uses_one_lock_order(): void
    {
        $contents = file_get_contents(
            app_path('Services/Service/CapacityConfigurationLockService.php')
        );
        $product = strpos($contents, 'Product::query()');
        $pivot = strpos($contents, "DB::table('config_option_products')");
        $option = strpos($contents, 'ConfigOption::query()');
        $plan = strpos($contents, 'Plan::query()');
        $price = strpos($contents, 'Price::query()');

        $this->assertNotFalse($product);
        $this->assertNotFalse($pivot);
        $this->assertNotFalse($option);
        $this->assertNotFalse($plan);
        $this->assertNotFalse($price);
        $this->assertLessThan($pivot, $product);
        $this->assertLessThan($option, $pivot);
        $this->assertLessThan($plan, $option);
        $this->assertLessThan($price, $plan);
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($contents, '->lockForUpdate()')
        );
        $this->assertStringContainsString(
            'Capacity configuration locks must be acquired inside the transaction',
            $contents
        );
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

    public function test_termination_requires_the_durable_runtime_before_external_delete(): void
    {
        $contents = file_get_contents(app_path('Jobs/Server/TerminateJob.php'));
        $terminal = strpos(
            $contents,
            '->cancellationIsDurablyComplete($this->service)'
        );
        $preflight = strpos($contents, '->assertRuntimeAvailable($this->service)');
        $externalDelete = strpos($contents, 'ExtensionHelper::terminateServer($this->service)');
        $completion = strpos($contents, '->completeCancellation($this->service)');

        $this->assertNotFalse($terminal);
        $this->assertNotFalse($preflight);
        $this->assertNotFalse($externalDelete);
        $this->assertNotFalse($completion);
        $this->assertLessThan($preflight, $terminal);
        $this->assertLessThan($externalDelete, $preflight);
        $this->assertLessThan($completion, $externalDelete);
        $this->assertStringContainsString(
            'Reservation-backed server cancellation requires operator intervention.',
            $contents
        );
        $this->assertStringContainsString(
            'NotificationHelper::sendSystemEmailNotification(',
            $contents
        );
    }

    public function test_provisioning_failure_has_a_missing_runtime_operator_fallback(): void
    {
        $contents = file_get_contents(app_path('Jobs/Server/CreateJob.php'));

        $this->assertStringContainsString(
            '->isReservationBacked($this->service)',
            $contents
        );
        $this->assertStringContainsString(
            'Reservation-backed server provisioning requires operator intervention.',
            $contents
        );
        $this->assertStringContainsString(
            'NotificationHelper::sendSystemEmailNotification(',
            $contents
        );
        $this->assertStringContainsString(
            'recording_error',
            $contents
        );
    }
}
