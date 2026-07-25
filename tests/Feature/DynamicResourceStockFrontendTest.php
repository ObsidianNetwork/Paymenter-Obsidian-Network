<?php

namespace Tests\Feature;

use Tests\TestCase;

class DynamicResourceStockFrontendTest extends TestCase
{
    public function test_checkout_blocks_until_a_live_complete_vector_quote_succeeds(): void
    {
        $view = file_get_contents(base_path('themes/default/views/products/checkout.blade.php'));
        $controller = file_get_contents(base_path('themes/default/js/dynamic-resource-stock.js'));

        $this->assertStringContainsString(
            'x-data="dynamicResourceStock(@js($dynamicStockConfig))"',
            $view
        );
        $this->assertStringContainsString(
            "'enabled' => \$usesDynamicStock",
            $view
        );
        $this->assertStringContainsString("'endpoint' =>", $view);
        $this->assertStringContainsString("'cartItemId' =>", $view);
        $this->assertStringContainsString('/resource-quote', $view);
        $this->assertStringContainsString('x-bind:disabled="!canCheckout"', $view);
        $this->assertStringContainsString("this.quoteState = 'loading'", $controller);
        $this->assertStringContainsString("this.quoteState = 'ready'", $controller);
        $this->assertStringContainsString('config_options:', $controller);
        $this->assertStringContainsString('cart_item_id:', $controller);
        $this->assertStringContainsString('role="alert"', $view);
        $this->assertStringContainsString('x-text="quoteError"', $view);
    }

    public function test_quote_controller_ignores_stale_responses_and_aborts_superseded_requests(): void
    {
        $controller = file_get_contents(base_path('themes/default/js/dynamic-resource-stock.js'));

        $this->assertStringContainsString('this._controller?.abort()', $controller);
        $this->assertStringContainsString('requestId !== this._requestId', $controller);
        $this->assertStringContainsString("error?.name === 'AbortError'", $controller);
    }

    public function test_slider_uses_live_bound_for_pointer_keyboard_and_accessibility(): void
    {
        $slider = file_get_contents(resource_path('views/components/form/dynamic-slider.blade.php'));

        $this->assertStringContainsString(':max="max"', $slider);
        $this->assertStringContainsString('keydown.end.prevent="value = max"', $slider);
        $this->assertStringContainsString(':aria-valuemax="max"', $slider);
        $this->assertStringContainsString(
            'dynamic-capacity-loading.window',
            $slider
        );
        $this->assertStringContainsString('applyCapacityQuote($event.detail)', $slider);
        $this->assertStringContainsString('Math.floor', $slider);
    }

    public function test_slider_ignores_removed_legacy_pricing_endpoints(): void
    {
        $slider = file_get_contents(
            resource_path('views/components/form/dynamic-slider.blade.php')
        );

        $this->assertStringNotContainsString(
            'pricing_endpoint',
            $slider
        );
        $this->assertStringNotContainsString(
            'fetchPricingPreview',
            $slider
        );
        $this->assertStringNotContainsString(
            'Pricing temporarily unavailable',
            $slider
        );
    }
}
