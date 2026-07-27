<?php

namespace Paymenter\Extensions\Gateways\Mollie;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Services\Invoice\InvoicePaymentInitiationService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Mollie extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
    }

    private function request(
        $url,
        $method = 'get',
        $data = [],
        array $headers = []
    ) {
        $response = Http::withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Content-Type' => 'application/json',
        ], $headers))
            ->$method('https://api.mollie.com' . $url, $data);

        if (!$response->successful()) {
            throw new Exception('Mollie API error: ' . $response->json()['detail']);
        }

        return $response->json();
    }

    /**
     * Get all the configuration for the extension
     *
     * @param  array  $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    /**
     * Return a view or a url to redirect to
     *
     * @param  float  $total
     * @return string
     */
    public function pay(Invoice $invoice, $total)
    {
        return $this->payInteractive($invoice, $total);
    }

    public function supportsDurablePaymentInitiations(): bool
    {
        return true;
    }

    public function payInvoiceInitiation(
        InvoicePaymentInitiation $initiation
    ): mixed {
        return $this->payInteractive(
            $initiation->invoice,
            $initiation->amount,
            $initiation
        );
    }

    public function reconcileInvoicePaymentInitiation(
        InvoicePaymentInitiation $initiation,
        bool $cancelIfSafe = false
    ): array {
        $reference = trim(
            (string) $initiation->provider_reference
        );
        if (
            $reference === ''
            || strlen($reference) > 255
        ) {
            throw new \RuntimeException(
                'The durable Mollie payment has no valid provider identity.'
            );
        }
        $path = '/v2/payments/' . rawurlencode($reference);
        $payment = $this->request($path);
        $status = is_string($payment['status'] ?? null)
            ? strtolower(trim($payment['status']))
            : '';
        if ($cancelIfSafe && $status === 'open') {
            if (($payment['isCancelable'] ?? false) === true) {
                $this->request($path, 'delete');
                $payment = $this->request($path);
                $status = is_string(
                    $payment['status'] ?? null
                )
                    ? strtolower(trim($payment['status']))
                    : '';
            }
        } elseif (
            $cancelIfSafe
            && $status === 'authorized'
        ) {
            // Releasing an authorization is asynchronous. Never release the
            // local slot from the 202 response; re-read and wait until Mollie
            // itself reports the definitive canceled state.
            $this->request(
                $path . '/release-authorization',
                'post'
            );
            $payment = $this->request($path);
            $status = is_string($payment['status'] ?? null)
                ? strtolower(trim($payment['status']))
                : '';
        }

        $metadata = is_array($payment['metadata'] ?? null)
            ? $payment['metadata']
            : [];
        $amount = is_array($payment['amount'] ?? null)
            ? $payment['amount']
            : [];
        $providerAmount = $this->canonicalMollieAmount(
            $amount['value'] ?? null
        );
        $currency = is_string($amount['currency'] ?? null)
            ? strtoupper(trim($amount['currency']))
            : '';
        if (
            !is_string($payment['id'] ?? null)
            || !hash_equals($reference, $payment['id'])
            || (int) ($metadata['invoice_id'] ?? 0)
                !== (int) $initiation->invoice_id
            || (int) (
                $metadata[
                    'invoice_payment_initiation_id'
                ] ?? 0
            ) !== (int) $initiation->id
            || !hash_equals(
                (string) $initiation->amount,
                $providerAmount
            )
            || !hash_equals(
                (string) $initiation->currency_code,
                $currency
            )
        ) {
            return $this->mollieReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'Mollie returned payment data that conflicts with the durable invoice generation.'
            );
        }
        $evidenceStatus = match ($status) {
            'paid' => 'succeeded',
            'pending', 'authorized' => 'processing',
            'open' => 'open',
            'canceled', 'expired', 'failed' => 'failed',
            default => 'attention',
        };

        return [
            'provider_reference' => $reference,
            'provider_transaction_id' => in_array($evidenceStatus, [
                'processing',
                'succeeded',
                'failed',
            ], true)
                    ? $reference
                    : null,
            'provider_status' => $status !== ''
                ? $status
                : 'invalid',
            'evidence_status' => $evidenceStatus,
            'amount' => (string) $initiation->amount,
            'currency_code' => (string) $initiation->currency_code,
            'fee' => null,
            'message' => $evidenceStatus === 'attention'
                ? 'Mollie returned an unsupported payment status.'
                : null,
        ];
    }

    private function canonicalMollieAmount(mixed $amount): string
    {
        $amount = is_string($amount) ? trim($amount) : '';
        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D',
                $amount,
                $matches
            ) !== 1
        ) {
            throw new \RuntimeException(
                'Mollie returned an invalid payment amount.'
            );
        }

        return $matches[1]
            . '.'
            . str_pad($matches[2] ?? '', 2, '0');
    }

    private function mollieReconciliationAttention(
        InvoicePaymentInitiation $initiation,
        string $reference,
        string $providerStatus,
        string $message
    ): array {
        return [
            'provider_reference' => $reference,
            'provider_transaction_id' => null,
            'provider_status' => $providerStatus !== ''
                ? $providerStatus
                : 'invalid',
            'evidence_status' => 'attention',
            'amount' => (string) $initiation->amount,
            'currency_code' => (string) $initiation->currency_code,
            'fee' => null,
            'message' => $message,
        ];
    }

    private function payInteractive(
        Invoice $invoice,
        mixed $total,
        ?InvoicePaymentInitiation $initiation = null
    ): mixed {
        $this->assertPaymentAttemptAllowed($invoice);
        $response = $this->request(
            '/v2/payments',
            'post',
            [
                'amount' => [
                    'currency' => $invoice->currency_code,
                    'value' => $this->canonicalMollieAmount($total),
                ],
                'description' => 'Invoice #' . $invoice->id,
                'redirectUrl' => route('invoices.show', $invoice)
                        . '?checkPayment=true',
                'cancelUrl' => route('invoices.show', $invoice),
                'webhookUrl' => route(
                    'extensions.gateways.mollie.webhook',
                    $invoice
                ),
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_payment_initiation_id' => $initiation?->id,
                ],
            ],
            $initiation === null
                ? []
                : [
                    'Idempotency-Key' => (string) $initiation->idempotency_key,
                ]
        );
        if ($initiation !== null) {
            app(InvoicePaymentInitiationService::class)
                ->recordProviderReference(
                    $initiation,
                    (string) ($response['id'] ?? '')
                );
        }

        return $response['_links']['checkout']['href'];
    }

    public function webhook(Request $request)
    {
        $payment = $this->request('/v2/payments/' . $request->input('id'));
        $metadata = is_array($payment['metadata'] ?? null)
            ? $payment['metadata']
            : [];
        $invoiceId = (int) ($metadata['invoice_id'] ?? 0);
        $providerReference = (string) ($payment['id'] ?? '');
        $currency = is_array($payment['amount'] ?? null)
            ? (string) ($payment['amount']['currency'] ?? '')
            : '';
        $initiations = app(
            InvoicePaymentInitiationService::class
        );
        $claim = $initiations
            ->providerGenerationForReference(
                'Mollie',
                $providerReference
            );
        if ($claim !== null) {
            $invoiceId = (int) $claim->invoice_id;
        }
        if (
            $claim !== null
            || $initiations->hasProviderGenerationHistory(
                $invoiceId,
                'Mollie'
            )
        ) {
            $initiations->verifyProviderGenerationMetadata(
                $invoiceId,
                'Mollie',
                $providerReference,
                $metadata['invoice_payment_initiation_id']
                    ?? null,
                null,
                false
            );
        }

        if ($payment['status'] == 'paid') {
            ExtensionHelper::addPayment(
                $invoiceId,
                'Mollie',
                $payment['amount']['value'],
                transactionId: $providerReference,
                providerCurrency: $currency,
                providerResourceReference: $providerReference
            );

            return;
        }
        if (in_array($payment['status'] ?? null, [
            'canceled',
            'expired',
            'failed',
            'pending',
            'authorized',
        ], true) && $claim !== null) {
            $initiations->reconcile($claim);
        }
    }
}
