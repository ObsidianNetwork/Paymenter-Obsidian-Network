<?php

namespace App\Rules;

use App\Models\ConfigOption;
use App\Support\StrictInteger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DynamicSliderMetadataRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The dynamic slider metadata must be an array.');

            return;
        }

        $integers = [];
        foreach (['min', 'max', 'step', 'default', 'display_divisor'] as $key) {
            $candidate = $value[$key] ?? null;
            $integer = StrictInteger::parse($candidate);
            if ($integer === null) {
                $fail("The dynamic slider {$key} value must be a canonical, in-range whole internal resource unit.");

                return;
            }

            $integers[$key] = $integer;
        }

        if ($integers['min'] < 0) {
            $fail('The dynamic slider minimum must be zero or greater.');

            return;
        }
        if ($integers['max'] < $integers['min']) {
            $fail('The dynamic slider maximum must be greater than or equal to its minimum.');

            return;
        }
        if ($integers['max'] > StrictInteger::MAX_STORED_SLIDER_VALUE) {
            $fail(
                'The dynamic slider maximum exceeds the largest exactly persisted resource unit.'
            );

            return;
        }
        if ($integers['step'] <= 0) {
            $fail('The dynamic slider step must be greater than zero.');

            return;
        }
        if ($integers['display_divisor'] <= 0) {
            $fail('The dynamic slider display divisor must be greater than zero.');

            return;
        }
        if ($integers['default'] < $integers['min'] || $integers['default'] > $integers['max']) {
            $fail('The dynamic slider default must be within its configured range.');

            return;
        }
        if (($integers['default'] - $integers['min']) % $integers['step'] !== 0) {
            $fail('The dynamic slider default must align to its configured step.');

            return;
        }
        if (($integers['max'] - $integers['min']) % $integers['step'] !== 0) {
            $fail('The dynamic slider maximum must align to its configured step.');

            return;
        }

        $pricing = $value['pricing'] ?? null;
        $errors = [];
        (new DynamicSliderPricingRule($integers['max'] / $integers['display_divisor']))
            ->validate(
                "{$attribute}.pricing",
                $pricing,
                function (string $message) use (&$errors): void {
                    $errors[] = $message;
                }
            );

        foreach ($errors as $error) {
            $fail($error);
        }
        if ($errors !== []) {
            return;
        }

        try {
            $option = new ConfigOption([
                'name' => 'Dynamic resource',
                'type' => 'dynamic_slider',
                'metadata' => $value,
            ]);
            $option->calculateDynamicPriceDelta(
                (float) $integers['max'],
                1,
                'month'
            );
        } catch (\InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
