<?php

namespace App\Admin\Resources\ServiceCancellationResource\Pages;

use App\Admin\Resources\ServiceCancellationResource;
use App\Services\Service\ServiceCancellationRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServiceCancellation extends CreateRecord
{
    protected static string $resource = ServiceCancellationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ServiceCancellationRequestService::class)
            ->create($data);
    }
}
