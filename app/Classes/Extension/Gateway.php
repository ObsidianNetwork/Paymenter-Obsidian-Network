<?php

namespace App\Classes\Extension;

use App\Exceptions\IndeterminateBillingChargeException;
use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\User;
use App\Services\Invoice\CapacityInvoicePaymentService;
use Illuminate\Support\Facades\View;

/**
 * Class Gateway
 */
abstract class Gateway extends Extension
{
    protected function assertPaymentAttemptAllowed(Invoice $invoice): void
    {
        app(CapacityInvoicePaymentService::class)
            ->assertPaymentAttemptAllowed($invoice);
    }

    /**
     * Pay the given invoice with the given total amount.
     *
     * @param  mixed  $total
     * @return View|string
     */
    abstract public function pay(Invoice $invoice, $total);

    /**
     * Check if gateway supports billing agreements.
     */
    public function supportsBillingAgreements(): bool
    {
        return false;
    }

    /**
     * Create a billing agreement for the given user.
     *
     * @param  string  $currencyCode
     * @return View|string
     */
    public function createBillingAgreement(User $user)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Cancel the billing agreement associated with the given card.
     *
     * @return void
     */
    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Charge the given billing agreement for the given invoice and amount.
     *
     * @param  mixed  $total
     * @return bool
     */
    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Freeze any provider account identity that must remain byte-for-byte
     * stable across retries of a saved-method charge.
     */
    public function billingAttemptProviderCustomerReference(
        BillingAgreement $billingAgreement
    ): ?string {
        return null;
    }

    /**
     * Opt in only after the adapter implements immutable idempotency and
     * provider-state reconciliation in chargeBillingAttempt().
     */
    public function supportsDurableBillingAttempts(): bool
    {
        return false;
    }

    public function supportsCustomerInitiatedBillingAttempts(): bool
    {
        return false;
    }

    /**
     * Submit or reconcile one immutable durable saved-method charge.
     *
     * @return array{
     *   provider_reference: string,
     *   provider_transaction_id: string|null,
     *   provider_status: string,
     *   evidence_status: string,
     *   fee?: string|null,
     *   message?: string|null
     * }
     */
    public function chargeBillingAttempt(
        BillingChargeAttempt $attempt
    ): array {
        throw new IndeterminateBillingChargeException(
            'This gateway does not implement durable saved-method charge reconciliation.'
        );
    }

    public function supportsDurablePaymentInitiations(): bool
    {
        return false;
    }

    /**
     * The period for which the provider guarantees that replaying one
     * interactive-payment idempotency key cannot create a second charge.
     *
     * Custom durable gateways must opt in explicitly before Paymenter will
     * replay a lost or repeated provider-open request.
     */
    public function paymentInitiationIdempotencyRetryWindowSeconds(): int
    {
        return 0;
    }

    public function payInvoiceInitiation(
        InvoicePaymentInitiation $initiation
    ): mixed {
        return $this->pay(
            $initiation->invoice,
            $initiation->amount
        );
    }

    /**
     * Re-read the immutable provider object owned by an interactive invoice
     * payment. Adapters may cancel an abandoned object only when the provider
     * supports an exact, irreversible cancellation transition.
     *
     * @return array{
     *   provider_reference: string,
     *   provider_transaction_id: string|null,
     *   provider_status: string,
     *   evidence_status: 'open'|'processing'|'succeeded'|'failed'|'attention',
     *   amount: string,
     *   currency_code: string,
     *   fee?: string|null,
     *   message?: string|null
     * }
     */
    public function reconcileInvoicePaymentInitiation(
        InvoicePaymentInitiation $initiation,
        bool $cancelIfSafe = false
    ): array {
        throw new IndeterminateBillingChargeException(
            'This gateway does not implement durable interactive payment reconciliation.'
        );
    }
}
