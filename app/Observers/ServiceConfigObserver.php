<?php

namespace App\Observers;

use App\Models\ServiceConfig;
use App\Services\Service\CapacityServiceMutationGuard;

class ServiceConfigObserver
{
    public function creating(ServiceConfig $config): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertConfigMutable($config);
    }

    public function updating(ServiceConfig $config): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertConfigMutable($config);
    }

    public function deleting(ServiceConfig $config): void
    {
        app(CapacityServiceMutationGuard::class)
            ->assertConfigMutable($config);
    }
}
