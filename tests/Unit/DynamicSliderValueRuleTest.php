<?php

namespace Tests\Unit;

use App\Models\ConfigOption;
use App\Rules\DynamicSliderMetadataRule;
use App\Rules\DynamicSliderValueRule;
use App\Support\StrictInteger;
use PHPUnit\Framework\TestCase;

class DynamicSliderValueRuleTest extends TestCase
{
    public function test_strict_integer_rejects_ambiguous_and_out_of_range_values(): void
    {
        $this->assertSame(1000, StrictInteger::parse(1000));
        $this->assertSame(1000, StrictInteger::parse('1000'));
        $this->assertNull(StrictInteger::parse(1000.0));
        $this->assertNull(StrictInteger::parse('1e3'));
        $this->assertNull(StrictInteger::parse('1e100'));
        $this->assertNull(StrictInteger::parse('01000'));
        $this->assertNull(StrictInteger::parse('-0'));
        $this->assertNull(StrictInteger::parse((string) PHP_INT_MAX.'0'));
    }

    public function test_stored_decimal_parser_only_accepts_exact_persistable_whole_values(): void
    {
        $this->assertSame(
            StrictInteger::MAX_STORED_SLIDER_VALUE,
            StrictInteger::parseStoredDecimal('99999999.0000')
        );
        $this->assertNull(
            StrictInteger::parseStoredDecimal('100000000.0000')
        );
        $this->assertNull(StrictInteger::parseStoredDecimal('1024.0001'));
        $this->assertNull(StrictInteger::parseStoredDecimal('1e3'));
    }

    public function test_value_rule_rejects_exponent_off_step_and_schema_overflow(): void
    {
        $option = $this->option([
            'min' => 0,
            'max' => StrictInteger::MAX_STORED_SLIDER_VALUE,
            'step' => 1,
            'default' => 0,
        ]);

        foreach (['1e3', '01024', 1024.0, '100000000'] as $value) {
            $this->assertNotEmpty($this->valueErrors($option, $value));
        }

        $stepped = $this->option([
            'min' => 1024,
            'max' => 4096,
            'step' => 1024,
            'default' => 1024,
        ]);
        $this->assertNotEmpty($this->valueErrors($stepped, '1536'));
        $this->assertSame([], $this->valueErrors($stepped, '2048'));
    }

    public function test_metadata_rule_rejects_exponents_and_unpersistable_maximum(): void
    {
        $base = [
            'min' => 0,
            'max' => 4096,
            'step' => 1024,
            'default' => 1024,
            'display_divisor' => 1024,
            'pricing' => [
                'model' => 'linear',
                'rate_per_unit' => 1,
            ],
        ];

        $this->assertNotEmpty($this->metadataErrors([
            ...$base,
            'max' => '1e100',
        ]));
        $this->assertNotEmpty($this->metadataErrors([
            ...$base,
            'max' => StrictInteger::MAX_STORED_SLIDER_VALUE + 1,
        ]));
        $this->assertSame([], $this->metadataErrors($base));
    }

    private function option(array $metadata): ConfigOption
    {
        return new ConfigOption([
            'name' => 'Memory',
            'type' => 'dynamic_slider',
            'metadata' => $metadata,
        ]);
    }

    private function valueErrors(ConfigOption $option, mixed $value): array
    {
        $errors = [];
        (new DynamicSliderValueRule($option))->validate(
            'resource',
            $value,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        return $errors;
    }

    private function metadataErrors(array $metadata): array
    {
        $errors = [];
        (new DynamicSliderMetadataRule())->validate(
            'metadata',
            $metadata,
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        return $errors;
    }
}
