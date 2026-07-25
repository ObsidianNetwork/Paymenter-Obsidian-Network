<?php

namespace App\Rules;

use App\Models\ConfigOption;
use App\Support\StrictInteger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DynamicSliderValueRule implements ValidationRule
{
    public function __construct(private readonly ConfigOption $option) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $integerValue = StrictInteger::parse($value);
        if ($integerValue === null) {
            $fail('The :attribute value must be a whole internal resource unit.');

            return;
        }
        if ($integerValue > StrictInteger::MAX_STORED_SLIDER_VALUE) {
            $fail(
                'The :attribute value exceeds the largest exactly persisted resource unit.'
            );

            return;
        }

        $minimum = $this->integerMetadata('min');
        $maximum = $this->integerMetadata('max');
        $step = $this->integerMetadata('step');

        if ($minimum === null || $maximum === null || $step === null || $step <= 0) {
            $fail('The :attribute slider configuration is invalid.');

            return;
        }

        if ($integerValue < $minimum || $integerValue > $maximum) {
            $fail("The :attribute value must be between {$minimum} and {$maximum}.");

            return;
        }

        if (($integerValue - $minimum) % $step !== 0) {
            $fail("The :attribute value must follow increments of {$step} from {$minimum}.");
        }
    }

    private function integerMetadata(string $key): ?int
    {
        return StrictInteger::parse($this->option->getMetadata($key));
    }
}
