<?php

namespace Tests\Feature;

use App\Events\CartItem\Created;
use App\Exceptions\DisplayException;
use App\Models\ConfigOption;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicStockCheckoutRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_cart_rejection_invalidates_the_browser_quote_without_changing_values(): void
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
            'sort' => 1,
            'hidden' => false,
            'upgradable' => false,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1024,
                'max' => 32768,
                'step' => 1024,
                'default' => 8192,
                'display_divisor' => 1024,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 1,
                ],
            ],
        ]);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $fixture->product->id,
        ]);

        Event::listen(
            Created::class,
            static function (Created $event): void {
                throw new DisplayException(
                    'No node has enough capacity for this configuration.'
                );
            }
        );

        $component = Livewire::test('products.checkout', [
            'category' => $fixture->product->category,
            'product' => $fixture->product->slug,
        ]);
        $selectedBefore = $component->get('configOptions');

        $component
            ->call('checkout')
            ->assertDispatched('dynamic-stock-refresh-required')
            ->assertDispatched('notify');

        $this->assertSame(
            $selectedBefore,
            $component->get('configOptions')
        );
        $this->assertDatabaseMissing('cart_items', [
            'product_id' => $fixture->product->id,
        ]);
    }
}
