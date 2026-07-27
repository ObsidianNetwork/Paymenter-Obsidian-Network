<?php

namespace Paymenter\Extensions\Gateways\PayPal;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaymentInitiation;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\InvoicePaymentInitiationService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;

#[ExtensionMeta(
    name: 'PayPal Gateway',
    description: 'Accept payments via PayPal.',
    version: '1.0.0',
    author: 'Paymenter',
    url: 'https://paymenter.org/docs/extensions/paypal',
    icon: 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgNTEyIDUxMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICAgIDxyZWN0IHdpZHRoPSI1MTIiIGhlaWdodD0iNTEyIiBmaWxsPSIjRjVGNkY4IiAvPgogICAgPHBhdGggZD0iTTMzNi4zOTcgMTgxLjQ4QzMzNi4zOTcgMjE1LjY2NyAzMDQuODQ3IDI1NiAyNTcuMTExIDI1NkgyMTEuMTI5TDIwOC44NzIgMjcwLjI0MkwxOTguMTQ1IDMzOC44SDE0MUwxNzUuMzc4IDExOEgyNjcuOTYxQzI5OS4xMzcgMTE4IDMyMy42NjQgMTM1LjM3NiAzMzIuNjk4IDE1OS41MjNDMzM1LjMwNCAxNjYuNTQzIDMzNi41NTkgMTczLjk5MyAzMzYuMzk3IDE4MS40OFoiIGZpbGw9IiMwMDI5OTEiIC8+CiAgICA8cGF0aCBkPSJNMzY5LjMzMSAyNDQuOTZDMzYzLjAzMSAyODMuMjM3IDMyOS44OTggMzExLjI5MyAyOTEuMTA2IDMxMS4ySDI1OS4xNzZMMjQ1Ljg4NSAzOTRIMTg5LjA0N0wxOTguMTQzIDMzOC44TDIwOC44NzYgMjcwLjI0MUwyMTEuMTI3IDI1NkgyNTcuMTA5QzMwNC43ODMgMjU2IDMzNi4zOTUgMjE1LjY2NyAzMzYuMzk1IDE4MS40NzlDMzU5Ljg1NSAxOTMuNTg3IDM3My41MzIgMjE4LjA1MyAzNjkuMzMxIDI0NC45NloiIGZpbGw9IiM2MENERkYiIC8+CiAgICA8cGF0aCBkPSJNMzM2LjM5NyAxODEuNDhDMzI2LjU1OSAxNzYuMzM0IDMxNC42MjkgMTczLjIgMzAxLjY0NSAxNzMuMkgyMjQuMTE5TDIxMS4xMjkgMjU2SDI1Ny4xMTFDMzA0Ljc4NSAyNTYgMzM2LjM5NyAyMTUuNjY3IDMzNi4zOTcgMTgxLjQ4WiIgZmlsbD0iIzAwOENGRiIgLz4KPC9zdmc+Cg=='
)]
class PayPal extends Gateway
{
    private const MAX_INTERACTIVE_ORDER_VALIDITY_SECONDS = 72 * 60 * 60;

    private const ORDER_VALIDITY_CLOCK_SKEW_SECONDS = 15 * 60;

    public function supportsBillingAgreements(): bool
    {
        return $this->config('paypal_support_billing_agreements') ?? false;
    }

    public function supportsDurableBillingAttempts(): bool
    {
        return true;
    }

    public function supportsCustomerInitiatedBillingAttempts(): bool
    {
        return true;
    }

    public function createBillingAgreement(User $user)
    {
        // Using PayPal Vaulting
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $result = $this->request('post', $url . '/v3/vault/setup-tokens', [
            'payment_source' => [
                'paypal' => [
                    'description' => 'Billing Agreement for ' . config('settings.company_name'),
                    'usage_type' => 'MERCHANT',
                    'usage_pattern' => 'RECURRING_PREPAID',
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'return_url' => route('extensions.gateways.paypal.setup-agreement'),
                        'cancel_url' => route('account.payment-methods'),
                        'brand_name' => config('settings.company_name'),
                        'shipping_preference' => 'NO_SHIPPING',
                        'vault_instruction' => 'ON_PAYER_APPROVAL',
                    ],
                ],
            ],
        ]);

        if (!isset($result->links[1]->href)) {
            throw new Exception('Failed to create billing agreement.');
        }

        return $result->links[1]->href;
    }

    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $this->request(
            'delete',
            $url
                . '/v3/vault/payment-tokens/'
                . rawurlencode(
                    $billingAgreement->external_reference
                )
        );

        return true;
    }

    public function setupAgreement(Request $request)
    {
        $request->validate([
            'approval_token_id' => 'required|string|max:255',
        ]);

        $tokenId = $request->input('approval_token_id');
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $request = $this->request('post', $url . '/v3/vault/payment-tokens', [
            'payment_source' => [
                'token' => [
                    'id' => $tokenId,
                    'type' => 'SETUP_TOKEN',
                ],
            ],
        ]);

        ExtensionHelper::makeBillingAgreement(
            Auth::user(),
            'PayPal',
            'PayPal ' . $request->payment_source->paypal->email_address,
            $request->id,
            'paypal',
        );

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
        ]);
    }

    /**
     * Dispatch or reconcile one durable, immutable saved-method charge.
     *
     * @return array{
     *     provider_reference: string,
     *     provider_transaction_id: ?string,
     *     provider_status: string,
     *     evidence_status: 'processing'|'succeeded'|'failed'|'attention',
     *     fee: ?string,
     *     message: ?string
     * }
     */
    public function chargeBillingAttempt(
        BillingChargeAttempt $attempt
    ): array {
        $context = $this->paypalBillingAttemptContext($attempt);
        $url = $this->config('test_mode')
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $providerReference = $attempt->provider_reference;

        if (
            is_string($providerReference)
            && trim($providerReference) !== ''
        ) {
            $providerReference = trim($providerReference);
            if (!$this->isPayPalIdentity($providerReference)) {
                throw new \RuntimeException(
                    'The durable PayPal charge has an invalid Order identity.'
                );
            }
            $order = $this->request(
                'get',
                $url
                    . '/v2/checkout/orders/'
                    . rawurlencode($providerReference)
            );
        } else {
            $storedCredential =
                $attempt->purpose ===
                    BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD
                    ? [
                        'payment_initiator' => 'CUSTOMER',
                        'usage' => 'SUBSEQUENT',
                    ]
                    : [
                        'payment_initiator' => 'MERCHANT',
                        'usage' => 'SUBSEQUENT',
                        'usage_pattern' => 'RECURRING_PREPAID',
                    ];
            $order = $this->request(
                'post',
                $url . '/v2/checkout/orders',
                [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'invoice_id' => (string) $context['invoice']->id,
                            'custom_id' => $context['custom_id'],
                            'amount' => [
                                'currency_code' => $context['currency'],
                                'value' => $context['provider_amount'],
                            ],
                        ],
                    ],
                    'payment_source' => [
                        'paypal' => [
                            'vault_id' => $context['vault_id'],
                            'stored_credential' => $storedCredential,
                        ],
                    ],
                ],
                [
                    'PayPal-Request-Id' => $context['idempotency_key'],
                    'Prefer' => 'return=representation',
                ]
            );
        }

        return $this->validatePayPalBillingOrder(
            $attempt,
            $order,
            $context
        );
    }

    /**
     * @return array{
     *     invoice: Invoice,
     *     amount: string,
     *     provider_amount: string,
     *     currency: string,
     *     vault_id: string,
     *     idempotency_key: string,
     *     custom_id: string
     * }
     */
    private function paypalBillingAttemptContext(
        BillingChargeAttempt $attempt
    ): array {
        if (
            (int) $attempt->id <= 0
            || (int) $attempt->invoice_id <= 0
            || (int) $attempt->billing_agreement_snapshot_id <= 0
            || (int) $attempt->gateway_snapshot_id <= 0
            || !in_array($attempt->purpose, [
                BillingChargeAttempt::PURPOSE_AUTOMATIC_RENEWAL,
                BillingChargeAttempt::PURPOSE_CUSTOMER_SAVED_METHOD,
            ], true)
            || !is_string($attempt->gateway_extension)
            || !hash_equals(
                'paypal',
                strtolower(trim($attempt->gateway_extension))
            )
        ) {
            throw new \RuntimeException(
                'The durable PayPal charge ownership snapshot is invalid.'
            );
        }
        if (
            $attempt->gateway_id !== null
            && (int) $attempt->gateway_id
                !== (int) $attempt->gateway_snapshot_id
        ) {
            throw new \RuntimeException(
                'The live PayPal gateway no longer matches the durable charge snapshot.'
            );
        }

        $invoice = Invoice::query()
            ->with('user')
            ->whereKey($attempt->invoice_id)
            ->firstOrFail();
        $vaultId = is_string($attempt->billing_agreement_reference)
            ? trim($attempt->billing_agreement_reference)
            : '';
        if ($vaultId === '' || strlen($vaultId) > 255) {
            throw new \RuntimeException(
                'The durable PayPal charge has no valid frozen vault token.'
            );
        }

        if ($attempt->billing_agreement_id !== null) {
            $agreement = BillingAgreement::withTrashed()
                ->whereKey($attempt->billing_agreement_id)
                ->first();
            if (
                $agreement === null
                || (int) $agreement->id
                    !== (int) $attempt->billing_agreement_snapshot_id
                || (int) $agreement->user_id !== (int) $invoice->user_id
                || (int) $agreement->gateway_id
                    !== (int) $attempt->gateway_snapshot_id
                || !hash_equals(
                    $vaultId,
                    (string) $agreement->external_reference
                )
            ) {
                throw new \RuntimeException(
                    'The live PayPal billing agreement no longer matches the durable charge snapshot.'
                );
            }
        }

        $currency = is_string($attempt->currency_code)
            ? strtoupper(trim($attempt->currency_code))
            : '';
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \RuntimeException(
                'The durable PayPal charge currency is invalid.'
            );
        }
        $idempotencyKey = is_string($attempt->idempotency_key)
            ? trim($attempt->idempotency_key)
            : '';
        if (
            $idempotencyKey === ''
            || strlen($idempotencyKey) > 38
            || preg_match('/^[\x21-\x7E]+$/D', $idempotencyKey) !== 1
        ) {
            throw new \RuntimeException(
                'The durable PayPal charge idempotency key is invalid.'
            );
        }
        $customId = 'paymenter-attempt:'
            . $attempt->id
            . ':'
            . $idempotencyKey;
        if (strlen($customId) > 127) {
            throw new \RuntimeException(
                'The durable PayPal charge correlation identity is too long.'
            );
        }

        $amount = $this->canonicalPayPalAmount($attempt->amount);

        return [
            'invoice' => $invoice,
            'amount' => $amount,
            'provider_amount' => $this->payPalProviderAmount(
                $amount,
                $currency
            ),
            'currency' => $currency,
            'vault_id' => $vaultId,
            'idempotency_key' => $idempotencyKey,
            'custom_id' => $customId,
        ];
    }

    private function canonicalPayPalAmount(
        mixed $amount,
        bool $allowZero = false
    ): string {
        $amount = (string) $amount;
        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d+))?$/D',
                $amount,
                $matches
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The durable PayPal charge amount is invalid.'
            );
        }
        $fraction = $matches[2] ?? '';
        if (
            strlen($fraction) > 2
            && trim(substr($fraction, 2), '0') !== ''
        ) {
            throw new \RuntimeException(
                'The durable PayPal charge amount exceeds invoice precision.'
            );
        }
        $canonical = $matches[1]
            . '.'
            . str_pad(substr($fraction, 0, 2), 2, '0');
        if (!$allowZero && $canonical === '0.00') {
            throw new \RuntimeException(
                'The durable PayPal charge amount must be positive.'
            );
        }

        return $canonical;
    }

    private function payPalProviderAmount(
        string $canonicalAmount,
        string $currency
    ): string {
        [$whole, $fraction] = explode('.', $canonicalAmount, 2);
        if (!in_array($currency, ['HUF', 'JPY', 'TWD'], true)) {
            return $canonicalAmount;
        }
        if ($fraction !== '00') {
            throw new \RuntimeException(
                'The durable PayPal charge amount contains a fraction unsupported by its currency.'
            );
        }

        return $whole;
    }

    /**
     * @return array{
     *     provider_reference: string,
     *     provider_transaction_id: ?string,
     *     provider_status: string,
     *     evidence_status: 'processing'|'succeeded'|'failed'|'attention',
     *     fee: ?string,
     *     message: ?string
     * }
     */
    private function validatePayPalBillingOrder(
        BillingChargeAttempt $attempt,
        mixed $order,
        ?array $context = null
    ): array {
        $context ??= $this->paypalBillingAttemptContext($attempt);
        if (!is_object($order)) {
            throw new \RuntimeException(
                'PayPal returned an invalid Order response.'
            );
        }

        $orderId = $order->id ?? null;
        if (
            !is_string($orderId)
            || !$this->isPayPalIdentity($orderId)
            || (
                is_string($attempt->provider_reference)
                && trim($attempt->provider_reference) !== ''
                && !hash_equals(
                    trim($attempt->provider_reference),
                    $orderId
                )
            )
        ) {
            throw new \RuntimeException(
                'PayPal returned the wrong Order identity.'
            );
        }
        $orderStatus = $order->status ?? null;
        if (
            !is_string($orderStatus)
            || trim($orderStatus) === ''
            || strlen(trim($orderStatus)) > 100
        ) {
            throw new \RuntimeException(
                'PayPal returned an Order without a valid status.'
            );
        }
        $orderStatus = strtoupper(trim($orderStatus));
        if (
            !is_string($order->intent ?? null)
            || !hash_equals('CAPTURE', strtoupper($order->intent))
        ) {
            throw new \RuntimeException(
                'PayPal returned an Order with the wrong payment intent.'
            );
        }

        $purchaseUnits = $order->purchase_units ?? null;
        if (!is_array($purchaseUnits) || count($purchaseUnits) !== 1) {
            throw new \RuntimeException(
                'PayPal returned an Order without the one durable purchase unit.'
            );
        }
        $purchase = $purchaseUnits[0];
        if (
            !is_object($purchase)
            || !hash_equals(
                (string) $context['invoice']->id,
                (string) ($purchase->invoice_id ?? '')
            )
            || !hash_equals(
                $context['custom_id'],
                (string) ($purchase->custom_id ?? '')
            )
        ) {
            throw new \RuntimeException(
                'PayPal returned an Order with the wrong durable purchase identity.'
            );
        }
        $this->assertPayPalAmount(
            $purchase->amount ?? null,
            $context['amount'],
            $context['currency'],
            'Order'
        );

        $payments = $purchase->payments ?? null;
        $captures = is_object($payments)
            ? ($payments->captures ?? [])
            : [];
        if (!is_array($captures) || count($captures) > 1) {
            throw new \RuntimeException(
                'PayPal returned an invalid capture set for the durable Order.'
            );
        }
        $capture = $captures[0] ?? null;
        $captureId = null;
        $fee = null;
        $message = null;
        if ($capture !== null) {
            if (!is_object($capture)) {
                throw new \RuntimeException(
                    'PayPal returned an invalid Capture response.'
                );
            }
            $captureId = $capture->id ?? null;
            if (
                !is_string($captureId)
                || !$this->isPayPalIdentity($captureId)
            ) {
                throw new \RuntimeException(
                    'PayPal returned an invalid Capture identity.'
                );
            }
            $this->assertPayPalAmount(
                $capture->amount ?? null,
                $context['amount'],
                $context['currency'],
                'Capture'
            );
            $providerStatus = $capture->status ?? null;
            if (
                !is_string($providerStatus)
                || trim($providerStatus) === ''
                || strlen(trim($providerStatus)) > 100
            ) {
                throw new \RuntimeException(
                    'PayPal returned a Capture without a valid status.'
                );
            }
            $providerStatus = strtoupper(trim($providerStatus));
            $feeAmount =
                $capture
                    ->seller_receivable_breakdown
                    ->paypal_fee
                    ?? null;
            if ($feeAmount !== null) {
                $fee = $this->payPalAmountValue(
                    $feeAmount,
                    $context['currency'],
                    'Capture fee',
                    true
                );
            }
            $reason = $capture->status_details->reason ?? null;
            $message = is_string($reason) && trim($reason) !== ''
                ? trim($reason)
                : null;
            $evidenceStatus = match ($providerStatus) {
                'COMPLETED' => 'succeeded',
                'PENDING' => 'processing',
                'DECLINED', 'DENIED', 'FAILED', 'VOIDED' => 'failed',
                default => 'attention',
            };
            if (
                $evidenceStatus === 'succeeded'
                && $orderStatus !== 'COMPLETED'
            ) {
                throw new \RuntimeException(
                    'PayPal returned a completed Capture on a non-completed Order.'
                );
            }
        } else {
            if ($orderStatus === 'COMPLETED') {
                throw new \RuntimeException(
                    'PayPal returned a completed Order without its Capture.'
                );
            }
            $providerStatus = $orderStatus;
            // The core only records processing or terminal evidence against
            // an immutable provider transaction identity. An Order without a
            // Capture has no such identity yet, regardless of its Order
            // status, so it must remain an operator/reconciliation state.
            $evidenceStatus = 'attention';
        }

        if ($message === null) {
            $message = match ($evidenceStatus) {
                'succeeded', 'processing' => null,
                'failed' => "PayPal returned failed payment status {$providerStatus}.",
                default => "PayPal returned unsupported or interactive payment status {$providerStatus}.",
            };
        }

        return [
            'provider_reference' => $orderId,
            'provider_transaction_id' => $captureId,
            'provider_status' => $providerStatus,
            'evidence_status' => $evidenceStatus,
            'fee' => $fee,
            'message' => $message,
        ];
    }

    private function assertPayPalAmount(
        mixed $amount,
        string $expectedAmount,
        string $expectedCurrency,
        string $label
    ): void {
        $actual = $this->payPalAmountValue(
            $amount,
            $expectedCurrency,
            $label
        );
        if (!hash_equals($expectedAmount, $actual)) {
            throw new \RuntimeException(
                "PayPal {$label} amount does not match the durable charge."
            );
        }
    }

    private function payPalAmountValue(
        mixed $amount,
        string $expectedCurrency,
        string $label,
        bool $allowZero = false
    ): string {
        if (
            !is_object($amount)
            || !is_string($amount->currency_code ?? null)
            || !hash_equals(
                $expectedCurrency,
                strtoupper($amount->currency_code)
            )
        ) {
            throw new \RuntimeException(
                "PayPal {$label} currency does not match the durable charge."
            );
        }

        try {
            return $this->canonicalPayPalAmount(
                $amount->value ?? null,
                $allowZero
            );
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                "PayPal {$label} amount is invalid.",
                previous: $exception
            );
        }
    }

    private function isPayPalIdentity(string $identity): bool
    {
        return strlen($identity) <= 255
            && preg_match('/^[A-Za-z0-9_-]+$/D', $identity) === 1;
    }

    private function payPalBillingAttemptFromOrder(
        mixed $order
    ): ?BillingChargeAttempt {
        $purchaseUnits = is_object($order)
            ? ($order->purchase_units ?? null)
            : null;
        $customId = is_array($purchaseUnits)
            && count($purchaseUnits) === 1
            && is_object($purchaseUnits[0])
                ? ($purchaseUnits[0]->custom_id ?? null)
                : null;
        if (
            !is_string($customId)
            || !str_starts_with($customId, 'paymenter-attempt:')
        ) {
            return null;
        }
        if (
            preg_match(
                '/^paymenter-attempt:([1-9]\d*):(.+)$/D',
                $customId,
                $matches
            ) !== 1
            || strlen($matches[1]) > strlen((string) PHP_INT_MAX)
            || (
                strlen($matches[1]) === strlen((string) PHP_INT_MAX)
                && strcmp($matches[1], (string) PHP_INT_MAX) > 0
            )
        ) {
            throw new \RuntimeException(
                'PayPal returned invalid durable charge correlation metadata.'
            );
        }

        $attempt = BillingChargeAttempt::query()
            ->whereKey((int) $matches[1])
            ->first();
        if (
            $attempt === null
            || !hash_equals(
                (string) $attempt->idempotency_key,
                $matches[2]
            )
        ) {
            throw new \RuntimeException(
                'PayPal returned unknown durable charge correlation metadata.'
            );
        }

        return $attempt;
    }

    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement)
    {
        $this->assertPaymentAttemptAllowed($invoice);
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $result = $this->request('post', $url . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => [
                        'currency_code' => $invoice->currency_code,
                        'value' => $total,
                    ],
                ],
            ],
            'payment_source' => [
                'paypal' => [
                    'vault_id' => $billingAgreement->external_reference,
                ],
            ],
        ]);

        return true;
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.paypal', __DIR__ . '/resources/views');

        Event::listen(Updated::class, function ($event) {
            if ($event->service->properties->where('key', 'has_paypal_subscription')->first()?->value !== '1') {
                return;
            }
            if ($event->service->isDirty('price')) {
                try {
                    $this->updateSubscription($event->service);
                } catch (Exception $e) {
                }
            }
            if ($event->service->isDirty('status') && $event->service->status === Service::STATUS_CANCELLED) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if ($service->properties->where('key', 'has_paypal_subscription')->first()?->value !== '1') {
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                // Log the error or handle it as needed
            }
        });
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'client_id',
                'label' => 'Client ID',
                'type' => 'text',
                'description' => 'Find your API keys at https://developer.paypal.com/developer/applications',
                'required' => true,
            ],
            [
                'name' => 'client_secret',
                'label' => 'Client Secret',
                'type' => 'text',
                'description' => 'Find your API keys at https://developer.paypal.com/developer/applications',
                'required' => true,
            ],
            [
                'name' => 'webhook_id',
                'label' => 'Webhook ID',
                'type' => 'text',
                'description' => 'Find your webhook ID at https://developer.paypal.com/developer/webhooks',
                'required' => true,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'description' => 'Enable test mode',
                'required' => false,
            ],
            [
                'name' => 'paypal_support_billing_agreements',
                'label' => 'Supports Billing Agreements',
                'type' => 'checkbox',
                'description' => new HtmlString('Enable this option if your PayPal account supports billing agreements. <a href="https://paymenter.org/docs/extensions/paypal#billing-agreements" target="_blank" rel="noopener">Learn more</a>'),
                'required' => false,
            ],
        ];
    }

    private function generateAccessToken()
    {
        return once(function () {
            $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

            $result = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->config('client_id') . ':' . $this->config('client_secret')),
            ])->asForm()->post($url . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

            if ($result->failed()) {
                throw new Exception('Failed to generate access token: ' . $result->body());
            }

            return $result->json()['access_token'];
        });
    }

    public function request(
        $method,
        $url,
        $data = [],
        array $headers = []
    ) {
        $requestId = $headers['PayPal-Request-Id']
            ?? bin2hex(random_bytes(16));
        unset($headers['Authorization'], $headers['PayPal-Request-Id']);

        return Http::withHeaders(array_merge($headers, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->generateAccessToken(),
            'PayPal-Request-Id' => $requestId,
        ]))
            ->$method($url, $data)
            ->throw()
            ->object();
    }

    public function pay($invoice, $total)
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
            || !$this->isPayPalIdentity($reference)
        ) {
            throw new \RuntimeException(
                'The durable PayPal payment has no valid Order identity.'
            );
        }

        $url = $this->config('test_mode')
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $order = $this->request(
            'get',
            $url
                . '/v2/checkout/orders/'
                . rawurlencode($reference)
        );

        // PayPal Orders v2 has no cancellation operation. Even when an
        // initiation is abandoned, CREATED/APPROVED/PAYER_ACTION_REQUIRED
        // remain charge-capable and retain the local active slot. Only a
        // provider-reported VOIDED Order proves that no later capture can
        // settle this generation.
        return $this->validatePayPalInteractiveOrder(
            $initiation,
            $order
        );
    }

    private function payInteractive(
        Invoice $invoice,
        mixed $total,
        ?InvoicePaymentInitiation $initiation = null
    ): mixed {
        $this->assertPaymentAttemptAllowed($invoice);
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $currency = strtoupper(
            trim((string) $invoice->currency_code)
        );
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \RuntimeException(
                'The PayPal invoice currency is invalid.'
            );
        }
        $total = $this->payPalProviderAmount(
            $this->canonicalPayPalAmount($total),
            $currency
        );

        $order = $this->request(
            'post',
            $url . '/v2/checkout/orders',
            [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'invoice_id' => $invoice->id,
                        'custom_id' => $initiation === null
                            ? null
                            : 'paymenter-initiation:'
                                . $initiation->id
                        . ':'
                                . $initiation->idempotency_key,
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => $total,
                        ],
                    ],
                ],
                'application_context' => [
                    'return_url' => route('invoices.show', $invoice),
                    'cancel_url' => route('invoices.show', $invoice),
                    // Disable shipping information
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ],
            $initiation === null
                ? []
                : [
                    'PayPal-Request-Id' => (string) $initiation->idempotency_key,
                ]
        );
        if ($initiation !== null) {
            app(InvoicePaymentInitiationService::class)
                ->recordProviderReference(
                    $initiation,
                    (string) ($order->id ?? '')
                );
        }

        return view('gateways.paypal::pay', ['invoice' => $invoice, 'total' => $total, 'order' => $order ?? null, 'clientId' => $this->config('client_id')]);
    }

    /**
     * @return array{
     *   provider_reference: string,
     *   provider_transaction_id: string|null,
     *   provider_status: string,
     *   evidence_status: 'open'|'processing'|'succeeded'|'failed'|'attention',
     *   amount: string,
     *   currency_code: string,
     *   fee: string|null,
     *   message: string|null,
     *   attention_reconcilable?: bool
     * }
     */
    private function validatePayPalInteractiveOrder(
        InvoicePaymentInitiation $initiation,
        mixed $order
    ): array {
        $reference = (string) $initiation->provider_reference;
        $status = is_object($order)
            && is_string($order->status ?? null)
                ? strtoupper(trim($order->status))
                : 'INVALID';
        if (
            !is_object($order)
            || !is_string($order->id ?? null)
            || !hash_equals($reference, $order->id)
            || !is_string($order->intent ?? null)
            || !hash_equals(
                'CAPTURE',
                strtoupper(trim($order->intent))
            )
        ) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'PayPal returned an Order identity or intent that conflicts with the durable invoice generation.'
            );
        }

        $purchaseUnits = $order->purchase_units ?? null;
        if (
            !is_array($purchaseUnits)
            || count($purchaseUnits) !== 1
            || !is_object($purchaseUnits[0])
        ) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'PayPal returned an invalid purchase-unit set for the durable invoice generation.'
            );
        }
        $purchase = $purchaseUnits[0];
        [$metadataId, $metadataKey] =
            $this->payPalInteractiveMetadata(
                $purchase->custom_id ?? null
            );
        if (
            !hash_equals(
                (string) $initiation->invoice_id,
                (string) ($purchase->invoice_id ?? '')
            )
            || $metadataId !== (int) $initiation->id
            || !is_string($metadataKey)
            || !hash_equals(
                (string) $initiation->idempotency_key,
                $metadataKey
            )
        ) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'PayPal returned Order metadata that conflicts with the durable invoice generation.'
            );
        }

        try {
            $this->assertPayPalAmount(
                $purchase->amount ?? null,
                (string) $initiation->amount,
                (string) $initiation->currency_code,
                'interactive Order'
            );
        } catch (\RuntimeException $exception) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                $exception->getMessage()
            );
        }

        $payments = $purchase->payments ?? null;
        $captures = is_object($payments)
            ? ($payments->captures ?? [])
            : [];
        if (!is_array($captures) || count($captures) > 1) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'PayPal returned an invalid Capture set for the durable invoice generation.',
                $status === 'VOIDED'
            );
        }
        $capture = $captures[0] ?? null;
        if ($capture === null) {
            $maximumValidityElapsed =
                $this->payPalMaximumOrderValidityElapsed(
                    $order->create_time ?? null
                );
            $financialEvidence =
                $this->payPalPaymentsContainFinancialEvidence(
                    $payments
                );
            $openStatus = in_array($status, [
                'CREATED',
                'SAVED',
                'APPROVED',
                'PENDING_APPROVAL',
                'PAYER_ACTION_REQUIRED',
            ], true);
            $expiredOpenOrder =
                $openStatus
                && $maximumValidityElapsed === true
                && !$financialEvidence;
            $unsafeOpenOrder =
                $openStatus
                && (
                    $maximumValidityElapsed === null
                    || $financialEvidence
                );
            $unsafeVoidedOrder =
                $status === 'VOIDED'
                && $financialEvidence;
            $evidenceStatus = match ($status) {
                'VOIDED' => $unsafeVoidedOrder
                    ? 'attention'
                    : 'failed',
                'CREATED',
                'SAVED',
                'APPROVED',
                'PENDING_APPROVAL',
                'PAYER_ACTION_REQUIRED' => $expiredOpenOrder
                        ? 'failed'
                        : ($unsafeOpenOrder ? 'attention' : 'open'),
                default => 'attention',
            };

            return [
                'provider_reference' => $reference,
                'provider_transaction_id' => null,
                'provider_status' => $status,
                'evidence_status' => $evidenceStatus,
                'amount' => (string) $initiation->amount,
                'currency_code' => (string) $initiation->currency_code,
                'fee' => null,
                'message' => match (true) {
                    $expiredOpenOrder => 'PayPal confirmed that the capture-free Order is older than its maximum settlement validity.',
                    $openStatus
                        && $maximumValidityElapsed === null => 'PayPal returned a capture-free Order with a missing or malformed creation timestamp.',
                    $openStatus && $financialEvidence => 'PayPal returned financial evidence without a verifiable Capture.',
                    $unsafeVoidedOrder => 'PayPal returned a voided Order with financial or malformed payment evidence.',
                    $evidenceStatus === 'attention' => 'PayPal returned an unsupported Order state without a Capture.',
                    default => null,
                },
                'attention_reconcilable' => $unsafeOpenOrder
                    || $unsafeVoidedOrder,
            ];
        }
        if (
            !is_object($capture)
            || !is_string($capture->id ?? null)
            || !$this->isPayPalIdentity($capture->id)
        ) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'PayPal returned an invalid Capture identity for the durable invoice generation.',
                $status === 'VOIDED'
            );
        }
        try {
            $this->assertPayPalAmount(
                $capture->amount ?? null,
                (string) $initiation->amount,
                (string) $initiation->currency_code,
                'interactive Capture'
            );
        } catch (\RuntimeException $exception) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                $exception->getMessage(),
                $status === 'VOIDED'
            );
        }

        $captureStatus = is_string($capture->status ?? null)
            ? strtoupper(trim($capture->status))
            : 'INVALID';
        $voidedWithCapture = $status === 'VOIDED';
        $evidenceStatus = $voidedWithCapture
            ? 'attention'
            : match ($captureStatus) {
                'COMPLETED' => $status === 'COMPLETED'
                    ? 'succeeded'
                    : 'attention',
                'PENDING' => 'processing',
                default => 'attention',
            };
        $feeAmount =
            $capture
                ->seller_receivable_breakdown
                ->paypal_fee
                ?? null;
        try {
            $fee = $feeAmount === null
                ? null
                : $this->payPalAmountValue(
                    $feeAmount,
                    (string) $initiation->currency_code,
                    'interactive Capture fee',
                    true
                );
        } catch (\RuntimeException $exception) {
            return $this->payPalInteractiveReconciliationAttention(
                $initiation,
                $reference,
                $captureStatus,
                $exception->getMessage(),
                $voidedWithCapture
            );
        }

        return [
            'provider_reference' => $reference,
            'provider_transaction_id' => (string) $capture->id,
            'provider_status' => $captureStatus,
            'evidence_status' => $evidenceStatus,
            'amount' => (string) $initiation->amount,
            'currency_code' => (string) $initiation->currency_code,
            'fee' => $fee,
            'message' => $evidenceStatus === 'attention'
                ? 'PayPal returned a Capture state that cannot safely settle or release this durable generation.'
                : null,
            'attention_reconcilable' => $voidedWithCapture,
        ];
    }

    private function payPalMaximumOrderValidityElapsed(
        mixed $createTime
    ): ?bool {
        $createdAt = $this->strictPayPalTimestamp($createTime);
        if ($createdAt === null) {
            return null;
        }

        $expiresAt =
            $createdAt
            + self::MAX_INTERACTIVE_ORDER_VALIDITY_SECONDS
            + self::ORDER_VALIDITY_CLOCK_SKEW_SECONDS;

        return now()->getTimestamp() > $expiresAt;
    }

    private function payPalPaymentsContainFinancialEvidence(
        mixed $payments
    ): bool {
        if ($payments === null) {
            return false;
        }
        if (!is_object($payments)) {
            return true;
        }
        foreach (get_object_vars($payments) as $evidence) {
            if (is_array($evidence)) {
                if ($evidence !== []) {
                    return true;
                }

                continue;
            }
            if ($evidence !== null) {
                return true;
            }
        }

        return false;
    }

    private function strictPayPalTimestamp(mixed $value): ?int
    {
        if (
            !is_string($value)
            || strlen($value) < 20
            || strlen($value) > 64
            || preg_match(
                '/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12]\d|3[01])[Tt](?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d):(?<second>[0-5]\d|60)(?:\.(?<fraction>\d+))?(?<zone>[Zz]|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D',
                $value,
                $matches
            ) !== 1
            || !checkdate(
                (int) $matches['month'],
                (int) $matches['day'],
                (int) $matches['year']
            )
            || (
                $matches['second'] === '60'
                && $matches['minute'] !== '59'
            )
        ) {
            return null;
        }
        $leapSecond = $matches['second'] === '60';
        $zone = in_array($matches['zone'], ['Z', 'z'], true)
            ? '+00:00'
            : $matches['zone'];
        $normalized = sprintf(
            '%s-%s-%sT%s:%s:%s%s',
            $matches['year'],
            $matches['month'],
            $matches['day'],
            $matches['hour'],
            $matches['minute'],
            $leapSecond ? '59' : $matches['second'],
            $zone
        );
        $createdAt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $normalized
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $createdAt === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            return null;
        }

        return $createdAt->getTimestamp() + ($leapSecond ? 1 : 0);
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function payPalInteractiveMetadata(
        mixed $customId
    ): array {
        if (
            !is_string($customId)
            || preg_match(
                '/^paymenter-initiation:([1-9]\d*):(.+)$/D',
                $customId,
                $matches
            ) !== 1
            || strlen($matches[1]) > strlen((string) PHP_INT_MAX)
            || (
                strlen($matches[1]) ===
                    strlen((string) PHP_INT_MAX)
                && strcmp(
                    $matches[1],
                    (string) PHP_INT_MAX
                ) > 0
            )
        ) {
            return [null, null];
        }

        return [(int) $matches[1], $matches[2]];
    }

    private function payPalInteractiveInitiationForOrder(
        object $order
    ): ?InvoicePaymentInitiation {
        $orderId = is_string($order->id ?? null)
            ? trim($order->id)
            : '';
        if ($orderId !== '') {
            $matches = InvoicePaymentInitiation::query()
                ->whereRaw(
                    'LOWER(gateway_extension) = ?',
                    ['paypal']
                )
                ->where('provider_reference', $orderId)
                ->limit(2)
                ->get();
            if ($matches->count() > 1) {
                throw new \RuntimeException(
                    'PayPal Order identity belongs to more than one durable initiation.'
                );
            }
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        $purchaseUnits = $order->purchase_units ?? null;
        $customId = is_array($purchaseUnits)
            && count($purchaseUnits) === 1
            && is_object($purchaseUnits[0])
                ? ($purchaseUnits[0]->custom_id ?? null)
                : null;
        [$initiationId] =
            $this->payPalInteractiveMetadata($customId);
        if ($initiationId === null) {
            return null;
        }

        return InvoicePaymentInitiation::query()
            ->whereKey($initiationId)
            ->whereRaw(
                'LOWER(gateway_extension) = ?',
                ['paypal']
            )
            ->first();
    }

    /**
     * @return array{
     *   provider_reference: string,
     *   provider_transaction_id: null,
     *   provider_status: string,
     *   evidence_status: 'attention',
     *   amount: string,
     *   currency_code: string,
     *   fee: null,
     *   message: string,
     *   attention_reconcilable: bool
     * }
     */
    private function payPalInteractiveReconciliationAttention(
        InvoicePaymentInitiation $initiation,
        string $reference,
        string $status,
        string $message,
        bool $reconcilable = false
    ): array {
        return [
            'provider_reference' => $reference,
            'provider_transaction_id' => null,
            'provider_status' => $status !== ''
                ? $status
                : 'INVALID',
            'evidence_status' => 'attention',
            'amount' => (string) $initiation->amount,
            'currency_code' => (string) $initiation->currency_code,
            'fee' => null,
            'message' => $message,
            'attention_reconcilable' => $reconcilable,
        ];
    }

    public function capture(Request $request)
    {
        if (!$request->has('orderID')) {
            abort(400);
        }
        $orderID = $request->input('orderID');
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        // First check if the order is already captured
        $order = $this->request('get', $url . '/v2/checkout/orders/' . $orderID);
        $durableInitiation =
            $this->payPalInteractiveInitiationForOrder($order);
        $invoice = $durableInitiation?->invoice
            ?? Invoice::query()->findOrFail(
                $order->purchase_units[0]->invoice_id
            );
        $initiations = app(
            InvoicePaymentInitiationService::class
        );
        if ($order->status === 'COMPLETED') {
            if ($durableInitiation !== null) {
                $initiations->assertProviderReferenceMatches(
                    $invoice,
                    'PayPal',
                    $orderID
                );
            }
            $this->recordInteractivePayPalCapture(
                $invoice,
                $order
            );

            return $order;
        }
        $initiation = $initiations
            ->assertProviderCompletionAllowed(
                $invoice,
                'PayPal',
                $orderID
            );
        $purchaseUnits = $order->purchase_units ?? null;
        $purchase = is_array($purchaseUnits)
            && count($purchaseUnits) === 1
            && is_object($purchaseUnits[0])
                ? $purchaseUnits[0]
                : null;
        [$metadataId, $metadataKey] =
            $this->payPalInteractiveMetadata(
                $purchase?->custom_id
            );
        if (
            !$initiations->verifyProviderGenerationMetadata(
                (int) $invoice->id,
                'PayPal',
                (string) $orderID,
                $metadataId,
                $metadataKey
            )
        ) {
            throw new \RuntimeException(
                'PayPal Order metadata does not match the invoice-owned durable initiation.'
            );
        }

        $response = $this->request(
            'post',
            $url . '/v2/checkout/orders/' . $orderID . '/capture',
            ['intent' => 'CAPTURE'],
            [
                'PayPal-Request-Id' => substr(
                    hash(
                        'sha256',
                        'capture:'
                            . $initiation->idempotency_key
                    ),
                    0,
                    36
                ),
            ]
        );

        $this->recordInteractivePayPalCapture(
            $invoice,
            $response
        );

        return $response;
    }

    private function recordInteractivePayPalCapture(
        Invoice $invoice,
        object $order
    ): void {
        $orderId = is_string($order->id ?? null)
            ? trim($order->id)
            : '';
        $purchaseUnits = $order->purchase_units ?? null;
        $purchase = is_array($purchaseUnits)
            && count($purchaseUnits) === 1
            && is_object($purchaseUnits[0])
                ? $purchaseUnits[0]
                : null;
        $captures = $purchase?->payments->captures ?? null;
        $capture = is_array($captures)
            && count($captures) === 1
                ? $captures[0]
                : null;
        $amount = $capture?->amount->value ?? null;
        $currency = $capture?->amount->currency_code ?? null;
        $transactionId = $capture?->id ?? null;
        if (
            !$this->isPayPalIdentity($orderId)
            || !is_object($purchase)
            || !is_object($capture)
            || !is_string($amount)
            || !is_string($currency)
            || preg_match(
                '/^[A-Z]{3}$/D',
                strtoupper(trim($currency))
            ) !== 1
            || !is_string($transactionId)
            || trim($transactionId) === ''
        ) {
            throw new \RuntimeException(
                'PayPal completed the Order without one valid capture.'
            );
        }

        $durableInitiation =
            $this->payPalInteractiveInitiationForOrder($order);
        if ($durableInitiation !== null) {
            $invoice = $durableInitiation->invoice;
            [$metadataId, $metadataKey] =
                $this->payPalInteractiveMetadata(
                    $purchase->custom_id ?? null
                );
            // A mismatch is quarantined, but the real provider Capture is
            // still passed to addPayment() below so financial evidence is
            // never discarded merely because local correlation drifted.
            app(InvoicePaymentInitiationService::class)
                ->verifyProviderGenerationMetadata(
                    (int) $invoice->id,
                    'PayPal',
                    $orderId,
                    $metadataId,
                    $metadataKey
                );
        }

        ExtensionHelper::addPayment(
            $invoice->id,
            'PayPal',
            $amount,
            $capture->seller_receivable_breakdown
                ?->paypal_fee?->value,
            $transactionId,
            providerCurrency: strtoupper(trim($currency)),
            providerResourceReference: $orderId
        );
    }

    public function webhook(Request $request)
    {
        $body = $request->getContent();
        $verification = $this->request('post', ($this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com') . '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $this->config('webhook_id'),
            'webhook_event' => json_decode($body, true),
        ]);

        if (($verification->verification_status ?? null) !== 'SUCCESS') {
            return response()->json(['status' => 'error'], 400);
        }

        $body = $request->json()->all();

        // Handle the subscription event
        if ($body['event_type'] === 'BILLING.SUBSCRIPTION.CREATED' && isset($body['resource']['custom_id'])) {
            // Its activated so we can now add the subscription to the user (custom is the order id)
            Invoice::findOrFail($body['resource']['custom_id'])->items()->where('reference_type', Service::class)->each(function (InvoiceItem $item) use ($body) {
                $service = $item->reference;
                $service->subscription_id = $body['resource']['id'];
                $service->save();
                $service->properties()->updateOrCreate([
                    'key' => 'has_paypal_subscription',
                    'name' => 'Has PayPal Subscription',
                ], [
                    'value' => true,
                ]);
            });
        } elseif ($body['event_type'] === 'PAYMENT.SALE.COMPLETED' && isset($body['resource']['billing_agreement_id'])) {
            Service::where('subscription_id', $body['resource']['billing_agreement_id'])->each(function (Service $service) use ($body) {
                // Add payment to the latest invoice
                $latestInvoice = $service->invoices()->latest()->first();
                ExtensionHelper::addPayment($latestInvoice->id, 'PayPal', $body['resource']['amount']['total'], $body['resource']['transaction_fee']['value'], $body['resource']['id']);
            });
        } elseif ($body['event_type'] === 'VAULT.PAYMENT-TOKEN.DELETED') {
            // Find the billing agreement with this external reference
            $billingAgreement = BillingAgreement::where('external_reference', $body['resource']['id'])->first();
            if ($billingAgreement) {
                app(BillingChargeAttemptService::class)
                    ->providerPaymentMethodDeleted($billingAgreement);
            }
        } elseif (in_array($body['event_type'], [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.CAPTURE.PENDING',
            'PAYMENT.CAPTURE.DECLINED',
            'PAYMENT.CAPTURE.DENIED',
        ], true)) {
            $this->handlePayPalCaptureWebhook($body);
        }

        return response()->json(['status' => 'success']);
    }

    private function handlePayPalCaptureWebhook(array $body): void
    {
        $eventType = $body['event_type'] ?? null;
        $resource = $body['resource'] ?? null;
        if (!is_array($resource)) {
            throw new \RuntimeException(
                'PayPal sent an invalid Capture webhook resource.'
            );
        }
        $orderId =
            $resource['supplementary_data']['related_ids']['order_id']
                ?? null;
        $captureId = $resource['id'] ?? null;
        if (
            !is_string($eventType)
            || !is_string($orderId)
            || !$this->isPayPalIdentity($orderId)
            || !is_string($captureId)
            || !$this->isPayPalIdentity($captureId)
        ) {
            throw new \RuntimeException(
                'PayPal sent incomplete Capture webhook identities.'
            );
        }

        $url = $this->config('test_mode')
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $order = $this->request(
            'get',
            $url
                . '/v2/checkout/orders/'
                . rawurlencode($orderId)
        );
        if (
            !is_object($order)
            || !is_string($order->id ?? null)
            || !hash_equals($orderId, $order->id)
        ) {
            throw new \RuntimeException(
                'PayPal Capture webhook resolved to the wrong Order.'
            );
        }

        $purchaseUnits = $order->purchase_units ?? null;
        if (!is_array($purchaseUnits) || count($purchaseUnits) !== 1) {
            throw new \RuntimeException(
                'PayPal Capture webhook Order has an invalid purchase-unit set.'
            );
        }
        $purchase = $purchaseUnits[0];
        $captures = is_object($purchase)
            ? ($purchase->payments->captures ?? null)
            : null;
        if (!is_array($captures)) {
            throw new \RuntimeException(
                'PayPal Capture webhook Order has no verifiable Capture set.'
            );
        }
        $matchingCaptures = array_values(array_filter(
            $captures,
            fn ($capture): bool => is_object($capture)
                && is_string($capture->id ?? null)
                && hash_equals($captureId, $capture->id)
        ));
        if (count($matchingCaptures) !== 1) {
            throw new \RuntimeException(
                'PayPal Capture webhook does not identify exactly one Order Capture.'
            );
        }
        $capture = $matchingCaptures[0];
        $expectedEventEvidenceStatus = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => 'succeeded',
            'PAYMENT.CAPTURE.PENDING' => 'processing',
            'PAYMENT.CAPTURE.DECLINED',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            default => 'attention',
        };
        $eventCaptureStatus = is_string($resource['status'] ?? null)
            ? strtoupper(trim($resource['status']))
            : '';
        $eventEvidenceStatus = match ($eventCaptureStatus) {
            'COMPLETED' => 'succeeded',
            'PENDING' => 'processing',
            'DECLINED', 'DENIED', 'FAILED', 'VOIDED' => 'failed',
            default => 'attention',
        };
        if ($eventEvidenceStatus !== $expectedEventEvidenceStatus) {
            throw new \RuntimeException(
                'PayPal Capture webhook type and resource status disagree.'
            );
        }
        $eventAmount = $resource['amount'] ?? null;
        $eventCurrency = is_array($eventAmount)
            && is_string($eventAmount['currency_code'] ?? null)
                ? strtoupper(trim($eventAmount['currency_code']))
                : '';
        if (preg_match('/^[A-Z]{3}$/D', $eventCurrency) !== 1) {
            throw new \RuntimeException(
                'PayPal Capture webhook has an invalid signed currency.'
            );
        }
        $eventAmountValue = $this->canonicalPayPalAmount(
            is_array($eventAmount)
                ? ($eventAmount['value'] ?? null)
                : null
        );
        $currentCaptureAmount = $this->payPalAmountValue(
            $capture->amount ?? null,
            $eventCurrency,
            'Capture webhook'
        );
        if (!hash_equals($eventAmountValue, $currentCaptureAmount)) {
            throw new \RuntimeException(
                'PayPal Capture webhook amount does not match its Order Capture.'
            );
        }

        $attempt = $this->payPalBillingAttemptFromOrder($order);
        if ($attempt !== null) {
            $result = $this->validatePayPalBillingOrder(
                $attempt,
                $order
            );
            if (
                !is_string($result['provider_transaction_id'])
                || !hash_equals(
                    $captureId,
                    $result['provider_transaction_id']
                )
            ) {
                throw new \RuntimeException(
                    'PayPal Capture webhook transaction does not match the durable charge.'
                );
            }
            $invoiceId = (int) $attempt->invoice_id;
            $amount = $attempt->amount;
            $fee = $result['fee'];
            $evidenceStatus = $result['evidence_status'];
            $providerCurrency =
                (string) $attempt->currency_code;
        } else {
            $interactiveInitiation =
                $this->payPalInteractiveInitiationForOrder(
                    $order
                );
            $invoiceId = $interactiveInitiation !== null
                ? (int) $interactiveInitiation->invoice_id
                : (
                    is_object($purchase)
                        ? (int) ($purchase->invoice_id ?? 0)
                        : 0
                );
            $purchaseCurrency = is_object($purchase)
                && is_object($purchase->amount ?? null)
                && is_string(
                    $purchase->amount->currency_code ?? null
                )
                    ? strtoupper(
                        trim($purchase->amount->currency_code)
                    )
                    : '';
            if (
                preg_match('/^[A-Z]{3}$/D', $purchaseCurrency) !== 1
            ) {
                throw new \RuntimeException(
                    'PayPal Capture webhook Order has an invalid currency.'
                );
            }
            $amount = $this->payPalAmountValue(
                $capture->amount ?? null,
                $purchaseCurrency,
                'Capture'
            );
            $orderAmount = $this->payPalAmountValue(
                $purchase->amount ?? null,
                $purchaseCurrency,
                'Order'
            );
            if (!hash_equals($orderAmount, $amount)) {
                throw new \RuntimeException(
                    'PayPal Capture webhook amount does not match its Order.'
                );
            }
            $feeAmount =
                $capture
                    ->seller_receivable_breakdown
                    ->paypal_fee
                    ?? null;
            $fee = $feeAmount === null
                ? null
                : $this->payPalAmountValue(
                    $feeAmount,
                    $purchaseCurrency,
                    'Capture fee',
                    true
                );
            $captureStatus = is_string($capture->status ?? null)
                ? strtoupper(trim($capture->status))
                : '';
            $evidenceStatus = match ($captureStatus) {
                'COMPLETED' => 'succeeded',
                'PENDING' => 'processing',
                'DECLINED', 'DENIED', 'FAILED', 'VOIDED' => 'failed',
                default => 'attention',
            };
            $providerCurrency = $purchaseCurrency;
            if ($interactiveInitiation !== null) {
                [$metadataId, $metadataKey] =
                    $this->payPalInteractiveMetadata(
                        $purchase->custom_id ?? null
                    );
                // Preserve the signed Capture below even if the generation
                // metadata is wrong; verification places the invoice in
                // attention so it cannot settle automatically.
                app(InvoicePaymentInitiationService::class)
                    ->verifyProviderGenerationMetadata(
                        $invoiceId,
                        'PayPal',
                        $orderId,
                        $metadataId,
                        $metadataKey
                    );
            }
        }

        if (
            $invoiceId <= 0
            || $amount === null
            || $evidenceStatus === 'attention'
        ) {
            throw new \RuntimeException(
                'PayPal Capture webhook has no recordable provider state.'
            );
        }

        // Webhooks may arrive out of order. The verified event must be
        // internally consistent, but local evidence follows the current
        // Capture returned by PayPal rather than regressing to an older event
        // snapshot.
        match ($evidenceStatus) {
            'succeeded' => ExtensionHelper::addPayment(
                $invoiceId,
                'PayPal',
                $amount,
                $fee,
                $captureId,
                billingChargeAttemptId: $attempt?->id,
                providerCurrency: $providerCurrency,
                providerResourceReference: $orderId
            ),
            'processing' => ExtensionHelper::addProcessingPayment(
                $invoiceId,
                'PayPal',
                $amount,
                $fee,
                $captureId,
                billingChargeAttemptId: $attempt?->id,
                providerCurrency: $providerCurrency,
                providerResourceReference: $orderId
            ),
            'failed' => ExtensionHelper::addFailedPayment(
                $invoiceId,
                'PayPal',
                $amount,
                $fee,
                $captureId,
                billingChargeAttemptId: $attempt?->id,
                providerCurrency: $providerCurrency,
                providerResourceReference: $orderId
            ),
            default => throw new \RuntimeException(
                'PayPal Capture webhook has no supported evidence transition.'
            ),
        };
    }

    public function updateSubscription(Service $service)
    {
        if ($service->properties->where('key', 'has_paypal_subscription')->first()?->value !== '1') {
            return false;
        }
        $paypal = new PayPal;
        // Update subscription price
        $newPrice = Service::where('subscription_id', $service->subscription_id)->sum('price');
        // Grab currenct subscription ID
        $subscriptionId = $service->subscription_id;
        $url = $paypal->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        // Update subscription price
        $paypal->request('PATCH', $url . '/v1/billing/subscriptions/' . $subscriptionId, [
            [
                'op' => 'replace',
                'path' => '/plan/billing_cycles/@sequence==2/pricing_scheme/fixed_price',
                'value' => [
                    'value' => $newPrice,
                    'currency_code' => $service->currency_code,
                ],
            ],
        ]);

        return true;
    }

    public function cancelSubscription(Service $service)
    {
        // Cancel subscription
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $this->request('post', $url . '/v1/billing/subscriptions/' . $service->subscription_id . '/cancel', [
            'reason' => 'User canceled',
        ]);

        $service->properties()->where('key', 'has_paypal_subscription')->delete();

        return true;
    }
}
