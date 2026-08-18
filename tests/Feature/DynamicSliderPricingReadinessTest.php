<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Services\Extensions\ExtensionLifecycleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DynamicSliderPricingReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_rejects_unmigrated_per_slider_base_price(): void
    {
        $fixture = $this->createProduct();
        $option = $this->legacySlider(
            $fixture->product,
            'Memory',
            5
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "option IDs: {$option->id}"
        );

        app(ExtensionLifecycleGuard::class)
            ->assertCanActivate('DynamicPterodactyl');
    }

    public function test_new_non_zero_metadata_base_price_cannot_be_saved(): void
    {
        $option = $this->slider('Memory');
        $metadata = $option->metadata;
        $metadata['pricing']['base_price'] = 5;
        $option->metadata = $metadata;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'unmigrated per-slider base price'
        );

        $option->save();
    }

    public function test_migration_moves_monthly_base_to_each_plan_once(): void
    {
        $fixture = $this->createProduct();
        $memory = $this->legacySlider(
            $fixture->product,
            'Memory',
            5
        );
        $disk = $this->legacySlider(
            $fixture->product,
            'Disk',
            5
        );
        $yearly = Plan::factory()->create([
            'priceable_id' => $fixture->product->id,
            'priceable_type' => Product::class,
            'name' => 'Yearly',
            'billing_unit' => 'year',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create([
            'plan_id' => $yearly->id,
            'price' => 100,
            'currency_code' => 'USD',
        ]);

        $this->artisan(
            'paymenter:migrate-slider-base-price',
            ['--force' => true]
        )->assertExitCode(0);

        $this->assertSame(
            5.0,
            $fixture->plan->fresh()->dynamicSliderBasePrice()
        );
        $this->assertSame(
            60.0,
            $yearly->fresh()->dynamicSliderBasePrice()
        );
        foreach ([$memory, $disk] as $option) {
            $this->assertSame(
                0.0,
                (float) data_get(
                    $option->fresh()->metadata,
                    'pricing.base_price'
                )
            );
        }
        app(ExtensionLifecycleGuard::class)
            ->assertCanActivate('DynamicPterodactyl');
    }

    public function test_conflicting_legacy_bases_fail_without_partial_changes(): void
    {
        $fixture = $this->createProduct();
        $memory = $this->legacySlider(
            $fixture->product,
            'Memory',
            5
        );
        $disk = $this->legacySlider(
            $fixture->product,
            'Disk',
            7
        );

        $this->artisan(
            'paymenter:migrate-slider-base-price',
            ['--force' => true]
        )
            ->expectsOutputToContain(
                'conflicting per-slider base prices'
            )
            ->assertExitCode(1);

        $this->assertSame(
            0.0,
            $fixture->plan->fresh()->dynamicSliderBasePrice()
        );
        $this->assertSame(
            5.0,
            (float) data_get(
                $memory->fresh()->metadata,
                'pricing.base_price'
            )
        );
        $this->assertSame(
            7.0,
            (float) data_get(
                $disk->fresh()->metadata,
                'pricing.base_price'
            )
        );
    }

    private function legacySlider(
        Product $product,
        string $name,
        float $basePrice
    ): ConfigOption {
        $option = $this->slider($name);
        DB::table('config_option_products')->insert([
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
        $metadata = $option->metadata;
        $metadata['pricing']['base_price'] = $basePrice;
        DB::table('config_options')
            ->where('id', $option->id)
            ->update([
                'metadata' => json_encode(
                    $metadata,
                    JSON_THROW_ON_ERROR
                ),
            ]);

        return $option->fresh();
    }

    private function slider(string $name): ConfigOption
    {
        return ConfigOption::create([
            'name' => $name,
            'env_variable' => strtoupper($name),
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
                'resource_type' => strtolower($name),
                'pricing' => [
                    'model' => 'linear',
                    'base_price' => 0,
                    'rate_per_unit' => 2,
                ],
            ],
        ]);
    }
}
