<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Invoices\CreateInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\DeleteInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoicesRequest;
use App\Http\Requests\Api\Admin\Invoices\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Invoice\MarkInvoicePaidService;
use App\Services\Invoice\CancelInvoiceService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Invoices', weight: 4)]
class InvoiceController extends ApiController
{
    protected const INCLUDES = [
        'items',
        'user',
    ];

    /**
     * List Invoices
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetInvoicesRequest $request)
    {
        // Fetch invoices with pagination
        $invoices = QueryBuilder::for(Invoice::class)
            ->allowedFilters(['id', 'currency_code', 'user_id', 'status'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'currency_code'])
            ->simplePaginate(request('per_page', 15));

        // Return the invoices as a JSON response
        return InvoiceResource::collection($invoices);
    }

    /**
     * Create a new invoice
     */
    public function store(CreateInvoiceRequest $request)
    {
        $data = $request->validated();
        $markPaid = $data['status'] === Invoice::STATUS_PAID;
        if ($markPaid) {
            $data['status'] = Invoice::STATUS_PENDING;
        }
        $invoice = DB::transaction(function () use ($data, $markPaid) {
            $invoice = Invoice::create($data);

            return $markPaid
                ? app(MarkInvoicePaidService::class)->handle($invoice)
                : $invoice;
        }, 5);

        // Return the created invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Show a specific invoice
     */
    public function show(GetInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = QueryBuilder::for(Invoice::class)
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoice->id);

        // Return the invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Update a specific invoice
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $markPaid = ($data['status'] ?? null) === Invoice::STATUS_PAID
            && $invoice->status !== Invoice::STATUS_PAID;
        $cancel = ($data['status'] ?? null) === Invoice::STATUS_CANCELLED
            && $invoice->status !== Invoice::STATUS_CANCELLED;
        $invoice = DB::transaction(function () use ($invoice, $data, $markPaid, $cancel) {
            if ($markPaid || $cancel) {
                unset($data['status']);
            }
            if ($data !== []) {
                $invoice->update($data);
            }

            if ($markPaid) {
                return app(MarkInvoicePaidService::class)->handle($invoice);
            }
            if ($cancel) {
                return app(CancelInvoiceService::class)->handle($invoice);
            }

            return $invoice;
        }, 5);

        // Return the updated invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Delete a specific invoice
     */
    public function destroy(DeleteInvoiceRequest $request, Invoice $invoice)
    {
        app(CancelInvoiceService::class)->assertCanDelete($invoice);

        // Delete the invoice
        $invoice->delete();

        return $this->returnNoContent();
    }
}
