<?php

namespace App\Admin\Resources\ConfigOptionResource\Concerns;

use App\Rules\DynamicSliderMetadataRule;
use Filament\Notifications\Notification;

/**
 * Runs DynamicSliderPricingRule against submitted config-option form data
 * before Filament persists it. On failure, sends a danger notification
 * and halts the page action so no invalid pricing ever reaches the DB.
 */
trait ValidatesDynamicSliderPricing
{
    protected function validateDynamicSliderPricing(array $data): void
    {
        if (($data['type'] ?? null) !== 'dynamic_slider') {
            return;
        }

        $metadata = $data['metadata'] ?? null;

        if ($metadata === null) {
            Notification::make()
                ->title('Invalid pricing configuration')
                ->body('Dynamic slider options require range and pricing metadata.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        $errors = [];
        (new DynamicSliderMetadataRule())->validate(
            'metadata',
            $metadata,
            function (string $message) use (&$errors) {
                $errors[] = $message;
            }
        );

        if ($errors === []) {
            return;
        }

        Notification::make()
            ->title('Invalid pricing configuration')
            ->body(implode(' ', $errors))
            ->danger()
            ->send();

        $this->halt();
    }
}
