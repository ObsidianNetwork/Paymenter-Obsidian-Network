<?php

namespace Paymenter\Extensions\Gateways\Stripe;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\InvoicePaymentInitiationService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Str;

#[ExtensionMeta(
    name: 'Stripe Gateway',
    description: 'Accept payments via Stripe.',
    version: '1.0.1',
    author: 'Paymenter',
    url: 'https://paymenter.org/docs/extensions/stripe',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgZmlsbD0iIzUzM0FGRCIvPjxwYXRoIGQ9Ik0xMjAgMzkyTDM5MiAzMzRWMTEyTDEyMCAxNz hWMzk yWiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg=='
)]
class Stripe extends Gateway
{
    private const API_VERSION = '2025-07-30.basil';

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.stripe', __DIR__ . '/resources/views');

        Event::listen(Updated::class, function (Updated $event) {
            if ($event->service->properties->where('key', 'has_stripe_subscription')->first()?->value !== '1' || !$event->service->subscription_id) {
                // If the service is not a stripe subscription, skip
                return;
            }
            if ($event->service->isDirty('price') || $event->service->isDirty('expires_at')) {
                try {
                    $this->updateSubscription($event->service);
                } catch (Exception $e) {
                }
            }
            // Check if the service is canceled
            if ($event->service->isDirty('status') && $event->service->status === Service::STATUS_CANCELLED) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    // Ignore exception
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if ($service->properties->where('key', 'has_stripe_subscription')->first()?->value !== '1' || !$service->subscription_id) {
                // If the service is not a stripe subscription, skip
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                // Ignore exception
            }
        });
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'stripe_secret_key',
                'label' => 'Stripe Restricted key',
                'placeholder' => 'Enter your Stripe Restricted API key',
                'type' => 'text',
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_publishable_key',
                'label' => 'Stripe Publishable Key',
                'placeholder' => 'Enter your Stripe Publishable API key',
                'type' => 'text',
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_create_customers',
                'label' => 'Create detailed Stripe customers',
                'type' => 'checkbox',
                'description' => 'Controls the level of detail for Stripe customers. When enabled, customers include full address, phone, and company details. When disabled, customers include only basic information (name, email, user ID). Applies to both one-time payments and billing agreements.',
                'required' => false,
            ],
            [
                'name' => 'stripe_webhook_secret',
                'label' => 'Stripe webhook secret (auto generated)',
                'type' => 'text',
                'description' => 'Stripe webhook secret',
                'required' => false,
            ],
        ];
    }

    public function enabled(Extension $gateway)
    {
        $this->updated($gateway);
    }

    public function updated(Extension $gateway)
    {
        $storedSecret = $gateway->settings()
            ->where('key', 'stripe_webhook_secret')
            ->first()
            ?->value;
        if ($this->hasNonblankWebhookSecret($storedSecret)) {
            return;
        }

        // If the extension isn't enabled, try to boot it
        if (!Route::has('extensions.gateways.stripe.webhook')) {
            require __DIR__ . '/routes.php';
        }

        // Capture existing endpoints, but keep them active until Stripe has
        // returned and Paymenter has durably stored a usable replacement
        // secret. A database rollback cannot restore a remotely deleted
        // endpoint.
        $webhooks = $this->request('get', '/webhook_endpoints');
        if (!is_array($webhooks->data ?? null)) {
            throw new \RuntimeException(
                'Stripe returned an invalid webhook endpoint list. The gateway was not enabled.'
            );
        }
        $webhookUrl = route('extensions.gateways.stripe.webhook');
        $existingWebhooks = collect($webhooks->data)
            ->filter(
                fn ($webhook): bool => is_object($webhook)
                    && isset($webhook->id, $webhook->url)
                    && is_string($webhook->id)
                    && $webhook->id !== ''
                    && hash_equals($webhookUrl, (string) $webhook->url)
            );

        // Create webhook on stripe
        $webhook = $this->request('post', '/webhook_endpoints', [
            'url' => $webhookUrl,
            'description' => 'Paymenter Stripe Webhook',
            'enabled_events' => [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'payment_intent.processing',
                'payment_method.detached',
                'setup_intent.succeeded',
                'subscription_schedule.canceled',
                'invoice.created',
                'invoice.payment_succeeded',
                'charge.updated',
            ],
            'api_version' => self::API_VERSION,
        ]);

        $webhookSecret = $webhook->secret ?? null;
        if (!$this->hasNonblankWebhookSecret($webhookSecret)) {
            throw new \RuntimeException(
                'Stripe created a webhook without returning a usable signing secret. The gateway was not enabled.'
            );
        }

        $setting = $gateway->settings()->updateOrCreate(
            ['key' => 'stripe_webhook_secret'],
            ['value' => trim($webhookSecret)]
        );
        if (!$this->hasNonblankWebhookSecret($setting->fresh()->value)) {
            throw new \RuntimeException(
                'Stripe webhook setup did not persist a usable signing secret. The gateway was not enabled.'
            );
        }

        // Cleanup is best-effort and may only happen after the enclosing
        // gateway-settings transaction commits. A later deadlock or rollback
        // must never leave the database pointing at a secret for an endpoint
        // that was already deleted remotely.
        DB::afterCommit(function () use ($existingWebhooks): void {
            foreach ($existingWebhooks as $existingWebhook) {
                try {
                    $this->request(
                        'delete',
                        '/webhook_endpoints/' . $existingWebhook->id
                    );
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        });

        Notification::make()
            ->success()
            ->title('Webhook created')
            ->body('We\'ve created a webhook for you on Stripe (refresh the page to see the secret)')
            ->send();
    }

    private function request(
        $method,
        $url,
        $data = [],
        array $headers = []
    ) {
        return Http::withHeaders(array_merge($headers, [
            'Authorization' => 'Bearer ' . $this->config('stripe_secret_key'),
            'Stripe-Version' => self::API_VERSION,
        ]))
            ->asForm()
            ->$method('https://api.stripe.com/v1' . $url, $data)
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
            || strlen($reference) > 255
            || preg_match(
                '/^pi_[A-Za-z0-9_]+$/D',
                $reference
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The durable Stripe payment has no valid PaymentIntent identity.'
            );
        }
        $intent = $this->request(
            'get',
            '/payment_intents/' . rawurlencode($reference)
        );
        $status = is_string($intent->status ?? null)
            ? trim($intent->status)
            : '';
        if (
            $cancelIfSafe
            && in_array($status, [
                'requires_payment_method',
                'requires_capture',
                'requires_confirmation',
                'requires_action',
            ], true)
        ) {
            $this->request(
                'post',
                '/payment_intents/'
                    . rawurlencode($reference)
                    . '/cancel',
                ['cancellation_reason' => 'abandoned'],
                [
                    'Idempotency-Key' => 'cancel-'
                        . $initiation->idempotency_key,
                ]
            );
            // The cancellation response is not trusted as a final proof:
            // independently re-read the same immutable provider object.
            $intent = $this->request(
                'get',
                '/payment_intents/' . rawurlencode($reference)
            );
            $status = is_string($intent->status ?? null)
                ? trim($intent->status)
                : '';
        }

        $evidence = $this->interactivePaymentIntentEvidence(
            $intent
        );
        $metadata = $intent->metadata ?? null;
        $expectedMetadata = [
            'invoice_id' => (string) $initiation->invoice_id,
            'invoice_payment_initiation_id' => (string) $initiation->id,
            'invoice_payment_initiation_key' => (string) $initiation->idempotency_key,
        ];
        if (
            !is_object($intent)
            || !hash_equals(
                $reference,
                $evidence['provider_reference']
            )
            || !is_string($intent->object ?? null)
            || !hash_equals('payment_intent', $intent->object)
            || !hash_equals(
                (string) $initiation->amount,
                $evidence['amount']
            )
            || !hash_equals(
                strtolower(
                    (string) $initiation->currency_code
                ),
                $evidence['currency']
            )
            || !is_object($metadata)
        ) {
            return $this->interactiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'Stripe returned a PaymentIntent that conflicts with the durable invoice generation.'
            );
        }
        foreach ($expectedMetadata as $key => $value) {
            if (
                !is_string($metadata->{$key} ?? null)
                || !hash_equals($value, $metadata->{$key})
            ) {
                return $this->interactiveReconciliationAttention(
                    $initiation,
                    $reference,
                    $status,
                    "Stripe PaymentIntent metadata {$key} conflicts with the durable invoice generation."
                );
            }
        }
        $amountReceived = $intent->amount_received ?? null;
        $expectedMinor = $this->stripeMinorAmount(
            $initiation->amount,
            strtolower((string) $initiation->currency_code)
        );
        if (
            !is_int($amountReceived)
            || $amountReceived < 0
            || $amountReceived > $expectedMinor
        ) {
            return $this->interactiveReconciliationAttention(
                $initiation,
                $reference,
                $status,
                'Stripe returned an invalid received amount.'
            );
        }
        $evidenceStatus = match ($status) {
            'succeeded' => $amountReceived === $expectedMinor
                ? 'succeeded'
                : 'attention',
            'processing' => 'processing',
            'canceled' => $amountReceived === 0
                ? 'failed'
                : 'attention',
            'requires_payment_method',
            'requires_capture',
            'requires_confirmation',
            'requires_action' => 'open',
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
                ? 'Stripe returned a PaymentIntent state that cannot be reconciled automatically.'
                : null,
        ];
    }

    private function interactiveReconciliationAttention(
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
        $currency = strtolower(trim((string) $invoice->currency_code));
        $intentData = [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => $this->stripeMinorAmount($total, $currency),
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id],
        ];
        if ($initiation !== null) {
            $intentData['metadata'] += [
                'invoice_payment_initiation_id' => (string) $initiation->id,
                'invoice_payment_initiation_key' => (string) $initiation->idempotency_key,
            ];
        }

        if ($initiation === null) {
            try {
                $includeDetails =
                    (bool) $this->config('stripe_create_customers');
                $customer = $this->createOrGetStripeCustomer(
                    $invoice->user,
                    $includeDetails
                );
                if (!empty($customer->id)) {
                    $intentData['customer'] = $customer->id;
                }
            } catch (Exception $e) {
                // Legacy direct payments continue without a customer if its
                // optional Stripe Customer cannot be created or retrieved.
            }
        }

        $intent = $this->request(
            'post',
            '/payment_intents',
            $intentData,
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
                    (string) ($intent->id ?? '')
                );
        }

        // Pay the invoice using Stripe
        return view('gateways.stripe::pay', ['invoice' => $invoice, 'total' => $total, 'intent' => $intent, 'stripePublishableKey' => $this->config('stripe_publishable_key')]);
    }

    public function webhook(Request $request)
    {
        if (!$this->isValidSignature($request->getContent(), $request->header('Stripe-Signature'), $this->config('stripe_webhook_secret'))) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($request->getContent());

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.processing':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $billingAttempt = $this->stripeBillingAttemptFromIntent(
                    $paymentIntent
                );
                if ($billingAttempt !== null) {
                    $this->handleStripeBillingAttemptWebhook(
                        $billingAttempt,
                        $paymentIntent,
                        'processing'
                    );
                    break;
                }
                $evidence = $this->interactivePaymentIntentEvidence(
                    $paymentIntent
                );
                if (
                    $evidence['verified_generation']
                        instanceof InvoicePaymentInitiation
                ) {
                    app(
                        InvoicePaymentInitiationService::class
                    )->reconcile(
                        $evidence['verified_generation']
                    );
                    if (
                        $evidence['verified_generation']
                            ->fresh()
                            ->status !==
                            InvoicePaymentInitiation::STATUS_NEEDS_ATTENTION
                    ) {
                        break;
                    }
                }
                ExtensionHelper::addProcessingPayment(
                    $evidence['invoice_id'],
                    'Stripe',
                    $evidence['amount'],
                    null,
                    $evidence['provider_reference'],
                    providerCurrency: $evidence['currency'],
                    providerResourceReference: $evidence['provider_reference']
                );
                break;
                // Normal payment
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $billingAttempt = $this->stripeBillingAttemptFromIntent(
                    $paymentIntent
                );
                if ($billingAttempt !== null) {
                    $this->handleStripeBillingAttemptWebhook(
                        $billingAttempt,
                        $paymentIntent,
                        'succeeded'
                    );
                    break;
                }
                $evidence = $this->interactivePaymentIntentEvidence(
                    $paymentIntent
                );
                ExtensionHelper::addPayment(
                    $evidence['invoice_id'],
                    'Stripe',
                    $evidence['amount'],
                    null,
                    $evidence['provider_reference'],
                    providerCurrency: $evidence['currency'],
                    providerResourceReference: $evidence['provider_reference']
                );
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $billingAttempt = $this->stripeBillingAttemptFromIntent(
                    $paymentIntent
                );
                if ($billingAttempt !== null) {
                    $this->handleStripeBillingAttemptWebhook(
                        $billingAttempt,
                        $paymentIntent,
                        'failed'
                    );
                    break;
                }
                $evidence = $this->interactivePaymentIntentEvidence(
                    $paymentIntent
                );
                $claim = app(
                    InvoicePaymentInitiationService::class
                )->providerGenerationForReference(
                    'Stripe',
                    $evidence['provider_reference']
                );
                if ($claim !== null) {
                    // payment_failed normally leaves the same PaymentIntent
                    // reusable in requires_payment_method. Re-read that exact
                    // object instead of falsely closing the generation.
                    app(
                        InvoicePaymentInitiationService::class
                    )->reconcile($claim);
                } else {
                    ExtensionHelper::addFailedPayment(
                        $evidence['invoice_id'],
                        'Stripe',
                        $evidence['amount'],
                        null,
                        $evidence['provider_reference'],
                        providerCurrency: $evidence['currency'],
                        providerResourceReference: $evidence['provider_reference']
                    );
                }
                break;
            case 'charge.updated':
                $charge = $event->data->object; // contains a StripeCharge
                // Get fee from charge
                $fee = 0;
                if ($charge->balance_transaction) {
                    $balanceTransaction = $this->request('get', '/balance_transactions/' . $charge->balance_transaction);
                    $fee = $this->stripeMajorAmount(
                        $balanceTransaction->fee,
                        strtolower(
                            trim((string) $balanceTransaction->currency)
                        ),
                        true
                    );
                }
                ExtensionHelper::addPaymentFee(
                    $charge->payment_intent,
                    $fee,
                    'Stripe'
                );

                break;
            case 'payment_method.detached':
                $paymentMethod = $event->data->object;
                $paymentMethodId = $paymentMethod->id ?? null;
                if (
                    !is_string($paymentMethodId)
                    || trim($paymentMethodId) === ''
                    || strlen(trim($paymentMethodId)) > 255
                ) {
                    throw new \RuntimeException(
                        'Stripe sent an invalid detached payment method identity.'
                    );
                }
                $billingAgreement = BillingAgreement::query()
                    ->where(
                        'external_reference',
                        trim($paymentMethodId)
                    )
                    ->first();
                if ($billingAgreement !== null) {
                    app(BillingChargeAttemptService::class)
                        ->providerPaymentMethodDeleted(
                            $billingAgreement
                        );
                }
                break;
            case 'setup_intent.succeeded':
                $setupIntent = $event->data->object; // contains a StripeSetupIntent
                // If it's a billing agreement, call setupBillingAgreement
                if (!isset($setupIntent->metadata->is_billing_agreement) || $setupIntent->metadata->is_billing_agreement !== '1') {
                    $this->setupSubscription($setupIntent);
                } else {
                    $this->setupBillingAgreement($setupIntent);
                }
                break;
            case 'subscription_schedule.canceled':
                $subscriptionSchedule = $event->data->object; // contains a StripeSubscriptionSchedule
                $this->handleCanceledSubscriptionSchedule(
                    $subscriptionSchedule
                );
                break;
            case 'invoice.created':
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($this->config('stripe_use_subscriptions') !== true) {
                    break;
                }

                // Check if its draft and does exist in our database
                if ($invoice->status === 'draft' && $invoice->parent->type === 'subscription_details') {
                    $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();

                    if ($service) {
                        $this->request('post', '/invoices/' . $invoice->id . '/finalize');
                        // Pay the invoice using Stripe
                        $this->request('post', '/invoices/' . $invoice->id . '/pay');
                    }
                }
                break;
            case 'invoice.payment_succeeded':
                // Mark invoice as paid
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($invoice->parent->type !== 'subscription_details') {
                    break;
                }

                $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();
                if ($service) {
                    $invoiceModel = $service->invoiceItems->sortByDesc('created_at')->first()->invoice;
                    $paymentIntents = $this->request('get', '/invoice_payments', ['invoice' => $invoice->id]);
                    $paymentIntent = collect($paymentIntents->data)->first();

                    if ($paymentIntent->payment->type !== 'payment_intent') {
                        break;
                    }

                    $currency = strtolower(
                        trim((string) $invoice->currency)
                    );
                    ExtensionHelper::addPayment(
                        $invoiceModel->id,
                        'Stripe',
                        $this->stripeMajorAmount(
                            $invoice->amount_paid,
                            $currency
                        ),
                        null,
                        $paymentIntent->payment->payment_intent,
                        providerCurrency: $currency,
                        providerResourceReference: $paymentIntent->payment->payment_intent
                    );
                }
                break;
            default:
                // Not a event type we care about, just return 200
        }

        http_response_code(200);
    }

    /**
     * @return array{
     *     invoice_id: int,
     *     amount: string,
     *     currency: string,
     *     provider_reference: string,
     *     verified_generation: InvoicePaymentInitiation|null
     * }
     */
    private function interactivePaymentIntentEvidence(
        object $paymentIntent
    ): array {
        $providerReference = is_string($paymentIntent->id ?? null)
            ? trim($paymentIntent->id)
            : '';
        $currency = is_string($paymentIntent->currency ?? null)
            ? strtolower(trim($paymentIntent->currency))
            : '';
        if (
            $providerReference === ''
            || strlen($providerReference) > 255
        ) {
            throw new \RuntimeException(
                'Stripe sent an invalid PaymentIntent identity.'
            );
        }
        $metadata = $paymentIntent->metadata ?? null;
        $invoiceId = is_object($metadata)
            && is_numeric($metadata->invoice_id ?? null)
                ? (int) $metadata->invoice_id
                : 0;
        if ($invoiceId <= 0) {
            throw new \RuntimeException(
                'Stripe PaymentIntent evidence has no valid invoice identity.'
            );
        }
        $initiations = app(
            InvoicePaymentInitiationService::class
        );
        $claim = $initiations
            ->providerGenerationForReference(
                'Stripe',
                $providerReference
            );
        $verifiedGeneration = null;
        if ($claim !== null) {
            $invoiceId = (int) $claim->invoice_id;
        }
        if (
            $claim !== null
            || $initiations->hasProviderGenerationHistory(
                $invoiceId,
                'Stripe'
            )
        ) {
            if (
                $initiations->verifyProviderGenerationMetadata(
                    $invoiceId,
                    'Stripe',
                    $providerReference,
                    is_object($metadata)
                        ? ($metadata
                            ->invoice_payment_initiation_id
                            ?? null)
                        : null,
                    is_object($metadata)
                        ? ($metadata
                            ->invoice_payment_initiation_key
                            ?? null)
                        : null
                )
            ) {
                $verifiedGeneration = $claim
                    ?? $initiations
                        ->providerGenerationForReference(
                            'Stripe',
                            $providerReference
                        );
            }
        }

        return [
            'invoice_id' => $invoiceId,
            'amount' => $this->stripeMajorAmount(
                $paymentIntent->amount ?? null,
                $currency
            ),
            'currency' => $currency,
            'provider_reference' => $providerReference,
            'verified_generation' => $verifiedGeneration,
        ];
    }

    private function handleStripeBillingAttemptWebhook(
        BillingChargeAttempt $attempt,
        mixed $eventIntent,
        string $expectedEventEvidenceStatus
    ): void {
        $context = $this->stripeBillingAttemptContext($attempt);
        $eventResult = $this->validateStripeBillingIntent(
            $attempt,
            $eventIntent,
            $context
        );
        if (
            $eventResult['evidence_status']
                !== $expectedEventEvidenceStatus
        ) {
            throw new \RuntimeException(
                'Stripe webhook type and PaymentIntent status disagree.'
            );
        }

        $currentIntent = $this->request(
            'get',
            '/payment_intents/'
                . rawurlencode($eventResult['provider_reference'])
        );
        $currentResult = $this->validateStripeBillingIntent(
            $attempt,
            $currentIntent,
            $context
        );
        if (
            !hash_equals(
                $eventResult['provider_reference'],
                $currentResult['provider_reference']
            )
        ) {
            throw new \RuntimeException(
                'Stripe webhook resolved to a different PaymentIntent.'
            );
        }

        $arguments = [
            (int) $attempt->invoice_id,
            'Stripe',
            $attempt->amount,
            null,
            $currentResult['provider_transaction_id'],
        ];
        match ($currentResult['evidence_status']) {
            'succeeded' => ExtensionHelper::addPayment(
                ...$arguments,
                billingChargeAttemptId: (int) $attempt->id
            ),
            'processing' => ExtensionHelper::addProcessingPayment(
                ...$arguments,
                billingChargeAttemptId: (int) $attempt->id
            ),
            'failed' => ExtensionHelper::addFailedPayment(
                ...$arguments,
                billingChargeAttemptId: (int) $attempt->id
            ),
            default => throw new \RuntimeException(
                'Stripe PaymentIntent has no recordable current evidence state.'
            ),
        };
    }

    private function setupSubscription($setupIntent)
    {
        $setupIntentId = $setupIntent->id ?? null;
        $invoiceId = $setupIntent->metadata->invoice_id ?? null;
        if (
            !is_string($setupIntentId)
            || trim($setupIntentId) === ''
            || !is_numeric($invoiceId)
            || (int) $invoiceId <= 0
        ) {
            throw new \RuntimeException(
                'Stripe returned an invalid subscription setup intent.'
            );
        }

        $invoice = Invoice::findOrFail((int) $invoiceId);
        $user = $invoice->user;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new \RuntimeException('Stripe customer not found.');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        $paymentMethodId = $setupIntent->payment_method ?? null;
        if (
            !is_string($paymentMethodId)
            || trim($paymentMethodId) === ''
        ) {
            throw new \RuntimeException(
                'Stripe returned an invalid subscription payment method identity.'
            );
        }
        $paymentMethodId = trim($paymentMethodId);

        // Create each subscription while holding its local service row. Stripe
        // may redeliver a webhook, or two workers may receive it concurrently;
        // only the first delivery may create and bind the remote schedule.
        $itemSnapshots = $invoice->items()
            ->orderBy('id')
            ->get(['id', 'reference_id', 'reference_type']);
        foreach ($itemSnapshots as $itemSnapshot) {
            if (
                $itemSnapshot->reference_type !== Service::class
                || $itemSnapshot->reference_id === null
            ) {
                continue;
            }

            $attempt = DB::transaction(function () use (
                $customer,
                $invoice,
                $itemSnapshot,
                $paymentMethodId,
                $setupIntentId
            ): ?array {
                [$lockedInvoice, $service, $item] =
                    $this->lockSubscriptionSetupContext(
                        (int) $invoice->id,
                        (int) $itemSnapshot->reference_id,
                        (int) $itemSnapshot->id
                    );
                if (
                    is_string($service->subscription_id)
                    && trim($service->subscription_id) !== ''
                ) {
                    return null;
                }

                $existingSchedule =
                    $this->findSubscriptionScheduleForSetupIntent(
                        $customer,
                        $setupIntentId,
                        $service
                    );
                if ($existingSchedule !== null) {
                    $this->bindSubscriptionSchedule(
                        $service,
                        $existingSchedule
                    );
                    $service->properties()
                        ->where(
                            'key',
                            'stripe_subscription_setup_attempt'
                        )
                        ->delete();

                    return null;
                }

                $idempotencyKey = 'paymenter:setup-subscription:'
                    . hash(
                        'sha256',
                        $setupIntentId . ':' . $service->id
                    );
                $attemptProperty = $service->properties()
                    ->where('key', 'stripe_subscription_setup_attempt')
                    ->lockForUpdate()
                    ->first();
                if ($attemptProperty !== null) {
                    return $this->decodeSubscriptionSetupAttempt(
                        $attemptProperty->value,
                        $customer,
                        $lockedInvoice,
                        $item,
                        $service,
                        $paymentMethodId,
                        $setupIntentId,
                        $idempotencyKey
                    );
                }

                // Only a still-eligible, unbound service may mutate the
                // customer's default payment method.
                $this->request(
                    'post',
                    '/customers/' . $customer->id,
                    [
                        'invoice_settings' => [
                            'default_payment_method' => $paymentMethodId,
                        ],
                    ],
                    [
                        'Idempotency-Key' => 'paymenter:subscription-default-method:'
                            . hash(
                                'sha256',
                                $setupIntentId
                                    . ':'
                                    . $customer->id
                            ),
                    ]
                );

                $product = $service->product;
                if ($product === null || $service->plan === null) {
                    throw new \RuntimeException(
                        'The Stripe subscription service is missing its product or plan.'
                    );
                }

                // Check if the service product already exists in Stripe.
                $stripeProduct = $this->request(
                    'get',
                    '/products/search',
                    [
                        'query' => 'metadata[\'product_id\']:\''
                            . $product->id
                            . '\'',
                    ]
                );

                if (empty($stripeProduct->data)) {
                    $stripeProduct = $this->request(
                        'post',
                        '/products',
                        [
                            'name' => $product->name,
                            'metadata' => [
                                'product_id' => $product->id,
                            ],
                        ],
                        [
                            'Idempotency-Key' => 'paymenter:stripe-product:'
                                . hash(
                                    'sha256',
                                    (string) $product->id
                                ),
                        ]
                    );
                } else {
                    $stripeProduct = $stripeProduct->data[0];
                }
                if (
                    !is_object($stripeProduct)
                    || !is_string($stripeProduct->id ?? null)
                    || trim($stripeProduct->id) === ''
                ) {
                    throw new \RuntimeException(
                        'Stripe returned an invalid product identity.'
                    );
                }

                $phases = [];
                // Check if the current invoice item includes a setup fee.
                if ($item->price != $service->price) {
                    $phases[] = [
                        'items' => [
                            [
                                'price_data' => [
                                    'currency' => strtolower(
                                        (string) $lockedInvoice
                                            ->currency_code
                                    ),
                                    'product' => $stripeProduct->id,
                                    'unit_amount' => $this->stripeMinorAmount(
                                        $item->price,
                                        strtolower(
                                            (string) $lockedInvoice
                                                ->currency_code
                                        ),
                                        true
                                    ),
                                    'recurring' => [
                                        'interval' => $service->plan->billing_unit,
                                        'interval_count' => $service->plan->billing_period,
                                    ],
                                ],
                                'quantity' => 1,
                            ],
                        ],
                        'iterations' => 1,
                        'metadata' => [
                            'service_id' => $service->id,
                            'setup_intent_id' => $setupIntentId,
                        ],
                        'proration_behavior' => 'none',
                    ];
                }
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => [
                                'currency' => strtolower(
                                    (string) $lockedInvoice->currency_code
                                ),
                                'product' => $stripeProduct->id,
                                'unit_amount' => $this->stripeMinorLineAmount(
                                    $service->price,
                                    strtolower(
                                        (string) $lockedInvoice
                                            ->currency_code
                                    ),
                                    $service->quantity,
                                    true
                                ),
                                'recurring' => [
                                    'interval' => $service->plan->billing_unit,
                                    'interval_count' => $service->plan->billing_period,
                                ],
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'metadata' => [
                        'service_id' => $service->id,
                        'setup_intent_id' => $setupIntentId,
                    ],
                    'proration_behavior' => 'none',
                ];

                $payload = [
                    'customer' => $customer->id,
                    'start_date' => now()->startOfDay()->timestamp,
                    'phases' => $phases,
                    'metadata' => [
                        'service_id' => $service->id,
                        'setup_intent_id' => $setupIntentId,
                    ],
                ];
                $attempt = [
                    'version' => 1,
                    'setup_intent_id' => $setupIntentId,
                    'invoice_id' => (int) $lockedInvoice->id,
                    'invoice_item_id' => (int) $item->id,
                    'service_id' => (int) $service->id,
                    'customer_id' => $customer->id,
                    'payment_method_id' => $paymentMethodId,
                    'idempotency_key' => $idempotencyKey,
                    'payload' => $payload,
                    'payload_hash' => hash(
                        'sha256',
                        json_encode(
                            $payload,
                            JSON_THROW_ON_ERROR
                                | JSON_PRESERVE_ZERO_FRACTION
                        )
                    ),
                ];
                $service->properties()->create([
                    'key' => 'stripe_subscription_setup_attempt',
                    'value' => json_encode(
                        $attempt,
                        JSON_THROW_ON_ERROR
                            | JSON_PRESERVE_ZERO_FRACTION
                    ),
                ]);

                return $attempt;
            }, 5);

            if ($attempt === null) {
                continue;
            }

            // The exact request is now durable. A crash after Stripe accepts
            // the POST leaves this attempt available for an exact retry or
            // metadata-based reconciliation after Stripe prunes the key.
            DB::transaction(function () use (
                $attempt,
                $customer,
                $invoice,
                $itemSnapshot,
                $paymentMethodId,
                $setupIntentId
            ): void {
                [$lockedInvoice, $service, $item] =
                    $this->lockSubscriptionSetupContext(
                        (int) $invoice->id,
                        (int) $itemSnapshot->reference_id,
                        (int) $itemSnapshot->id
                    );
                if (
                    is_string($service->subscription_id)
                    && trim($service->subscription_id) !== ''
                ) {
                    return;
                }

                $attemptProperty = $service->properties()
                    ->where('key', 'stripe_subscription_setup_attempt')
                    ->lockForUpdate()
                    ->first();
                if ($attemptProperty === null) {
                    throw new \RuntimeException(
                        'The durable Stripe subscription setup attempt is missing.'
                    );
                }
                $storedAttempt = $this->decodeSubscriptionSetupAttempt(
                    $attemptProperty->value,
                    $customer,
                    $lockedInvoice,
                    $item,
                    $service,
                    $paymentMethodId,
                    $setupIntentId,
                    $attempt['idempotency_key']
                );
                if (
                    !hash_equals(
                        $attempt['payload_hash'],
                        $storedAttempt['payload_hash']
                    )
                ) {
                    throw new \RuntimeException(
                        'The Stripe subscription setup attempt changed before dispatch.'
                    );
                }

                // Stripe only retains idempotency results for a limited time.
                // Reconcile an earlier remote success before POSTing so a
                // process crash before the local save cannot create a second
                // schedule after the idempotency result is pruned.
                $existingSchedule =
                    $this->findSubscriptionScheduleForSetupIntent(
                        $customer,
                        $setupIntentId,
                        $service
                    );
                if ($existingSchedule !== null) {
                    $this->bindSubscriptionSchedule(
                        $service,
                        $existingSchedule
                    );
                    $attemptProperty->delete();

                    return;
                }

                $subscription = $this->request(
                    'post',
                    '/subscription_schedules',
                    $storedAttempt['payload'],
                    [
                        'Idempotency-Key' => $storedAttempt['idempotency_key'],
                    ]
                );
                $this->bindSubscriptionSchedule(
                    $service,
                    $subscription
                );
                $attemptProperty->delete();
            }, 5);
        }
    }

    private function lockSubscriptionSetupContext(
        int $invoiceId,
        int $serviceId,
        int $invoiceItemId
    ): array {
        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->firstOrFail();
        $service = Service::query()
            ->with(['product', 'plan'])
            ->whereKey($serviceId)
            ->lockForUpdate()
            ->firstOrFail();
        $item = $invoice->items()
            ->whereKey($invoiceItemId)
            ->lockForUpdate()
            ->first();
        if (
            $item === null
            || $item->reference_type !== Service::class
            || (int) $item->reference_id !== (int) $service->id
        ) {
            throw new \RuntimeException(
                'The Stripe subscription invoice changed while acquiring its fulfillment locks.'
            );
        }
        if (
            !in_array(
                $invoice->status,
                [Invoice::STATUS_PENDING, Invoice::STATUS_PAID],
                true
            )
            || !in_array(
                $service->status,
                [
                    Service::STATUS_PENDING,
                    Service::STATUS_PROVISIONING,
                    Service::STATUS_ACTIVE,
                    Service::STATUS_SUSPENDED,
                ],
                true
            )
            || (int) $invoice->user_id !== (int) $service->user_id
        ) {
            throw new \RuntimeException(
                'The Stripe subscription invoice or service is no longer eligible for setup.'
            );
        }

        return [$invoice, $service, $item];
    }

    private function decodeSubscriptionSetupAttempt(
        mixed $encodedAttempt,
        object $customer,
        Invoice $invoice,
        object $item,
        Service $service,
        string $paymentMethodId,
        string $setupIntentId,
        string $idempotencyKey
    ): array {
        if (!is_string($encodedAttempt) || trim($encodedAttempt) === '') {
            throw new \RuntimeException(
                'The Stripe subscription setup attempt is invalid.'
            );
        }
        try {
            $attempt = json_decode(
                $encodedAttempt,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'The Stripe subscription setup attempt is invalid.',
                previous: $exception
            );
        }

        $payload = is_array($attempt)
            ? ($attempt['payload'] ?? null)
            : null;
        $payloadHash = is_array($payload)
            ? hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
                )
            )
            : null;
        if (
            !is_array($attempt)
            || ($attempt['version'] ?? null) !== 1
            || (string) ($attempt['setup_intent_id'] ?? '')
                !== $setupIntentId
            || (int) ($attempt['invoice_id'] ?? 0) !== (int) $invoice->id
            || (int) ($attempt['invoice_item_id'] ?? 0)
                !== (int) $item->id
            || (int) ($attempt['service_id'] ?? 0)
                !== (int) $service->id
            || (string) ($attempt['customer_id'] ?? '')
                !== (string) ($customer->id ?? '')
            || (string) ($attempt['payment_method_id'] ?? '')
                !== $paymentMethodId
            || (string) ($attempt['idempotency_key'] ?? '')
                !== $idempotencyKey
            || !is_array($payload)
            || !is_string($attempt['payload_hash'] ?? null)
            || !hash_equals($attempt['payload_hash'], $payloadHash)
        ) {
            throw new \RuntimeException(
                'The Stripe subscription setup attempt does not match the locked service.'
            );
        }

        return $attempt;
    }

    private function findSubscriptionScheduleForSetupIntent(
        object $customer,
        string $setupIntentId,
        Service $service
    ): ?object {
        $customerId = $customer->id ?? null;
        if (!is_string($customerId) || trim($customerId) === '') {
            throw new \RuntimeException(
                'Stripe returned an invalid customer identity.'
            );
        }
        $customerId = trim($customerId);

        $cursor = null;
        $seenScheduleIds = [];
        $matches = [];

        // Stripe permits 100 schedules per page. The explicit cap prevents a
        // malformed or cyclic response from turning webhook handling into an
        // unbounded remote scan; reaching it fails closed before any POST.
        for ($page = 0; $page < 100; $page++) {
            $parameters = [
                'customer' => $customerId,
                'limit' => 100,
            ];
            if ($cursor !== null) {
                $parameters['starting_after'] = $cursor;
            }

            $response = $this->request(
                'get',
                '/subscription_schedules',
                $parameters
            );
            $schedules = $response->data ?? null;
            $hasMore = $response->has_more ?? null;
            if (!is_array($schedules) || !is_bool($hasMore)) {
                throw new \RuntimeException(
                    'Stripe returned an invalid subscription schedule page.'
                );
            }
            if ($hasMore && $schedules === []) {
                throw new \RuntimeException(
                    'Stripe returned an empty non-terminal subscription schedule page.'
                );
            }

            foreach ($schedules as $schedule) {
                $scheduleId = is_object($schedule)
                    ? ($schedule->id ?? null)
                    : null;
                if (
                    !is_string($scheduleId)
                    || trim($scheduleId) === ''
                    || isset($seenScheduleIds[$scheduleId])
                ) {
                    throw new \RuntimeException(
                        'Stripe returned an invalid or repeated subscription schedule identity.'
                    );
                }
                $scheduleId = trim($scheduleId);
                $seenScheduleIds[$scheduleId] = true;

                $scheduleCustomerId = $schedule->customer ?? null;
                if (
                    !is_string($scheduleCustomerId)
                    || !hash_equals(
                        $customerId,
                        trim($scheduleCustomerId)
                    )
                ) {
                    throw new \RuntimeException(
                        'Stripe returned a subscription schedule for the wrong customer.'
                    );
                }

                $metadata = $schedule->metadata ?? null;
                $metadataSetupIntentId = is_object($metadata)
                    ? ($metadata->setup_intent_id ?? null)
                    : null;
                $metadataServiceId = is_object($metadata)
                    ? ($metadata->service_id ?? null)
                    : null;
                if (
                    !is_string($metadataSetupIntentId)
                    || !hash_equals(
                        $setupIntentId,
                        $metadataSetupIntentId
                    )
                    || (string) $metadataServiceId
                        !== (string) $service->id
                ) {
                    continue;
                }

                $status = $schedule->status ?? null;
                if (
                    !is_string($status)
                    || in_array(
                        $status,
                        ['canceled', 'completed', 'released'],
                        true
                    )
                ) {
                    throw new \RuntimeException(
                        'Stripe returned a terminal or invalid matching subscription schedule.'
                    );
                }
                $matches[] = $schedule;
            }

            if (!$hasMore) {
                if (count($matches) > 1) {
                    throw new \RuntimeException(
                        'Stripe returned multiple subscription schedules for one setup intent and service.'
                    );
                }

                return $matches[0] ?? null;
            }

            $lastSchedule = end($schedules);
            $nextCursor = is_object($lastSchedule)
                ? ($lastSchedule->id ?? null)
                : null;
            if (
                !is_string($nextCursor)
                || trim($nextCursor) === ''
                || $nextCursor === $cursor
            ) {
                throw new \RuntimeException(
                    'Stripe returned an invalid subscription schedule cursor.'
                );
            }
            $cursor = trim($nextCursor);
        }

        throw new \RuntimeException(
            'Stripe subscription schedule pagination exceeded the safe reconciliation limit.'
        );
    }

    private function bindSubscriptionSchedule(
        Service $service,
        object $schedule
    ): void {
        $scheduleId = $schedule->id ?? null;
        $subscriptionId = $schedule->subscription ?? null;
        if (
            !is_string($scheduleId)
            || trim($scheduleId) === ''
            || !is_string($subscriptionId)
            || trim($subscriptionId) === ''
        ) {
            throw new \RuntimeException(
                'Stripe did not return complete subscription schedule identities.'
            );
        }

        $service->subscription_id = trim($subscriptionId);
        $service->save();
        $service->properties()->updateOrCreate(
            ['key' => 'stripe_subscription_schedule_id'],
            ['value' => trim($scheduleId)]
        );
        $service->properties()->updateOrCreate(
            ['key' => 'has_stripe_subscription'],
            ['value' => true]
        );
    }

    private function handleCanceledSubscriptionSchedule(
        mixed $subscriptionSchedule
    ): void {
        $scheduleId = is_object($subscriptionSchedule)
            ? ($subscriptionSchedule->id ?? null)
            : null;
        if (!is_string($scheduleId) || trim($scheduleId) === '') {
            throw new \RuntimeException(
                'Stripe returned an invalid canceled subscription schedule identity.'
            );
        }
        $scheduleId = trim($scheduleId);
        $subscriptionId = $subscriptionSchedule->subscription ?? null;
        $subscriptionId = is_string($subscriptionId)
            && trim($subscriptionId) !== ''
                ? trim($subscriptionId)
                : null;

        DB::transaction(function () use (
            $scheduleId,
            $subscriptionId
        ): void {
            $services = Service::query()
                ->whereHas(
                    'properties',
                    fn ($query) => $query
                        ->where(
                            'key',
                            'stripe_subscription_schedule_id'
                        )
                        ->where('value', $scheduleId)
                )
                ->lockForUpdate()
                ->limit(2)
                ->get();

            // Older services predate schedule-ID persistence. Their local
            // identity is the underlying subscription, not the schedule ID.
            if ($services->isEmpty() && $subscriptionId !== null) {
                $services = Service::query()
                    ->where('subscription_id', $subscriptionId)
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();
            }
            if ($services->count() > 1) {
                throw new \RuntimeException(
                    'Multiple services match one canceled Stripe subscription schedule.'
                );
            }

            $service = $services->first();
            if ($service === null) {
                return;
            }

            $storedScheduleId = $service->properties()
                ->where('key', 'stripe_subscription_schedule_id')
                ->value('value');
            if (
                is_string($storedScheduleId)
                && trim($storedScheduleId) !== ''
                && !hash_equals(trim($storedScheduleId), $scheduleId)
            ) {
                throw new \RuntimeException(
                    'The canceled Stripe schedule does not match the service schedule identity.'
                );
            }
            if (
                $subscriptionId !== null
                && is_string($service->subscription_id)
                && trim($service->subscription_id) !== ''
                && !hash_equals(
                    trim($service->subscription_id),
                    $subscriptionId
                )
            ) {
                throw new \RuntimeException(
                    'The canceled Stripe schedule does not match the service subscription identity.'
                );
            }

            $service->subscription_id = null;
            $service->save();
            $service->properties()
                ->whereIn('key', [
                    'has_stripe_subscription',
                    'stripe_subscription_schedule_id',
                ])
                ->delete();
        }, 5);
    }

    public function updateSubscription(Service $service)
    {
        // Grab the schedule from Stripe
        $scheduleId = $this->request('get', '/subscriptions/' . $service->subscription_id);

        if ($service->isDirty('price')) {
            if ($scheduleId->schedule) {

                $oldPhases = $this->request('get', '/subscription_schedules/' . $scheduleId->schedule)->phases;
                // Overwrite phase 2 item 0 with the new price
                $phases = [];
                // Only keep items and end, start date
                foreach ($oldPhases as $phase) {
                    $phases[] = [
                        'items' => $phase->items,
                        'end_date' => $phase->end_date,
                        'start_date' => $phase->start_date,
                    ];
                }
                // Check if the service->product already exists in Stripe
                $product = $service->product;
                $stripeProduct = $this->request('get', '/products/search', ['query' => 'metadata[\'product_id\']:\'' . $product->id . '\'']);

                if (empty($stripeProduct->data)) {
                    // Create product
                    $stripeProduct = $this->request('post', '/products', [
                        'name' => $product->name,
                        'metadata' => ['product_id' => $product->id],
                    ]);
                } else {
                    $stripeProduct = $stripeProduct->data[0];
                }
                // Latest phase is the current one
                $key = count($phases) - 1;
                $phases[$key]['items'][0]->price_data = [
                    'currency' => strtolower(
                        (string) $service->currency->code
                    ),
                    'unit_amount' => $this->stripeMinorAmount(
                        $service->price,
                        strtolower(
                            (string) $service->currency->code
                        ),
                        true
                    ),
                    'product' => $stripeProduct->id,
                    'recurring' => [
                        'interval' => $service->plan->billing_unit,
                        'interval_count' => $service->plan->billing_period,
                    ],
                ];
                $phases[$key]['items'][0]->price = null;
                $phases[$key]['items'][0]->plan = null;

                // Update the schedule
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule, [
                    'phases' => $phases,
                    'proration_behavior' => 'none',
                ]);
            } else {
                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => strtolower(
                            (string) $service->currency->code
                        ),
                        'unit_amount' => $this->stripeMinorAmount(
                            $service->price,
                            strtolower(
                                (string) $service->currency->code
                            ),
                            true
                        ),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
        }

        if ($service->isDirty('expires_at')) {
            $subDate = Carbon::createFromTimestamp($scheduleId->current_period_end)->startOfDay();
            // Check if current date is before the end date of the subscription
            if ($subDate == $service->expires_at || $service->expires_at <= $subDate) {
                return;
            }

            if ($scheduleId->schedule) {
                // As phases are only used for the setup fee, we can remove the phases
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule . '/release', []);

                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => strtolower(
                            (string) $service->currency->code
                        ),
                        'unit_amount' => $this->stripeMinorAmount(
                            $service->price,
                            strtolower(
                                (string) $service->currency->code
                            ),
                            true
                        ),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
            // Update the subscription
            $this->request('post', '/subscriptions/' . $service->subscription_id, [
                'trial_end' => $service->expires_at->timestamp,
                'proration_behavior' => 'none',
            ]);
        }
    }

    public function cancelSubscription(Service $service)
    {
        if (!$service->subscription_id && !$service->properties->where('key', 'has_stripe_subscription')->first()) {
            return;
        }
        $this->request('delete', '/subscriptions/' . $service->subscription_id);

        // Remove subscription id from service
        $service->update(['subscription_id' => null]);
        // Remove Stripe subscription identity properties.
        $service->properties()
            ->whereIn('key', [
                'has_stripe_subscription',
                'stripe_subscription_schedule_id',
            ])
            ->delete();

        return true;
    }

    public function supportsBillingAgreements(): bool
    {
        return true;
    }

    /**
     * Ensure a Stripe Customer exists for the given user. If a customer already
     * exists it will be returned; otherwise a new customer will be created.
     *
     * @param  bool  $includeDetails  Whether to include address, phone, and company details
     * @return object Stripe customer object
     */
    private function createOrGetStripeCustomer($user, $includeDetails = true)
    {
        $stripeCustomerId = $user->properties()->where('key', 'stripe_id')->first();

        if ($stripeCustomerId) {
            try {
                $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
                if (!isset($customer->deleted)) {
                    return $customer;
                }
            } catch (Exception $e) {
                // continue and create a new customer
            }
        }

        // Build basic payload
        $payload = [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ];

        // Add detailed information if requested
        if ($includeDetails) {
            $props = $user->properties()->pluck('value', 'key')->toArray();

            if (!empty($props['company_name'])) {
                $payload['description'] = $props['company_name'];
            }

            $address = array_filter([
                'line1' => $props['address'] ?? null,
                'line2' => $props['address2'] ?? null,
                'city' => $props['city'] ?? null,
                'state' => $props['state'] ?? null,
                'postal_code' => $props['zip'] ?? null,
                'country' => $props['country'] ?? null,
            ]);

            if ($address) {
                $payload['address'] = $address;
            }

            if (!empty($props['phone'])) {
                $payload['phone'] = $props['phone'];
            }
        }

        $customer = $this->request('post', '/customers', $payload);

        $user->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => $customer->id]);

        return $customer;
    }

    public function createBillingAgreement($user)
    {
        // Create or fetch a Stripe customer for this user, use detailed info based on setting
        $includeDetails = (bool) $this->config('stripe_create_customers');
        $customer = $this->createOrGetStripeCustomer($user, $includeDetails);

        $setupIntent = $this->request('post', '/setup_intents', [
            'metadata' => [
                'user_id' => $user->id,
                'is_billing_agreement' => '1',
            ],
            'usage' => 'off_session',
            'customer' => $customer->id,
        ]);

        // Return the client secret to the frontend to complete the setup
        return view('gateways.stripe::billing-agreement', ['intent' => $setupIntent, 'type' => 'setup', 'stripePublishableKey' => $this->config('stripe_publishable_key')]);
    }

    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        try {
            $this->request(
                'post',
                '/payment_methods/'
                    . rawurlencode(
                        $billingAgreement->external_reference
                    )
                    . '/detach'
            );
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }
        }

        return true;
    }

    public function setupAgreement(Request $request)
    {
        $request->validate([
            'setup_intent' => 'required|string|regex:/^seti_[a-zA-Z0-9]{24,}$/|max:255',
        ]);

        $setupIntent = $this->request('get', '/setup_intents/' . $request->input('setup_intent'));

        if ($setupIntent->status !== 'succeeded') {
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Could not add payment method, setup not completed successfully.',
            ]);
        }
        // Validate if user id matches
        if (Auth::id() != $setupIntent->metadata->user_id) {
            abort(403, 'Unauthorized');
        }

        $this->setupBillingAgreement($setupIntent);

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
        ]);
    }

    private function setupBillingAgreement($setupIntent)
    {
        $user = User::findOrFail($setupIntent->metadata->user_id);

        // Get setup intent with expanded data
        $setupIntent = $this->request('get', '/setup_intents/' . $setupIntent->id, [
            'expand' => ['latest_attempt'],
        ]);

        // Get the actual payment method that will be used for future charges
        $actualPaymentMethod = $this->getActualPaymentMethod($setupIntent);

        $data = $this->getPaymentDetails($actualPaymentMethod);

        // Save billing agreement
        ExtensionHelper::makeBillingAgreement(
            $user,
            'Stripe',
            $data['name'],
            $actualPaymentMethod->id,
            $data['type'],
            $data['expiry'],
        );
    }

    private function getActualPaymentMethod($setupIntent)
    {
        $latestAttempt = $setupIntent->latest_attempt;

        // Check if the payment method was converted to another type
        if ($latestAttempt && isset($latestAttempt->payment_method_details)) {
            $details = $latestAttempt->payment_method_details;

            if (in_array($details->type, ['ideal', 'bancontact', 'sofort'])) {
                // These payment methods can be converted to SEPA Direct Debit
                return $this->request('get', '/payment_methods/' . $details->{$details->type}->generated_sepa_debit);
            }
        }

        // If no conversion, use the original payment method
        return $this->request('get', '/payment_methods/' . $setupIntent->payment_method);
    }

    private function getPaymentDetails($paymentMethod)
    {
        $name = match ($paymentMethod->type) {
            'card' => match ($paymentMethod->card->brand) {
                'amex' => 'American Express',
                'diners' => 'Diners Club',
                'discover' => 'Discover',
                'jcb' => 'JCB',
                'mastercard' => 'Mastercard',
                'unionpay' => 'UnionPay',
                'visa' => 'Visa',
                default => ucfirst($paymentMethod->card->brand),
            } . ' **** ' . $paymentMethod->card->last4,

            'sepa_debit' => 'SEPA Direct Debit **** ' . $paymentMethod->sepa_debit->last4,
            'ideal' => 'iDEAL **** ' . $paymentMethod->ideal->bank_code,
            'bancontact' => 'Bancontact **** ' . $paymentMethod->bancontact->bank_code,
            'sofort' => 'SOFORT **** ' . strtoupper($paymentMethod->sofort->country),
            'us_bank_account' => 'US Bank Account **** ' . $paymentMethod->us_bank_account->last4,
            'bacs_debit' => 'Bacs Direct Debit **** ' . $paymentMethod->bacs_debit->last4,
            'au_becs_debit' => 'BECS Direct Debit **** ' . $paymentMethod->au_becs_debit->last4,

            default => ucfirst(str_replace('_', ' ', $paymentMethod->type)),
        };
        $type = match ($paymentMethod->type) {
            'card' => $paymentMethod->card->brand,
            // For the others, just return the type as is
            default => $paymentMethod->type,
        };
        $expiry = null;
        if ($paymentMethod->type === 'card') {
            $expiry = Carbon::createFromDate(
                $paymentMethod->card->exp_year,
                $paymentMethod->card->exp_month,
                1
            )->endOfMonth()->format('Y-m-d');
        }

        return [
            'name' => $name,
            'type' => $type,
            'expiry' => $expiry,
        ];

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
        $context = $this->stripeBillingAttemptContext($attempt);
        $providerReference = $attempt->provider_reference;

        if (
            is_string($providerReference)
            && trim($providerReference) !== ''
        ) {
            $providerReference = trim($providerReference);
            if (
                strlen($providerReference) > 255
                || preg_match(
                    '/^pi_[A-Za-z0-9_]+$/D',
                    $providerReference
                ) !== 1
            ) {
                throw new \RuntimeException(
                    'The durable Stripe charge has an invalid PaymentIntent identity.'
                );
            }
            $intent = $this->request(
                'get',
                '/payment_intents/' . rawurlencode($providerReference)
            );
        } else {
            try {
                $intent = $this->request(
                    'post',
                    '/payment_intents',
                    [
                        'amount' => $context['amount_minor'],
                        'currency' => $context['currency'],
                        'customer' => $context['customer_id'],
                        'payment_method' => $context['payment_method_id'],
                        'off_session' => 'true',
                        'confirm' => 'true',
                        'error_on_requires_action' => 'true',
                        'description' => __('invoices.payment_for_invoice', [
                            'number' => $context['invoice']->number
                                    ?? $context['invoice']->id,
                        ]),
                        'metadata' => $context['metadata'],
                    ],
                    [
                        'Idempotency-Key' => $context['idempotency_key'],
                    ]
                );
            } catch (RequestException $exception) {
                // Stripe returns the authoritative PaymentIntent inside many
                // confirmation failures (for example, a decline or required
                // customer authentication). Preserve that durable identity
                // and map its state instead of turning a known terminal result
                // into an ambiguous transport failure.
                $error = $exception->response->object()?->error ?? null;
                $intent = is_object($error)
                    ? ($error->payment_intent ?? null)
                    : null;
                if (!is_object($intent)) {
                    throw $exception;
                }
            }
        }

        return $this->validateStripeBillingIntent(
            $attempt,
            $intent,
            $context
        );
    }

    public function supportsDurableBillingAttempts(): bool
    {
        return true;
    }

    public function billingAttemptProviderCustomerReference(
        BillingAgreement $billingAgreement
    ): ?string {
        $customerId = $billingAgreement->user
            ?->properties()
            ->where('key', 'stripe_id')
            ->value('value');
        $customerId = is_string($customerId)
            ? trim($customerId)
            : '';
        if (
            $customerId === ''
            || strlen($customerId) > 255
            || preg_match('/^cus_[A-Za-z0-9]+$/D', $customerId) !== 1
        ) {
            throw new \RuntimeException(
                'The saved Stripe payment method has no valid frozen customer identity.'
            );
        }

        return $customerId;
    }

    /**
     * @return array{
     *     invoice: Invoice,
     *     amount_minor: int,
     *     currency: string,
     *     customer_id: string,
     *     payment_method_id: string,
     *     idempotency_key: string,
     *     metadata: array<string, string>
     * }
     */
    private function stripeBillingAttemptContext(
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
                'stripe',
                strtolower(trim($attempt->gateway_extension))
            )
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge ownership snapshot is invalid.'
            );
        }
        if (
            $attempt->gateway_id !== null
            && (int) $attempt->gateway_id
                !== (int) $attempt->gateway_snapshot_id
        ) {
            throw new \RuntimeException(
                'The live Stripe gateway no longer matches the durable charge snapshot.'
            );
        }

        $invoice = Invoice::query()
            ->with('user')
            ->whereKey($attempt->invoice_id)
            ->firstOrFail();
        $paymentMethodId = is_string(
            $attempt->billing_agreement_reference
        )
            ? trim($attempt->billing_agreement_reference)
            : '';
        if (
            $paymentMethodId === ''
            || strlen($paymentMethodId) > 255
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge has no valid frozen payment method.'
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
                    $paymentMethodId,
                    (string) $agreement->external_reference
                )
            ) {
                throw new \RuntimeException(
                    'The live Stripe billing agreement no longer matches the durable charge snapshot.'
                );
            }
        }

        $customerId = is_string(
            $attempt->provider_customer_reference
        )
            ? trim($attempt->provider_customer_reference)
            : '';
        if (
            $customerId === ''
            || strlen($customerId) > 255
            || preg_match('/^cus_[A-Za-z0-9]+$/D', $customerId) !== 1
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge has no valid frozen Stripe customer.'
            );
        }

        $currency = is_string($attempt->currency_code)
            ? strtolower(trim($attempt->currency_code))
            : '';
        if (preg_match('/^[a-z]{3}$/D', $currency) !== 1) {
            throw new \RuntimeException(
                'The durable Stripe charge currency is invalid.'
            );
        }

        $idempotencyKey = is_string($attempt->idempotency_key)
            ? trim($attempt->idempotency_key)
            : '';
        if (
            $idempotencyKey === ''
            || strlen($idempotencyKey) > 255
            || preg_match('/^[\x21-\x7E]+$/D', $idempotencyKey) !== 1
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge idempotency key is invalid.'
            );
        }

        return [
            'invoice' => $invoice,
            'amount_minor' => $this->stripeMinorAmount(
                $attempt->amount,
                $currency
            ),
            'currency' => $currency,
            'customer_id' => $customerId,
            'payment_method_id' => $paymentMethodId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'invoice_id' => (string) $attempt->invoice_id,
                'billing_agreement_id' => (string) $attempt->billing_agreement_snapshot_id,
                'billing_agreement_reference' => $paymentMethodId,
                'gateway_id' => (string) $attempt->gateway_snapshot_id,
                'billing_charge_attempt_id' => (string) $attempt->id,
                'billing_charge_attempt_key' => $idempotencyKey,
                'billing_charge_attempt_purpose' => (string) $attempt->purpose,
            ],
        ];
    }

    private function stripeMinorAmount(
        mixed $amount,
        string $currency,
        bool $allowZero = false
    ): int {
        $currency = strtolower(trim($currency));
        $exponent = $this->stripeCurrencyExponent($currency);
        $amount = (string) $amount;
        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d+))?$/D',
                $amount,
                $matches
            ) !== 1
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge amount is invalid.'
            );
        }

        $fraction = $matches[2] ?? '';
        if (
            $this->stripeRequiresWholeMajorAmount($currency)
            && trim($fraction, '0') !== ''
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge amount contains an unsupported currency fraction.'
            );
        }
        if (
            strlen($fraction) > $exponent
            && trim(substr($fraction, $exponent), '0') !== ''
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge amount exceeds the currency precision.'
            );
        }
        $minor = ltrim(
            $matches[1]
                . str_pad(
                    substr($fraction, 0, $exponent),
                    $exponent,
                    '0'
                ),
            '0'
        );
        $minor = $minor === '' ? '0' : $minor;
        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($minor) > strlen($maximum)
            || (
                strlen($minor) === strlen($maximum)
                && strcmp($minor, $maximum) > 0
            )
            || (!$allowZero && (int) $minor <= 0)
        ) {
            throw new \RuntimeException(
                'The durable Stripe charge amount is outside the supported range.'
            );
        }

        return (int) $minor;
    }

    private function stripeMinorLineAmount(
        mixed $unitAmount,
        string $currency,
        mixed $quantity,
        bool $allowZero = false
    ): int {
        $quantity = is_int($quantity)
            ? $quantity
            : (
                is_string($quantity)
                && preg_match('/^[1-9]\d*$/D', $quantity) === 1
                    ? (int) $quantity
                    : 0
            );
        $unitMinor = $this->stripeMinorAmount(
            $unitAmount,
            $currency,
            $allowZero
        );
        if (
            $quantity <= 0
            || $unitMinor > intdiv(PHP_INT_MAX, $quantity)
        ) {
            throw new \RuntimeException(
                'The Stripe line amount is outside the supported range.'
            );
        }

        return $unitMinor * $quantity;
    }

    private function stripeMajorAmount(
        mixed $minorAmount,
        string $currency,
        bool $allowZero = false
    ): string {
        $currency = strtolower(trim($currency));
        $exponent = $this->stripeCurrencyExponent($currency);
        $minor = is_int($minorAmount)
            ? (string) $minorAmount
            : (
                is_string($minorAmount)
                    ? trim($minorAmount)
                    : ''
            );
        if (
            preg_match('/^(0|[1-9]\d*)$/D', $minor) !== 1
            || (!$allowZero && $minor === '0')
        ) {
            throw new \RuntimeException(
                'Stripe sent an invalid minor-unit amount.'
            );
        }

        if ($exponent === 0) {
            $whole = $minor;
            $fraction = '00';
        } else {
            $padded = str_pad(
                $minor,
                $exponent + 1,
                '0',
                STR_PAD_LEFT
            );
            $whole = substr($padded, 0, -$exponent);
            $providerFraction = substr($padded, -$exponent);
            if (
                $this->stripeRequiresWholeMajorAmount($currency)
                && trim($providerFraction, '0') !== ''
            ) {
                throw new \RuntimeException(
                    'Stripe sent an unsupported fractional amount for this currency.'
                );
            }
            $fraction = $providerFraction;
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 15) {
            throw new \RuntimeException(
                'Stripe sent an amount outside Paymenter\'s supported range.'
            );
        }

        return $whole . '.' . $fraction;
    }

    private function stripeCurrencyExponent(string $currency): int
    {
        if (preg_match('/^[a-z]{3}$/D', $currency) !== 1) {
            throw new \RuntimeException(
                'The Stripe currency is invalid.'
            );
        }
        $zeroDecimalCurrencies = [
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
            'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ];
        if (in_array($currency, $zeroDecimalCurrencies, true)) {
            return 0;
        }

        return 2;
    }

    private function stripeRequiresWholeMajorAmount(
        string $currency
    ): bool {
        return in_array($currency, ['isk', 'ugx'], true);
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
    private function validateStripeBillingIntent(
        BillingChargeAttempt $attempt,
        mixed $intent,
        ?array $context = null
    ): array {
        $context ??= $this->stripeBillingAttemptContext($attempt);
        if (!is_object($intent)) {
            throw new \RuntimeException(
                'Stripe returned an invalid PaymentIntent response.'
            );
        }

        $providerReference = $intent->id ?? null;
        if (
            !is_string($providerReference)
            || strlen($providerReference) > 255
            || preg_match(
                '/^pi_[A-Za-z0-9_]+$/D',
                $providerReference
            ) !== 1
            || (
                is_string($attempt->provider_reference)
                && trim($attempt->provider_reference) !== ''
                && !hash_equals(
                    trim($attempt->provider_reference),
                    $providerReference
                )
            )
        ) {
            throw new \RuntimeException(
                'Stripe returned the wrong PaymentIntent identity.'
            );
        }
        if (
            !is_string($intent->object ?? null)
            || !hash_equals('payment_intent', $intent->object)
        ) {
            throw new \RuntimeException(
                'Stripe returned a non-PaymentIntent provider object.'
            );
        }
        if (
            !is_int($intent->amount ?? null)
            || $intent->amount !== $context['amount_minor']
            || !is_string($intent->currency ?? null)
            || !hash_equals(
                $context['currency'],
                strtolower($intent->currency)
            )
            || !hash_equals(
                $context['customer_id'],
                $this->stripeResponseIdentity(
                    $intent->customer ?? null
                )
            )
            || !hash_equals(
                $context['payment_method_id'],
                $this->stripeResponseIdentity(
                    $intent->payment_method ?? null
                )
            )
        ) {
            throw new \RuntimeException(
                'Stripe returned a PaymentIntent that does not match the durable charge payload.'
            );
        }

        $metadata = $intent->metadata ?? null;
        if (!is_object($metadata)) {
            throw new \RuntimeException(
                'Stripe returned a PaymentIntent without durable charge metadata.'
            );
        }
        foreach ($context['metadata'] as $key => $value) {
            $actual = $metadata->{$key} ?? null;
            if (
                !is_string($actual)
                || !hash_equals($value, $actual)
            ) {
                throw new \RuntimeException(
                    "Stripe PaymentIntent metadata {$key} does not match the durable charge."
                );
            }
        }

        $status = $intent->status ?? null;
        if (
            !is_string($status)
            || trim($status) === ''
            || strlen(trim($status)) > 100
        ) {
            throw new \RuntimeException(
                'Stripe returned a PaymentIntent without a valid status.'
            );
        }
        $status = trim($status);
        $message = $intent->last_payment_error->message ?? null;
        $message = is_string($message) && trim($message) !== ''
            ? trim($message)
            : null;
        $evidenceStatus = match ($status) {
            'succeeded' => 'succeeded',
            'processing' => 'processing',
            'requires_action',
            'requires_payment_method',
            'canceled' => 'failed',
            default => 'attention',
        };
        $amountReceived = $intent->amount_received ?? null;
        if (
            !is_int($amountReceived)
            || $amountReceived < 0
            || $amountReceived > $context['amount_minor']
        ) {
            throw new \RuntimeException(
                'Stripe returned an invalid received amount for the durable charge.'
            );
        }
        if (
            $evidenceStatus === 'succeeded'
            && $amountReceived !== $context['amount_minor']
        ) {
            throw new \RuntimeException(
                'Stripe marked the PaymentIntent succeeded without the exact durable amount.'
            );
        }
        if ($evidenceStatus === 'failed' && $amountReceived !== 0) {
            $evidenceStatus = 'attention';
            $message =
                'Stripe returned a failed PaymentIntent that also reports received funds.';
        }
        if ($message === null) {
            $message = match ($status) {
                'requires_action' => 'The off-session Stripe payment requires customer action.',
                'requires_capture' => 'Stripe returned an uncaptured payment for an automatic charge.',
                'requires_confirmation' => 'Stripe returned an unconfirmed automatic charge.',
                'requires_payment_method' => 'Stripe could not charge the saved payment method.',
                'canceled' => 'Stripe canceled the PaymentIntent.',
                'succeeded', 'processing' => null,
                default => "Stripe returned unsupported PaymentIntent status {$status}.",
            };
        }

        return [
            'provider_reference' => $providerReference,
            'provider_transaction_id' => $providerReference,
            'provider_status' => $status,
            'evidence_status' => $evidenceStatus,
            'fee' => null,
            'message' => $message,
        ];
    }

    private function stripeResponseIdentity(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_object($value) && is_string($value->id ?? null)) {
            return trim($value->id);
        }

        return '';
    }

    private function stripeBillingAttemptFromIntent(
        mixed $intent
    ): ?BillingChargeAttempt {
        $metadata = is_object($intent)
            ? ($intent->metadata ?? null)
            : null;
        if (!is_object($metadata)) {
            return null;
        }
        $attemptId = $metadata->billing_charge_attempt_id ?? null;
        $attemptKey = $metadata->billing_charge_attempt_key ?? null;
        if ($attemptId === null && $attemptKey === null) {
            return null;
        }
        if (
            !is_string($attemptId)
            || preg_match('/^[1-9]\d*$/D', $attemptId) !== 1
            || strlen($attemptId) > strlen((string) PHP_INT_MAX)
            || (
                strlen($attemptId) === strlen((string) PHP_INT_MAX)
                && strcmp($attemptId, (string) PHP_INT_MAX) > 0
            )
            || !is_string($attemptKey)
            || trim($attemptKey) === ''
        ) {
            throw new \RuntimeException(
                'Stripe returned incomplete durable charge metadata.'
            );
        }

        $attempt = BillingChargeAttempt::query()
            ->whereKey((int) $attemptId)
            ->first();
        if (
            $attempt === null
            || !hash_equals(
                (string) $attempt->idempotency_key,
                $attemptKey
            )
        ) {
            throw new \RuntimeException(
                'Stripe returned unknown durable charge metadata.'
            );
        }

        return $attempt;
    }

    public function charge(Invoice $invoice, $amount, BillingAgreement $billingAgreement)
    {
        $this->assertPaymentAttemptAllowed($invoice);
        $user = $invoice->user;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new DisplayException('Stripe customer not found');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        // Create payment intent
        try {
            $intent = $this->request('post', '/payment_intents', [
                'amount' => $this->stripeMinorAmount(
                    $amount,
                    strtolower((string) $invoice->currency_code)
                ),
                'currency' => strtolower(
                    (string) $invoice->currency_code
                ),
                'customer' => $customer->id,
                'payment_method' => $billingAgreement->external_reference,
                'off_session' => 'true',
                'confirm' => 'true',
                'metadata' => ['invoice_id' => $invoice->id, 'billing_agreement_id' => $billingAgreement->id],
            ]);
        } catch (Exception $e) {
            if ($e instanceof RequestException && $e->response->status() === 400) {
                $error = $e->response->object()->error;
                // If error is invalid_request_error and message contains "The provided currency" we can show the message
                if ($error->type === 'invalid_request_error' && Str::contains($error->message, 'The currency provided')) {
                    throw new DisplayException('Cannot charge the billing agreement because the card does not support the invoice currency (' . $invoice->currency_code . '). Please use another payment method.');
                }
            }
            throw new DisplayException('Could not process payment');
        }

        return true;
    }

    // Function to split and decode the Stripe-Signature header
    private function getHeaderValues($sig_header)
    {
        if (!is_string($sig_header)) {
            return [null, null];
        }

        $parts = explode(',', $sig_header);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
            } elseif (strpos($part, 'v1=') === 0) {
                $signature = substr($part, 3);
            }
        }

        return [$timestamp, $signature];
    }

    // Validate the signature
    private function isValidSignature($payload, $sig_header, $secret)
    {
        [$timestamp, $signature] = $this->getHeaderValues($sig_header);

        if (
            empty($timestamp)
            || empty($signature)
            || !$this->hasNonblankWebhookSecret($secret)
        ) {
            return false;
        }
        $timestampValue = filter_var($timestamp, FILTER_VALIDATE_INT);
        if (
            $timestampValue === false
            || abs(now()->timestamp - $timestampValue) > 300
        ) {
            return false;
        }

        // Create the signed payload string
        $signed_payload = $timestamp . '.' . $payload;

        // Compute the expected signature
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        // Compare the expected signature to the actual signature
        return hash_equals($expected_signature, $signature);
    }

    private function hasNonblankWebhookSecret(mixed $secret): bool
    {
        return is_string($secret)
            && preg_match('/\S/u', $secret) === 1;
    }
}
