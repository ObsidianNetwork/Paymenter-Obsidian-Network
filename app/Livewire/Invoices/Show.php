<?php

namespace App\Livewire\Invoices;

use App\Classes\PDF;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\CreditInvoicePaymentService;
use App\Services\Service\ServiceBillingAnchorMutationCoordinator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    #[Locked]
    public Invoice $invoice;

    public $checkPayment = false;

    private $pay = null;

    #[Url('pay', except: false, nullable: true)]
    public $showPayModal = false;

    public $lastChecked = null;

    public $selectedMethod = null;

    public $setAsDefault = false;

    public function mount()
    {
        if (
            Request::has('checkPayment')
            && $this->invoice->status === 'pending'
            && !$this->paymentRequiresAttention()
            && !$this->capacityPaymentDeadlineExpired()
        ) {
            $this->checkPayment = true;
        }
        if (
            !$this->paymentRequiresAttention()
            && !$this->capacityPaymentDeadlineExpired()
            && $this->invoice->transactions()
                ->where('status', InvoiceTransactionStatus::Processing)
                ->exists()
        ) {
            $this->checkPayment = true;
        }

        // Load relations
        $this->invoice->load('transactions', 'transactions.gateway', 'transactions.invoice');

        if (
            $this->showPayModal
            && (
                $this->invoice->status !== 'pending'
                || $this->paymentRequiresAttention()
                || $this->capacityPaymentDeadlineExpired()
            )
        ) {
            $this->showPayModal = false;
        }
    }

    #[Computed]
    public function gateways()
    {
        return ExtensionHelper::getCheckoutGateways($this->invoice->total, $this->invoice->currency_code, 'invoice', $this->invoice->items);
    }

    #[Computed]
    public function paymentMethods()
    {
        return ExtensionHelper::getBillingAgreementGateways(true);
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        $durableGatewayIds = collect(
            ExtensionHelper::getBillingAgreementGateways(true)
        )->pluck('id');

        return Auth::user()->billingAgreements()
            ->whereIn('gateway_id', $durableGatewayIds)
            ->with('gateway')
            ->get();
    }

    #[Computed]
    public function recurringServices()
    {
        return $this->invoice->items()
            ->where('reference_type', Service::class)
            ->whereNotNull('reference_id')
            ->whereHasMorph('reference', [Service::class], function ($query) {
                $query->whereHas('plan', function ($planQuery) {
                    $planQuery->whereNotIn('type', ['one-time', 'free']);
                });
            });
    }

    public function updatedShowPayModal($value)
    {
        if (
            $value
            && (
                $this->invoice->status !== 'pending'
                || $this->paymentRequiresAttention()
                || $this->capacityPaymentDeadlineExpired()
            )
        ) {
            $this->showPayModal = false;
        }
    }

    public function processPayment()
    {
        $this->invoice->refresh();
        if ($this->invoice->status !== 'pending') {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }
        if ($this->paymentRequiresAttention()) {
            $this->showPayModal = false;

            return $this->notify(
                __('This invoice requires manual payment review. New payment attempts are disabled.'),
                'error'
            );
        }
        if ($this->capacityPaymentDeadlineExpired()) {
            $this->showPayModal = false;

            return $this->notify(
                __('This invoice can no longer be paid because its capacity guarantee expired.'),
                'error'
            );
        }

        if (is_null($this->selectedMethod)) {
            return;
        }

        if ($this->selectedMethod === 'credit') {
            return $this->payWithCredit();
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);

            return $this->payWithMethod($gatewayId);
        }

        return $this->payWithSavedMethod($this->selectedMethod);
    }

    private function payWithMethod($methodId)
    {
        if (!in_array($methodId, array_column($this->gateways, 'id'))) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        $this->pay = ExtensionHelper::pay(Gateway::where('id', $methodId)->first(), $this->invoice);

        if (is_string($this->pay)) {
            $this->redirect($this->pay);
        }
    }

    private function payWithCredit()
    {
        $result = app(CreditInvoicePaymentService::class)
            ->pay($this->invoice);
        $this->invoice = $result['invoice'];

        if ($result['applied'] === '0.00') {
            return null;
        }
        if ($result['fully_paid']) {
            return $this->redirect(
                route('invoices.show', $this->invoice),
                true
            );
        }

        return $this->notify(
            __('Part of the invoice has been paid with credits. Please pay the remaining amount')
        );
    }

    private function payWithSavedMethod($agreementUlid)
    {
        $agreement = Auth::user()->billingAgreements()->where('ulid', $agreementUlid)->with('gateway')->first();
        if (!$agreement) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        if (!in_array(
            $agreement->gateway->id,
            array_column($this->paymentMethods, 'id')
        )) {
            return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
        }

        $success = ExtensionHelper::charge($agreement->gateway, $this->invoice, $agreement);

        if ($success === true) {
            if (
                $this->setAsDefault
                && $this->updateDefaultBillingAgreement($agreement) > 0
            ) {
                $this->notify(
                    'Default payment method has been updated for recurring services.',
                    'success'
                );
            }
            $this->notify(
                __('The saved payment method charge was submitted. Payment status will update after provider confirmation.'),
                'success'
            );

            return $this->redirect(route('invoices.show', $this->invoice) . '?checkPayment', true);
        } else {
            return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
        }
    }

    private function updateDefaultBillingAgreement(
        BillingAgreement $agreement
    ): int {
        $serviceIds = $this->recurringServices()
            ->pluck('reference_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        if ($serviceIds->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use (
            $agreement,
            $serviceIds
        ): int {
            $services = Service::query()
                ->whereKey($serviceIds)
                ->where('user_id', $this->invoice->user_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($services as $service) {
                ServiceBillingAnchorMutationCoordinator::run(
                    $service,
                    function () use ($service, $agreement): void {
                        $service->billing_agreement_id =
                            (int) $agreement->id;
                        $service->save();
                    }
                );
            }

            return $services->count();
        }, 5);
    }

    public function exitPay()
    {
        $this->pay = null;
        // Dispatch event so extensions can do their thing
        $this->dispatch('invoice.payment.cancelled', $this->invoice);
        // Refresh invoice status
        $this->redirect(route('invoices.show', $this->invoice), true);
    }

    public function checkPaymentStatus()
    {
        $this->invoice->refresh();
        if ($this->paymentRequiresAttention()) {
            $this->checkPayment = false;
            $this->lastChecked = null;
            $this->showPayModal = false;

            return $this->notify(
                __('Payment was received, but this invoice requires manual review before fulfillment.'),
                'error'
            );
        }
        if ($this->capacityPaymentDeadlineExpired()) {
            $this->checkPayment = false;
            $this->lastChecked = null;
            $this->showPayModal = false;

            return $this->notify(
                __('This invoice can no longer be paid because its capacity guarantee expired.'),
                'error'
            );
        }

        // Check for transactions that failed since lastChecked
        if ($this->lastChecked) {
            $failedSinceLastCheck = $this->invoice->transactions()
                ->where('status', InvoiceTransactionStatus::Failed)
                ->where('updated_at', '>', $this->lastChecked)
                ->exists();

            if ($failedSinceLastCheck) {
                $this->notify(__('Payment failed. Please try again or use a different payment method.'), 'error');
                $this->checkPayment = false;
                $this->lastChecked = null;

                return;
            }
        }

        // Update lastChecked to current time
        $this->lastChecked = now();

        // Check if invoice is paid
        if ($this->invoice->status === 'paid') {
            $this->notify(__('The invoice has been paid.'), 'success');
            $this->checkPayment = false;
            $this->lastChecked = null;
        }

        // Skip render if still checking
        if ($this->checkPayment) {
            return $this->skipRender();
        }
    }

    public function render()
    {
        return view('invoices.show')->layoutData([
            'title' => __('invoices.invoice', ['id' => $this->invoice->number]),
            'sidebar' => true,
        ]);
    }

    #[Computed]
    public function capacityPaymentDeadlineExpired(): bool
    {
        return app(CapacityInvoicePaymentService::class)
            ->deadlineExpired($this->invoice);
    }

    #[Computed]
    public function paymentRequiresAttention(): bool
    {
        return app(CapacityInvoicePaymentService::class)
            ->requiresAttention($this->invoice);
    }

    public function downloadPDF()
    {
        return response()->streamDownload(function () {
            echo PDF::generateInvoice($this->invoice)->stream();
        }, 'invoice-' . ($this->invoice->number ?? $this->invoice->id) . '.pdf');
    }
}
