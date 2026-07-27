<?php

namespace App\Admin\Resources\GatewayResource\Pages;

use App\Admin\Resources\GatewayResource;
use App\Helpers\ExtensionHelper;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditGateway extends EditRecord
{
    protected static string $resource = GatewayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn ($record) => ExtensionHelper::call($record, 'disabled', [$record], mayFail: true)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->settings as $setting) {
            $data['settings'][$setting->key] = $setting->value;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Extension update hooks may mutate a remote gateway. A database
        // deadlock must not replay those calls automatically.
        return DB::transaction(function () use ($record, $data): Model {
            $record->update(Arr::except($data, ['settings']));

            if (!isset($data['settings'])) {
                return $record;
            }

            $config = ExtensionHelper::getConfig(
                $record->type,
                $record->extension
            );

            foreach ($config as $option) {
                $value = $data['settings'][$option['name']] ?? null;
                $record->settings()->updateOrCreate([
                    'key' => $option['name'],
                    'settingable_id' => $record->id,
                    'settingable_type' => $record->getMorphClass(),
                ], [
                    'type' => $option['database_type'] ?? 'string',
                    'value' => is_array($value)
                        ? json_encode($value)
                        : $value,
                    'encrypted' => $option['encrypted'] ?? false,
                ]);
            }

            // mutateFormDataBeforeFill() normally loads and caches this
            // relation. Hooks must see the values just written above, not the
            // settings that were present when the edit form was opened.
            $record->unsetRelation('settings');

            if (ExtensionHelper::hasFunction($record, 'updated')) {
                ExtensionHelper::call($record, 'updated', [$record]);
            }

            // Maybe the extension changed the record, so refresh it while the
            // validated settings transaction is still open.
            return $record->refresh();
        });
    }
}
