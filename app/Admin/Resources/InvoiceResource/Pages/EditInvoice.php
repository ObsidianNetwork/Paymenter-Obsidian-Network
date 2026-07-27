<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\InvoiceResource;
use App\Classes\PDF;
use App\Models\Invoice;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\MarkInvoicePaidService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $markPaid = ($data['status'] ?? null) === Invoice::STATUS_PAID
            && $record->status !== Invoice::STATUS_PAID;
        $cancel = ($data['status'] ?? null) === Invoice::STATUS_CANCELLED
            && $record->status !== Invoice::STATUS_CANCELLED;

        return DB::transaction(function () use ($record, $data, $markPaid, $cancel) {
            if ($markPaid || $cancel) {
                unset($data['status']);
            }
            if ($data !== []) {
                $record->update($data);
            }

            if ($markPaid) {
                return app(MarkInvoicePaidService::class)->handle($record);
            }
            if ($cancel) {
                return app(CancelInvoiceService::class)->handle($record);
            }

            return $record;
        }, 5);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(
                    fn (Invoice $record): bool => app(
                        CapacityInvoicePaymentService::class
                    )->isCapacityBacked($record)
                ),
            Action::make('pdf')
                ->label('Download PDF')
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->stream();
                    }, 'invoice-' . ($invoice->number ?? $invoice->id) . '.pdf');
                }),
            AuditAction::make()
                ->auditChildren([
                    'items',
                    'transactions',
                ]),
        ];
    }
}
