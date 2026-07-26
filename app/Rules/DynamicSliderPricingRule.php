<?php

namespace App\Rules;

use App\Support\StrictDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DynamicSliderPricingRule implements ValidationRule
{
    public function __construct(private readonly ?float $requiredCoverage = null) {}

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

        // Reject unknown / missing / non-string model names. is_string() guards against array_key_exists()
        // throwing TypeError when $model is e.g. an array (it accepts null/string/int only).
        if (! is_string($model) || ! array_key_exists($model, self::REQUIRED_KEYS)) {
            $fail(
                'Unknown dynamic_slider pricing model "' . var_export($model, true) . '". '
                . 'Allowed values: ' . implode(', ', array_keys(self::REQUIRED_KEYS)) . '.'
            );

            return;
        }

        // The shared base is authoritative at the plan level. Permit an
        // explicit zero only for compatibility with already-normalized
        // metadata; any non-zero copy would make browser and invoice math
        // disagree.
        if (array_key_exists('base_price', $value) && $value['base_price'] !== null && $value['base_price'] !== '') {
            $basePrice = $this->decimal(
                $value['base_price'],
                99_999_999.99
            );
            if ($basePrice === null) {
                $fail(
                    'The base price must be a finite non-negative numeric decimal with at most 8 decimal places.'
                );

                return;
            }
            if ($basePrice > 0) {
                $fail(
                    'Per-slider base prices are not supported. Configure the shared dynamic resource base price on the product plan.'
                );

                return;
            }
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
        if ($this->decimal($pricing['rate_per_unit']) === null) {
            $fail(
                'The rate per unit must be a finite non-negative numeric decimal with at most 8 decimal places.'
            );
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
        $lastIndex    = array_key_last($tiers);

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

            if ($this->decimal($tier['rate']) === null) {
                $fail(
                    "Tier {$tierNum} rate must be a finite non-negative numeric decimal with at most 8 decimal places."
                );

                return;
            }

            // Empty-string up_to is rejected explicitly: the runtime (ConfigOption::calculateTieredDelta)
            // only treats null/missing as unlimited, while (float) '' coerces to 0 — a divergence
            // that would silently mis-price. Force the caller to use null or omit the key.
            if (array_key_exists('up_to', $tier) && $tier['up_to'] === '') {
                $fail("Tier {$tierNum} \"up_to\" cannot be an empty string; use null or omit the key for unlimited.");

                return;
            }

            // up_to is optional (null/missing = unlimited), but only valid as the LAST tier.
            $hasUpTo = array_key_exists('up_to', $tier) && $tier['up_to'] !== null;

            if (! $hasUpTo) {
                if ($index !== $lastIndex) {
                    $fail("Tier {$tierNum} is unlimited (no \"up_to\"), so it must be the last tier.");

                    return;
                }
                continue;
            }

            $upTo = $this->decimal($tier['up_to']);
            if ($upTo === null) {
                $fail(
                    "Tier {$tierNum} \"up_to\" must be a finite non-negative numeric decimal with at most 8 decimal places."
                );

                return;
            }

            if ($upTo <= $previousUpTo) {
                $fail("Tier {$tierNum} \"up_to\" value ({$upTo}) must be strictly greater than the previous tier's \"up_to\" value ({$previousUpTo}).");

                return;
            }

            $previousUpTo = $upTo;
        }

        $lastTier = $tiers[$lastIndex] ?? null;
        $lastUpTo = is_array($lastTier) ? ($lastTier['up_to'] ?? null) : null;
        if (
            $this->requiredCoverage !== null
            && $lastUpTo !== null
            && ($parsedLastUpTo = $this->decimal($lastUpTo)) !== null
            && $parsedLastUpTo < $this->requiredCoverage
        ) {
            $fail(sprintf(
                'The final pricing tier must be unlimited or cover the slider maximum of %s display units.',
                rtrim(rtrim(number_format($this->requiredCoverage, 4, '.', ''), '0'), '.')
            ));
        }
    }

    private function validateBaseAddon(array $pricing, Closure $fail): void
    {
        if ($this->decimal($pricing['included_units']) === null) {
            $fail(
                'The included units must be a finite non-negative numeric decimal with at most 8 decimal places.'
            );

            return;
        }

        if ($this->decimal($pricing['overage_rate']) === null) {
            $fail(
                'The overage rate must be a finite non-negative numeric decimal with at most 8 decimal places.'
            );
        }
    }

    private function decimal(
        mixed $value,
        float $maximum = StrictDecimal::MAX_VALUE
    ): ?float {
        return StrictDecimal::parseNonNegative($value, $maximum);
    }
}
