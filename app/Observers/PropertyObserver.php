<?php

namespace App\Observers;

use App\Events\Property as PropertyEvent;
use App\Models\Property;
use App\Services\Service\CapacityServiceMutationGuard;

class PropertyObserver
{
    public function creating(Property $property): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertPropertyMutable($property);
    }

    public function updating(Property $property): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertPropertyMutable($property);
    }

    public function deleting(Property $property): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertPropertyMutable($property);
    }

    /**
     * Handle the Property "creating" event.
     */
    public function created(Property $property): void
    {
        event(new PropertyEvent\Created($property));
    }

    /**
     * Handle the Property "updating" event.
     */
    public function updated(Property $property): void
    {
        event(new PropertyEvent\Updated($property));
    }

    /**
     * Handle the Property "deleted" event.
     */
    public function deleted(Property $property): void
    {
        event(new PropertyEvent\Deleted($property));
    }
}
