<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DynamicSliderPricingRule implements ValidationRule
{
    /**
     * Recognized pricing models and their required keys.
     */
    private const REQUIRED_KEYS = [
        'linear'     => ['rate_per_unit'],
        'tiered'     => ['tiers'],
        'base_addon' => ['included_units', 'overage_rate'],
    ];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The pricing configuration must be an array.');

            return;
        }

        $model = $value['model'] ?? null;

        // Reject unknown / missing model names
        if (! array_key_exists($model, self::REQUIRED_KEYS)) {
            $fail(
                'Unknown dynamic_slider pricing model "' . var_export($model, true) . '". '
                . 'Allowed values: ' . implode(', ', array_keys(self::REQUIRED_KEYS)) . '.'
            );

            return;
        }

        // Validate base_price is non-negative when present
        if (isset($value['base_price']) && (float) $value['base_price'] < 0) {
            $fail('The base price must be 0 or greater.');

            return;
        }

        // Check required keys per model
        foreach (self::REQUIRED_KEYS[$model] as $key) {
            if (! array_key_exists($key, $value)) {
                $fail("The pricing configuration is missing required key \"{$key}\" for model \"{$model}\".");

                return;
            }
        }

        // Model-specific validation
        match ($model) {
            'linear'     => $this->validateLinear($value, $fail),
            'tiered'     => $this->validateTiered($value, $fail),
            'base_addon' => $this->validateBaseAddon($value, $fail),
        };
    }

    private function validateLinear(array $pricing, Closure $fail): void
    {
        if ((float) ($pricing['rate_per_unit'] ?? 0) < 0) {
            $fail('The rate per unit must be 0 or greater.');
        }
    }

    private function validateTiered(array $pricing, Closure $fail): void
    {
        $tiers = $pricing['tiers'] ?? [];

        if (! is_array($tiers) || count($tiers) === 0) {
            $fail('Tiered pricing must have at least one tier.');

            return;
        }

        $previousUpTo = -1;

        foreach ($tiers as $index => $tier) {
            if (! is_array($tier)) {
                $fail('Each tier must be an array with "up_to" and "rate" keys.');

                return;
            }
            $tierNum = (int) $index + 1;

            if (! array_key_exists('rate', $tier)) {
                $fail("Tier {$tierNum} is missing a required \"rate\" value.");

                return;
            }

            if ((float) $tier['rate'] < 0) {
                $fail("Tier {$tierNum} rate must be 0 or greater.");

                return;
            }

            // up_to is optional (null/missing = unlimited), but when present must be strictly ascending
            if (isset($tier['up_to']) && $tier['up_to'] !== null && $tier['up_to'] !== '') {
                $upTo = (float) $tier['up_to'];

                if ($upTo <= $previousUpTo) {
                    $fail("Tier {$tierNum} \"up_to\" value ({$upTo}) must be strictly greater than the previous tier's \"up_to\" value ({$previousUpTo}).");

                    return;
                }

                $previousUpTo = $upTo;
            }
        }
    }

    private function validateBaseAddon(array $pricing, Closure $fail): void
    {
        if ((float) ($pricing['included_units'] ?? 0) < 0) {
            $fail('The included units must be 0 or greater.');

            return;
        }

        if ((float) ($pricing['overage_rate'] ?? 0) < 0) {
            $fail('The overage rate must be 0 or greater.');
        }
    }
}
