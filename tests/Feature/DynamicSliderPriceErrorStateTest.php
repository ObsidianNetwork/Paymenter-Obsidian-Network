<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DynamicSliderPriceErrorStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_slider_renders_capacity_failure_bindings(): void
    {
        $fixture = $this->createProduct();
        $fixture->plan->forceFill([
            'dynamic_slider_base_price' => 5,
        ])->save();

        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'MEMORY',
            'type' => 'dynamic_slider',
            'sort' => 1,
            'hidden' => false,
            'upgradable' => false,
            'metadata' => [
                'min' => 1,
                'max' => 64,
                'step' => 1,
                'default' => 8,
                'unit' => 'GB',
                'display_unit' => 'GB',
                'display_divisor' => 1,
                'resource_type' => 'memory',
                'pricing' => [
                    'model' => 'linear',
                    'base_price' => 0,
                    'rate_per_unit' => 2,
                ],
            ],
        ]);

        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $fixture->product->id,
        ]);

        $html = view('components.form.configoption', [
            'config' => $option->fresh(),
            'name' => "configOptions.{$option->id}",
            'plan' => $fixture->plan,
            'showPriceTag' => true,
        ])->render();

        $this->assertStringContainsString('stockDisabled: false', $html);
        $this->assertStringContainsString(
            'dynamic-capacity-failed.window',
            $html
        );
        $this->assertStringContainsString(
            'dynamic-capacity-failed.window="if (stockManaged) stockDisabled = false"',
            $html
        );
        $this->assertStringContainsString(':disabled="stockDisabled"', $html);
        $this->assertStringContainsString('basePrice: 0', $html);
        $this->assertStringNotContainsString(
            'basePrice: 5',
            $html
        );
    }

    public function test_checkout_renders_the_plan_shared_base_once(): void
    {
        $source = file_get_contents(
            base_path(
                'themes/default/views/products/checkout.blade.php'
            )
        );

        $this->assertSame(
            1,
            substr_count(
                $source,
                '$plan->dynamicSliderBasePrice()'
            )
        );
        $this->assertStringContainsString(
            'Dynamic resource base:',
            $source
        );
    }
}
