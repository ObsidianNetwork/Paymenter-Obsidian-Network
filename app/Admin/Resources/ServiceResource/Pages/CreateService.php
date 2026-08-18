<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Resources\ServiceResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (
            Product::query()
                ->find($data['product_id'] ?? null)
                ?->usesDynamicResources()
        ) {
            throw ValidationException::withMessages([
                'product_id' => 'Dynamic resource services cannot be created directly. Use customer checkout or an explicit capacity-aware import coordinator.',
            ]);
        }

        return $data;
    }
}
