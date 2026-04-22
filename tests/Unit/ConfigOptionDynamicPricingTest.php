<?php

namespace Tests\Unit;

use App\Models\ConfigOption;
use PHPUnit\Framework\TestCase;

class ConfigOptionDynamicPricingTest extends TestCase
{
    private function createConfigOption(array $metadata): ConfigOption
    {
        $option = new ConfigOption();
        $option->type = 'dynamic_slider';
        $option->metadata = $metadata;

        return $option;
    }

    public function test_linear_pricing_calculates_correctly(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'linear',
                'base_price' => 5.0,
                'rate_per_unit' => 2.0,
            ],
            'display_divisor' => 1,
        ]);

        $price = $option->calculateDynamicPrice(10, 1, 'month');
        $this->assertEquals(25.0, $price); // 5 + (10 * 2)
    }

    public function test_tiered_pricing_calculates_correctly(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'tiered',
                'base_price' => 0,
                'tiers' => [
                    ['up_to' => 4, 'rate' => 3.0],
                    ['up_to' => 16, 'rate' => 2.5],
                    ['up_to' => null, 'rate' => 2.0],
                ],
            ],
            'display_divisor' => 1,
        ]);

        $price = $option->calculateDynamicPrice(10, 1, 'month');
        // (4 * 3) + (6 * 2.5) = 12 + 15 = 27
        $this->assertEquals(27.0, $price);
    }

    public function test_base_addon_pricing_calculates_correctly(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'base_addon',
                'base_price' => 5.0,
                'included_units' => 4,
                'overage_rate' => 2.5,
            ],
            'display_divisor' => 1,
        ]);

        $price = $option->calculateDynamicPrice(10, 1, 'month');
        // 5 + ((10 - 4) * 2.5) = 5 + 15 = 20
        $this->assertEquals(20.0, $price);
    }

    public function test_unknown_model_throws_exception(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'unknown_model',
                'rate_per_unit' => 1.0,
            ],
            'display_divisor' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_model');

        $option->calculateDynamicPrice(10, 1, 'month');
    }

    public function test_legacy_base_plus_addon_throws_exception(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'base_plus_addon',
                'rate_per_unit' => 1.0,
            ],
            'display_divisor' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base_plus_addon');

        $option->calculateDynamicPrice(10, 1, 'month');
    }

    public function test_non_dynamic_slider_returns_zero(): void
    {
        $option = new ConfigOption();
        $option->type = 'select';
        $option->metadata = [
            'pricing' => [
                'model' => 'linear',
                'rate_per_unit' => 2.0,
            ],
        ];

        $price = $option->calculateDynamicPrice(10, 1, 'month');
        $this->assertEquals(0.0, $price);
    }

    public function test_billing_period_multiplier_applies_correctly(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'linear',
                'base_price' => 5.0,
                'rate_per_unit' => 2.0,
            ],
            'display_divisor' => 1,
        ]);

        // Monthly billing (period=1, unit=month) = multiplier 1
        $monthlyPrice = $option->calculateDynamicPrice(10, 1, 'month');
        $this->assertEquals(25.0, $monthlyPrice);

        // 3-month billing = multiplier 3
        $quarterlyPrice = $option->calculateDynamicPrice(10, 3, 'month');
        $this->assertEquals(75.0, $quarterlyPrice);

        // Yearly billing = multiplier 12
        $yearlyPrice = $option->calculateDynamicPrice(10, 1, 'year');
        $this->assertEquals(300.0, $yearlyPrice);
    }

    public function test_display_divisor_applies_correctly(): void
    {
        $option = $this->createConfigOption([
            'pricing' => [
                'model' => 'linear',
                'base_price' => 0,
                'rate_per_unit' => 2.0,
            ],
            'display_divisor' => 1024, // MB to GB conversion
        ]);

        // 2048 MB with divisor 1024 = 2 GB displayed
        $price = $option->calculateDynamicPrice(2048, 1, 'month');
        $this->assertEquals(4.0, $price); // 2 GB * $2/GB
    }
}
