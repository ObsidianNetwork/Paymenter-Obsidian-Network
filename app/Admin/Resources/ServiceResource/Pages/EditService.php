<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use App\Services\Service\ServiceCancellationRequestService;
use App\Services\Service\ServiceJobDispatchService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordUpdate(
        Model $record,
        array $data
    ): Model {
        return app(
            ServiceBillingAnchorMutationCoordinator::class
        )->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeStatus')
                ->label('Trigger Extension Action')
                ->schema([
                    Select::make('action')
                        ->label('Action')
                        ->options([
                            'create' => 'Create server',
                            'suspend' => 'Suspend server',
                            'unsuspend' => 'Unsuspend server',
                            'terminate' => 'Terminate server',
                            'upgrade' => 'Upgrade server',
                        ])->required(),
                    Checkbox::make('sendNotification')
                        ->label('Send Notification')
                        ->default(false),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    try {
                        $fulfillment = app(DurableFulfillmentService::class);
                        $reservationBacked = $fulfillment->isReservationBacked($record);

                        switch ($data['action']) {
                            case 'create':
                                app(ServiceJobDispatchService::class)
                                    ->requestCreate(
                                        $record,
                                        (bool) $data['sendNotification']
                                    );
                                break;
                            case 'suspend':
                                $sdata = ExtensionHelper::suspendServer($record);
                                break;
                            case 'unsuspend':
                                $sdata = ExtensionHelper::unsuspendServer($record);
                                break;
                            case 'terminate':
                                if ($record->cancellation()->exists()) {
                                    throw new \RuntimeException(
                                        'This service already has a cancellation request.'
                                    );
                                }
                                app(
                                    ServiceCancellationRequestService::class
                                )->create([
                                    'service_id' => $record->id,
                                    'type' => 'immediate',
                                    'reason' => 'Immediate cancellation requested by an administrator.',
                                ]);
                                break;
                            case 'upgrade':
                                if ($reservationBacked) {
                                    Notification::make('Upgrade blocked')
                                        ->title('Use the capacity-aware service upgrade flow')
                                        ->body('Raw extension upgrades bypass stock reservation, payment, and reconciliation.')
                                        ->danger()
                                        ->send();
                                    $action->halt();

                                    return;
                                }
                                $sdata = ExtensionHelper::upgradeServer($record);
                                break;
                        }
                    } catch (Exception $e) {
                        if (config('app.debug')) {
                            throw $e;
                        }
                        report($e);
                        Notification::make('Error')
                            ->title('Error occured while triggering the action:')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                    Notification::make('Success')
                        ->title('Action triggered successfully')
                        ->body('The action has been triggered successfully')
                        ->success()
                        ->send();
                })
                ->color('primary')
                ->modalSubmitActionLabel('Trigger'),

            AuditAction::make()->auditChildren([
                'order',
                'invoices',
                'properties',
                'configs',
                'invoiceItems',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (!$this->record->cancellation()->exists()) {
            return [];
        }

        return [
            ServiceResource\Widgets\CancellationOverview::class,
        ];
    }
}
