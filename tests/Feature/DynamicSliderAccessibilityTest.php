<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class DynamicSliderAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_slider_renders_aria_attributes_and_live_regions(): void
    {
        $fixture = $this->createProduct();

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

        $this->assertStringContainsString('role="slider"', $html);
        $this->assertStringContainsString(':aria-valuemin="min"', $html);
        $this->assertStringContainsString(':aria-valuemax="max"', $html);
        $this->assertStringContainsString(':aria-valuenow="value"', $html);
        $this->assertStringContainsString(':aria-valuetext="formattedValue"', $html);
        $this->assertStringContainsString('aria-labelledby="slider-label-'.$option->id.'"', $html);
        $this->assertStringContainsString('aria-describedby="slider-price-'.$option->id.' slider-hint-'.$option->id.'"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('class="sr-only"', $html);
        $this->assertStringContainsString('dynamic-capacity-updated.window', $html);
        $this->assertStringContainsString(':disabled="stockDisabled"', $html);
        $this->assertStringContainsString(
            'dynamic-capacity-loading.window="if (stockManaged) stockDisabled = false"',
            $html
        );
        $this->assertStringContainsString(
            'dynamic-capacity-failed.window="if (stockManaged) stockDisabled = false"',
            $html
        );
        $this->assertStringContainsString('aria-invalid="false"', $html);

        // Smoke: obsidian theme delegates to the same shared partial
        $obsidianHtml = view()->file(base_path('themes/obsidian/views/components/form/configoption.blade.php'), [
            'config' => $option->fresh(),
            'name' => "configOptions.{$option->id}",
            'plan' => $fixture->plan,
            'showPriceTag' => true,
        ])->render();

        $this->assertStringContainsString('role="slider"', $obsidianHtml);
    }

    public function test_dynamic_slider_renders_and_associates_its_validation_error(): void
    {
        $fixture = $this->createProduct();

        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'MEMORY',
            'type' => 'dynamic_slider',
            'sort' => 1,
            'hidden' => false,
            'upgradable' => false,
            'metadata' => [
                'min' => 1024,
                'max' => 32768,
                'step' => 1024,
                'default' => 2048,
                'unit' => 'MB',
                'display_unit' => 'GB',
                'display_divisor' => 1024,
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

        $name = "configOptions.{$option->id}";
        $errorId = "slider-error-{$option->id}";
        $errors = (new ViewErrorBag())->put(
            'default',
            new MessageBag([
                $name => ['Choose a whole value on the configured step.'],
            ])
        );
        $html = view('components.form.configoption', [
            'config' => $option->fresh(),
            'name' => $name,
            'plan' => $fixture->plan,
            'showPriceTag' => true,
            'errors' => $errors,
        ])->render();

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString(
            'aria-errormessage="'.$errorId.'"',
            $html
        );
        $this->assertStringContainsString(
            'aria-describedby="slider-price-'.$option->id
                .' slider-hint-'.$option->id.' '.$errorId.'"',
            $html
        );
        $this->assertStringContainsString(
            'id="'.$errorId.'" role="alert"',
            $html
        );
        $this->assertStringContainsString(
            'Choose a whole value on the configured step.',
            $html
        );
    }

    public function test_dynamic_slider_renders_focus_visible_focus_ring_classes(): void
    {
        $fixture = $this->createProduct();

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

        $this->assertStringContainsString('focus-visible:ring-2', $html);

        // Smoke: obsidian theme delegates to the same shared partial
        $obsidianHtml = view()->file(base_path('themes/obsidian/views/components/form/configoption.blade.php'), [
            'config' => $option->fresh(),
            'name' => "configOptions.{$option->id}",
            'plan' => $fixture->plan,
            'showPriceTag' => true,
        ])->render();

        $this->assertStringContainsString('role="slider"', $obsidianHtml);
    }
}
