<?php

namespace App\Helpers;

use App\Attributes\ExtensionMeta;
use App\Classes\FilamentInput;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\LateCapacityPaymentException;
use App\Models\BillingAgreement;
use App\Models\BillingChargeAttempt;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoicePaymentInitiation;
use App\Models\InvoiceTransaction;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\BillingChargeAttemptService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Invoice\InvoicePaymentInitiationService;
use App\Services\Service\DurableFulfillmentService;
use Exception;
use Filament\Forms\Components\Placeholder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OwenIt\Auditing\Events\AuditCustom;
use ReflectionClass;
use Throwable;

class ExtensionHelper
{
    /**
     * Used to read all Extensions in app/Extensions with or without type (e.g. 'gateway' or 'server' or 'other' (for non-gateway/server extensions))
     *
     * @param  string|null  $type
     * @return array
     */
    public static function getExtensions($type = null)
    {
        // Check how long this takes
        $extensions = self::getAvailableExtensions();

        if ($type && $type == 'other') {
            // Filter out gateways and servers
            $extensions = array_filter($extensions, fn ($extension) => !in_array($extension['type'], ['gateway', 'server']));

            return $extensions;
        } elseif ($type) {
            $type = strtolower($type);

            return array_filter($extensions, fn ($extension) => $extension['type'] === $type);
        }

        return $extensions;
    }

    /**
     * Get extension and return new instance
     *
     * @param  string  $type
     * @param  string  $extension
     * @return object
     */
    public static function getExtension($type, $extension, $config = [])
    {
        $extension = '\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $extension . '\\' . $extension;

        if (!class_exists($extension)) {
            throw new Exception('Extension "' . $extension . '" not found');
        }

        if (!is_array($config)) {
            $config = self::settingsToArray($config);
        }

        return new $extension($config);
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getConfig($type, $extension, $config = [])
    {
        if (empty($config)) {
            $typeClass = ($type == 'gateway') ? Gateway::class : (($type == 'server') ? Server::class : Extension::class);
            $config = $typeClass::where('extension', $extension)->exists()
                ? $typeClass::where('extension', $extension)->first()->settings->pluck('value', 'key')->toArray()
                : [];
        }

        return self::getExtension($type, $extension)->getConfig($config);
    }

    /**
     * Has function
     *
     * @param  object  $extension
     * @param  string  $function
     */
    public static function hasFunction($extension, $function)
    {
        return method_exists(self::getExtension($extension->type, $extension->extension, $extension->settings), $function);
    }

    /**
     * Test connection
     *
     * @return string
     */
    public static function testConfig($extension, $values)
    {
        return self::getExtension($extension->type, $extension->extension, $values)->testConfig();
    }

    /**
     * Get checkout configuration
     *
     * @return array
     */
    public static function getCheckoutConfig(Product $product, $values = [])
    {
        $server = $product->server;
        if (!$server) {
            return [];
        }

        return self::call($server, 'getCheckoutConfig', [$product, $values, self::settingsToArray($product->settings)], mayFail: true) ?? [];
    }

    /**
     * Get all extensions which are not gateways or servers with their settings
     *
     * @return array
     */
    public static function getAvailableExtensions()
    {
        $extensions = [];

        $classmap = require base_path('vendor/composer/autoload_classmap.php');

        // Magic code so we can also support extensions that don't reside in the extensions folder
        foreach ($classmap as $class => $path) {
            if (strpos($class, 'Paymenter\\Extensions\\') !== 0) {
                continue;
            }

            // Example: Paymenter\Extensions\Whatevers\SomeExtension\SomeExtension
            $parts = explode('\\', $class);

            // Must have at least: Paymenter, Extensions, <Type>s, <Name>, <Class>
            if (count($parts) < 5) {
                continue;
            }

            $typePlural = $parts[2];

            $type = strtolower(rtrim($typePlural, 's'));
            $name = $parts[3];

            // Only add the main extension class (class name matches extension folder)
            if ($parts[4] !== $name) {
                continue;
            }

            if (!file_exists($path) || !class_exists($class)) {
                continue;
            }
            $extensions[] = [
                'name' => $name,
                'type' => $type,
                'meta' => self::getMeta($class),
            ];
        }

        // Newly created extensions sometimes don't have a classmap entry, so we also check the filesystem
        $extensionPath = base_path('extensions');
        $typeFolders = glob($extensionPath . '/*', GLOB_ONLYDIR);
        foreach ($typeFolders as $typeFolder) {
            $type = strtolower(rtrim(basename($typeFolder), 's'));
            $extensionDirs = glob($typeFolder . '/*', GLOB_ONLYDIR);

            foreach ($extensionDirs as $extensionDir) {
                $name = basename($extensionDir);

                // CHeck if already added
                if (in_array($name, array_column($extensions, 'name')) && in_array($type, array_column($extensions, 'type'))) {
                    continue;
                }

                // Check if the class exists
                if (class_exists('\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name)) {

                    $extensions[] = [
                        'name' => $name,
                        'type' => $type,
                        'meta' => self::getMeta('\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $name . '\\' . $name),
                    ];
                }
            }
        }

        return $extensions;
    }

    public static function getMeta($class)
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(ExtensionMeta::class);

        return $attributes ? $attributes[0]->newInstance() : null;
    }

    public static function getInstallableExtensions()
    {
        $extensions = self::getExtensions('other');

        // Filter out already installed extensions
        $installedExtensions = Extension::all()->pluck('extension')->toArray();

        return array_filter($extensions, fn ($extension) => !in_array($extension['name'], $installedExtensions));
    }

    public static function call($extension, $function, $args = [], $mayFail = false)
    {
        try {
            if (!self::hasFunction($extension, $function)) {
                throw new Exception('Function not found');
            }

            return self::getExtension($extension->type, $extension->extension, $extension->settings)->$function(...$args);
        } catch (Exception $e) {
            // If mayFail is true, just report the exception instead of throwing it
            if (!$mayFail) {
                throw $e;
            } else {
                // If extension error is Not Found, don't report
                if (\Str::doesntEndWith($e->getMessage(), 'not found')) {
                    report($e);
                }
            }
        }
    }

    public static function callService(Service $service, $function, $args = [], $mayFail = false)
    {
        $server = $service->product->server;

        if (!$server) {
            if ($mayFail) {
                throw new Exception('No server assigned to this product');
            } else {
                return;
            }
        }

        return self::call($server, $function, [$service, self::settingsToArray($service->product->settings), self::getServiceProperties($service), ...$args], $mayFail);
    }

    /**
     * Convert extensions to options
     *
     * @param  Extension  $extension
     * @return object
     */
    public static function getConfigAsInputs(string $type, ?string $name, $config = [])
    {
        if (!$name) {
            return [];
        }

        $settings = [];

        try {
            foreach (self::getConfig($type, $name, $config) as $key => $config) {
                $config['name'] = 'settings.' . $config['name'];
                $settings[] = FilamentInput::convert($config);
            }
        } catch (Exception $e) {
            $settings[] = Placeholder::make('error')->content($e->getMessage());
            // Handle exception
        }

        return $settings;
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getProductConfig($server, $values = [])
    {
        return self::call($server, 'getProductConfig', [$values]);
    }

    /**
     * Get available settings
     *
     * @return array
     */
    public static function getProductConfigOnce($server, $values = [])
    {
        static $config = [];

        $config = Cache::get('product_config', []);

        $key = $server->extension . $server->id . md5(serialize(self::prepareForSerialization($values)));

        if (!isset($config[$key])) {
            $config[$key] = self::getProductConfig($server, $values);
        }

        Cache::put('product_config', $config, 60);

        return $config[$key];
    }

    protected static function prepareForSerialization($values)
    {
        if (is_array($values)) {
            foreach ($values as $key => $value) {
                $values[$key] = self::prepareForSerialization($value);
            }

            return $values;
        }

        if ($values instanceof TemporaryUploadedFile) {
            // Store the file and use the path, or just use the filename if already stored
            return $values->getRealPath() ?: (string) $values;
        }

        return $values;
    }

    /**
     * Convert settings to array
     *
     * @param  mixed  $settings
     * @return array
     */
    public static function settingsToArray($settings)
    {
        $settingsArray = [];

        if ($settings instanceof Collection) {
            // If $settings is a collection of models
            foreach ($settings as $setting) {
                $settingsArray[$setting->key] = $setting->value;
            }
        } elseif ($settings instanceof Model) {
            // If $settings is a single model
            $settingsArray[$settings->name] = $settings->value;
        }

        return $settingsArray ?? $settings;
    }

    /**
     * Register a new middleware.
     *
     * @param  string  $middleware
     * @param  string  $group
     * @return Router
     */
    public static function registerMiddleware($middleware, $group = 'web')
    {
        return app('router')->pushMiddlewareToGroup($group, $middleware);
    }

    /**
     * Get every gateway which allows to checkout with
     *
     * @return array
     */
    public static function getCheckoutGateways($total, $currency, $type, $items = [])
    {
        $gateways = [];

        foreach (Gateway::with('settings')->get() as $gateway) {
            $extension = self::getExtension(
                'gateway',
                $gateway->extension,
                $gateway->settings
            );
            if (
                in_array(
                    $type,
                    ['cart', 'invoice', 'credits'],
                    true
                )
                && !$extension->supportsDurablePaymentInitiations()
            ) {
                continue;
            }
            if (self::hasFunction($gateway, 'canUseGateway')) {
                if ($extension->canUseGateway($total, $currency, $type, $items)) {
                    $gateways[] = $gateway;
                }
            } else {
                $gateways[] = $gateway;
            }
        }

        return $gateways;
    }

    /**
     * Get payment url or view
     */
    public static function pay($gateway, $invoice)
    {
        $initiation = app(
            InvoicePaymentInitiationService::class
        )->create($invoice, $gateway);

        return app(InvoicePaymentInitiationService::class)
            ->execute($initiation);
    }

    public static function payInvoiceInitiation(
        InvoicePaymentInitiation $initiation
    ): mixed {
        $gateway = Gateway::withTrashed()
            ->whereKey($initiation->gateway_snapshot_id)
            ->with('settings')
            ->firstOrFail();
        if (
            $gateway->trashed()
            || !(bool) $gateway->enabled
            || !hash_equals(
                (string) $gateway->extension,
                (string) $initiation->gateway_extension
            )
        ) {
            throw new \RuntimeException(
                'The frozen provider gateway is unavailable.'
            );
        }

        return self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        )->payInvoiceInitiation($initiation);
    }

    public static function supportsDurablePaymentInitiations(
        Gateway $gateway
    ): bool {
        return self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        )->supportsDurablePaymentInitiations();
    }

    /**
     * Re-read (and, after the abandonment window, safely cancel) the exact
     * provider object frozen by one durable interactive payment generation.
     */
    public static function reconcileInvoicePaymentInitiation(
        InvoicePaymentInitiation $initiation,
        bool $cancelIfSafe = false
    ): array {
        $gateway = Gateway::withTrashed()
            ->whereKey($initiation->gateway_snapshot_id)
            ->with('settings')
            ->firstOrFail();
        if (
            $gateway->trashed()
            || !(bool) $gateway->enabled
            || !hash_equals(
                (string) $gateway->extension,
                (string) $initiation->gateway_extension
            )
        ) {
            throw new \RuntimeException(
                'The frozen provider gateway is unavailable for reconciliation.'
            );
        }
        $extension = self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        );
        if (!$extension->supportsDurablePaymentInitiations()) {
            throw new \RuntimeException(
                'The frozen provider gateway cannot safely reconcile interactive payments.'
            );
        }

        return $extension->reconcileInvoicePaymentInitiation(
            $initiation,
            $cancelIfSafe
        );
    }

    public static function charge(Gateway $gateway, Invoice $invoice, BillingAgreement $billingAgreement): bool
    {
        $billingAttempts = app(BillingChargeAttemptService::class);
        $billingAttempts->createForManualPayment(
            $invoice,
            $billingAgreement
        );

        return $billingAttempts->chargeSavedMethod(
            $invoice,
            $billingAgreement
        );
    }

    public static function chargeBillingAttempt(
        BillingChargeAttempt $attempt
    ): array {
        $gateway = Gateway::withTrashed()
            ->whereKey($attempt->gateway_snapshot_id)
            ->with('settings')
            ->firstOrFail();
        if (
            $gateway->trashed()
            || !(bool) $gateway->enabled
            || !hash_equals(
                (string) $gateway->extension,
                (string) $attempt->gateway_extension
            )
        ) {
            throw new \RuntimeException(
                'The frozen billing gateway is unavailable.'
            );
        }

        return self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        )->chargeBillingAttempt($attempt);
    }

    public static function billingAttemptProviderCustomerReference(
        Gateway $gateway,
        BillingAgreement $billingAgreement
    ): ?string {
        $extension = self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        );
        if (!$extension->supportsDurableBillingAttempts()) {
            throw new \RuntimeException(
                'This saved-payment gateway does not implement durable automatic charge reconciliation.'
            );
        }
        $reference =
            $extension->billingAttemptProviderCustomerReference(
                $billingAgreement
            );
        if ($reference === null) {
            return null;
        }
        if (
            !is_string($reference)
            || trim($reference) === ''
            || strlen(trim($reference)) > 255
        ) {
            throw new \RuntimeException(
                'The gateway returned an invalid durable customer identity.'
            );
        }

        return trim($reference);
    }

    public static function supportsCustomerInitiatedBillingAttempts(
        Gateway $gateway
    ): bool {
        return self::getExtension(
            'gateway',
            $gateway->extension,
            $gateway->settings
        )->supportsCustomerInitiatedBillingAttempts();
    }

    public static function getBillingAgreementGateways(
        bool $customerInitiated = false
    ) {
        $gateways = [];

        foreach (Gateway::with('settings')->get() as $gateway) {
            if (self::hasFunction($gateway, 'supportsBillingAgreements')) {
                $extension = self::getExtension(
                    'gateway',
                    $gateway->extension,
                    $gateway->settings
                );
                if (
                    $extension->supportsBillingAgreements()
                    && $extension->supportsDurableBillingAttempts()
                    && (
                        !$customerInitiated
                        || $extension
                            ->supportsCustomerInitiatedBillingAttempts()
                    )
                ) {
                    $gateways[] = $gateway;
                }
            }
        }

        return $gateways;
    }

    /**
     * Create billing agreement
     *
     * @param  User  $user
     * @param  Gateway  $gateway
     * @return string|view
     */
    public static function createBillingAgreement($user, $gateway)
    {
        return self::getExtension('gateway', $gateway->extension, $gateway->settings)->createBillingAgreement($user);
    }

    /**
     * Cancel billing agreement
     *
     * @return bool
     */
    public static function cancelBillingAgreement(BillingAgreement $billingAgreement)
    {
        $extension = null;
        $resolutionError = null;
        try {
            $gateway = $billingAgreement->gateway()
                ->with('settings')
                ->firstOrFail();
            $extension = self::getExtension(
                'gateway',
                $gateway->extension,
                $gateway->settings
            );
        } catch (Throwable $exception) {
            $resolutionError = $exception;
        }

        // Revoke the method locally before the network request. The model's
        // coordinated delete locks and rechecks unresolved charge attempts,
        // then detaches every future renewal in the same transaction. A
        // missing extension or provider outage can therefore require remote
        // cleanup, but can never leave Paymenter able to charge a method the
        // customer removed.
        $billingAgreement->delete();
        if ($resolutionError !== null) {
            throw $resolutionError;
        }

        return $extension->cancelBillingAgreement($billingAgreement);
    }

    public static function makeBillingAgreement(User $user, $gateway, $name, $externalReference, $type = null, $expiry = null)
    {
        $gateway = Gateway::where('extension', $gateway)->firstOrFail();

        $billingAgreement = BillingAgreement::updateOrCreate([
            'external_reference' => $externalReference,
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
        ], [
            'name' => $name,
            'type' => $type,
            'expiry' => $expiry,
        ]);

        return $billingAgreement;
    }

    /**
     * Add payment to invoice
     *
     * @param  Invoice|int  $invoice
     */
    public static function addPayment(
        $invoice,
        $gateway,
        $amount,
        $fee = null,
        $transactionId = null,
        InvoiceTransactionStatus $status =
            InvoiceTransactionStatus::Succeeded,
        $isCreditTransaction = false,
        ?int $billingChargeAttemptId = null,
        ?string $providerCurrency = null,
        ?string $providerResourceReference = null
    ) {
        $invoiceId = $invoice instanceof Invoice
            ? (int) $invoice->id
            : (int) $invoice;
        $capacityPayments = app(CapacityInvoicePaymentService::class);
        $gatewayId = self::resolveGatewayId($gateway);
        $transactionId = $transactionId === null
            || (string) $transactionId === ''
                ? null
                : (string) $transactionId;
        $billingAttempts = app(BillingChargeAttemptService::class);
        if (
            $billingAttempts->shouldCoordinateProviderEvidence(
                $invoiceId,
                $billingChargeAttemptId,
                $gatewayId,
                $transactionId
            )
        ) {
            return $billingAttempts->recordProviderEvidence(
                $invoiceId,
                $gatewayId,
                $amount,
                $transactionId,
                $status,
                $billingChargeAttemptId,
                static fn () => self::addPayment(
                    $invoiceId,
                    $gateway,
                    $amount,
                    $fee,
                    $transactionId,
                    $status,
                    $isCreditTransaction,
                    $billingChargeAttemptId,
                    $providerCurrency,
                    $providerResourceReference
                )
            );
        }
        $paymentInitiations = app(
            InvoicePaymentInitiationService::class
        );
        if (
            $paymentInitiations
                ->shouldCoordinateProviderEvidence(
                    $invoiceId,
                    $gatewayId,
                    $transactionId,
                    $providerResourceReference
                )
        ) {
            return $paymentInitiations->recordProviderEvidence(
                $invoiceId,
                $gatewayId,
                $amount,
                $transactionId,
                $status,
                static fn () => self::addPayment(
                    $invoiceId,
                    $gateway,
                    $amount,
                    $fee,
                    $transactionId,
                    $status,
                    $isCreditTransaction,
                    $billingChargeAttemptId,
                    $providerCurrency,
                    $providerResourceReference
                ),
                $providerCurrency,
                $providerResourceReference
            );
        }
        $existingEvidence = self::existingPaymentEvidence(
            $invoiceId,
            $gatewayId,
            $transactionId,
            $amount,
            $status,
            (bool) $isCreditTransaction,
        );
        if ($existingEvidence['exact'] ?? false) {
            // Exact replays are read-only. The locked proof below is required
            // only for an insert or processing-to-terminal mutation.
            return $existingEvidence['transaction'];
        }
        $persist = static fn () => $capacityPayments->recordPaymentEvidence(
            $invoiceId,
            static function () use (
                $invoiceId,
                $gatewayId,
                $amount,
                $fee,
                $transactionId,
                $status,
                $isCreditTransaction,
                $billingChargeAttemptId
            ) {
                $invoice = Invoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
                app(CapacityInvoicePaymentService::class)
                    ->assertPaymentAttemptAllowed($invoice);
                $lateAttentionReason = app(
                    CapacityInvoicePaymentService::class
                )->incomingEvidenceAttentionReason(
                    $invoice,
                    $status,
                    $gatewayId,
                    $transactionId,
                    $amount,
                    $billingChargeAttemptId
                );
                if (
                    $lateAttentionReason !== null
                    && app(CapacityInvoicePaymentService::class)
                        ->paymentEvidenceRecoveryReason($invoiceId) === null
                ) {
                    throw new LateCapacityPaymentException(
                        $lateAttentionReason
                    );
                }

                if ($transactionId === null) {
                    return $invoice->transactions()->create([
                        'gateway_id' => $gatewayId,
                        'amount' => $amount,
                        'fee' => $fee,
                        'status' => $status,
                        'is_credit_transaction' => $isCreditTransaction,
                    ]);
                }

                $updateData = [
                    'gateway_id' => $gatewayId,
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'status' => $status,
                    'is_credit_transaction' => $isCreditTransaction,
                ];
                if ($fee !== null) {
                    $updateData['fee'] = $fee;
                }

                $guard =
                    InvoiceTransaction::gatewayTransactionGuard(
                        $gatewayId,
                        $transactionId
                    );
                $identity = Schema::hasColumn(
                    'invoice_transactions',
                    'gateway_transaction_guard'
                )
                    ? ['gateway_transaction_guard' => $guard]
                    : [
                        'gateway_id' => $gatewayId,
                        'transaction_id' => $transactionId,
                    ];

                $existing = $invoice->transactions()
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    // The optimistic precheck is not authoritative: a
                    // concurrent callback can insert or finalize this row
                    // before the invoice and evidence locks are acquired.
                    // Re-prove every immutable field and the exact allowed
                    // status transition against the locked row.
                    $exact = self::assertPaymentEvidenceReplay(
                        $existing,
                        $invoiceId,
                        $gatewayId,
                        $transactionId,
                        $amount,
                        $status,
                        (bool) $isCreditTransaction
                    );
                    if ($exact) {
                        return $existing;
                    }

                    // Only status and the gateway-reported fee may change;
                    // equivalent-but-differently-cast immutable fields are
                    // deliberately left untouched.
                    $existing->status = $status;
                    if ($fee !== null) {
                        $existing->fee = $fee;
                    }
                    $existing->save();

                    return $existing;
                }

                return $invoice->transactions()->create($updateData);
            }
        );

        $invoiceState = Invoice::query()->findOrFail($invoiceId);
        $lateAttentionReason =
            $capacityPayments->incomingEvidenceAttentionReason(
                $invoiceState,
                $status,
                $gatewayId,
                $transactionId,
                $amount,
                $billingChargeAttemptId
            );
        if ($lateAttentionReason !== null) {
            return $capacityPayments->recoverPaymentEvidence(
                $invoiceId,
                $lateAttentionReason,
                $persist
            );
        }
        if (
            $existingEvidence !== null
            && in_array($status, [
                InvoiceTransactionStatus::Succeeded,
                InvoiceTransactionStatus::Failed,
            ], true)
            && $capacityPayments->requiresAttention($invoiceState)
        ) {
            $attentionReason = trim(
                (string) $invoiceState->payment_attention_reason
            );

            return $capacityPayments->recoverPaymentEvidence(
                $invoiceId,
                $attentionReason !== ''
                    ? $attentionReason
                    : 'The capacity-backed invoice remains under manual payment review.',
                $persist
            );
        }
        $capacityPayments->assertPaymentAttemptAllowed($invoiceState);

        try {
            return $persist();
        } catch (Throwable $exception) {
            if ($exception instanceof LateCapacityPaymentException) {
                return $capacityPayments->recoverPaymentEvidence(
                    $invoiceId,
                    $exception->getMessage(),
                    $persist
                );
            }
            $existingEvidence = self::existingPaymentEvidence(
                $invoiceId,
                $gatewayId,
                $transactionId,
                $amount,
                $status,
                (bool) $isCreditTransaction,
            );
            if ($existingEvidence['exact'] ?? false) {
                return $existingEvidence['transaction'];
            }
            if ($existingEvidence !== null) {
                try {
                    return $persist();
                } catch (Throwable $retryException) {
                    $exception = $retryException;
                }
            }
            if ($status !== InvoiceTransactionStatus::Succeeded) {
                throw $exception;
            }
            if ($capacityPayments->requiresAttention($invoiceId)) {
                throw $exception;
            }
            if (
                !$capacityPayments->requiresFulfillmentCoordinator(
                    $invoiceId
                )
            ) {
                throw $exception;
            }

            $reason = 'External payment was recorded, but fulfillment could '
                . 'not be committed atomically: '
                . \Illuminate\Support\Str::limit(
                    $exception->getMessage(),
                    1000,
                    ''
                )
                . '. Do not provision; refund or account-credit review is required.';

            return $capacityPayments->recoverPaymentEvidence(
                $invoiceId,
                $reason,
                $persist
            );
        }
    }

    /**
     * Return exact evidence for an idempotent callback, allow the one valid
     * processing-to-terminal transition, and reject every conflicting reuse.
     *
     * @return array{transaction: InvoiceTransaction, exact: bool}|null
     */
    private static function existingPaymentEvidence(
        int $invoiceId,
        ?int $gatewayId,
        mixed $transactionId,
        mixed $amount,
        InvoiceTransactionStatus $status,
        bool $isCreditTransaction
    ): ?array {
        if ($transactionId === null || (string) $transactionId === '') {
            return null;
        }

        $transactionId = (string) $transactionId;
        $existing = self::paymentEvidenceByIdentity(
            $gatewayId,
            $transactionId
        );
        if ($existing === null) {
            return null;
        }
        $exact = self::assertPaymentEvidenceReplay(
            $existing,
            $invoiceId,
            $gatewayId,
            $transactionId,
            $amount,
            $status,
            $isCreditTransaction
        );

        return [
            'transaction' => $existing,
            'exact' => $exact,
        ];
    }

    /**
     * Re-prove a gateway replay against one authoritative evidence row.
     *
     * @return bool true for an exact replay, false for the one permitted
     *              processing-to-terminal transition
     */
    private static function assertPaymentEvidenceReplay(
        InvoiceTransaction $existing,
        int $invoiceId,
        ?int $gatewayId,
        string $transactionId,
        mixed $amount,
        InvoiceTransactionStatus $status,
        bool $isCreditTransaction
    ): bool {
        if ((int) $existing->invoice_id !== $invoiceId) {
            throw new \RuntimeException(
                'This gateway transaction was already recorded for a different invoice.'
            );
        }
        $recordedGatewayId = $existing->gateway_id === null
            ? null
            : (int) $existing->gateway_id;
        if (
            $recordedGatewayId !== $gatewayId
            || !hash_equals(
                (string) $existing->transaction_id,
                $transactionId
            )
        ) {
            throw new \RuntimeException(
                'The replayed gateway transaction identity conflicts with the recorded payment evidence.'
            );
        }

        $incomingAmount = self::canonicalInvoiceTransactionAmount(
            $amount
        );
        if (
            $incomingAmount === null
            || !hash_equals((string) $existing->amount, $incomingAmount)
            || (bool) $existing->is_credit_transaction
                !== $isCreditTransaction
        ) {
            throw new \RuntimeException(
                'The replayed gateway transaction does not match the recorded payment evidence.'
            );
        }

        $existingStatus = $existing->status
            instanceof InvoiceTransactionStatus
                ? $existing->status
                : InvoiceTransactionStatus::tryFrom(
                    (string) $existing->status
                );
        if ($existingStatus === $status) {
            return true;
        }
        if (
            $existingStatus === InvoiceTransactionStatus::Processing
            && in_array($status, [
                InvoiceTransactionStatus::Succeeded,
                InvoiceTransactionStatus::Failed,
            ], true)
        ) {
            return false;
        }

        throw new \RuntimeException(
            'The replayed gateway transaction status conflicts with the recorded payment evidence.'
        );
    }

    private static function canonicalInvoiceTransactionAmount(
        mixed $amount
    ): ?string {
        try {
            $transaction = new InvoiceTransaction;
            $transaction->setAttribute('amount', $amount);
            $canonical = $transaction->getAttribute('amount');

            return is_string($canonical)
                && preg_match(
                    '/^-?(?:0|[1-9]\d*)\.\d{2}$/D',
                    $canonical
                ) === 1
                    ? $canonical
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function addProcessingPayment(
        $invoice,
        $gateway,
        $amount,
        $fee = null,
        $transactionId = null,
        ?int $billingChargeAttemptId = null,
        ?string $providerCurrency = null,
        ?string $providerResourceReference = null
    ) {
        return self::addPayment(
            $invoice,
            $gateway,
            $amount,
            $fee,
            $transactionId,
            InvoiceTransactionStatus::Processing,
            false,
            $billingChargeAttemptId,
            $providerCurrency,
            $providerResourceReference
        );
    }

    public static function addFailedPayment(
        $invoice,
        $gateway,
        $amount,
        $fee = null,
        $transactionId = null,
        ?int $billingChargeAttemptId = null,
        ?string $providerCurrency = null,
        ?string $providerResourceReference = null
    ) {
        return self::addPayment(
            $invoice,
            $gateway,
            $amount,
            $fee,
            $transactionId,
            InvoiceTransactionStatus::Failed,
            false,
            $billingChargeAttemptId,
            $providerCurrency,
            $providerResourceReference
        );
    }

    public static function addPaymentFee(
        $transactionId,
        $fee,
        $gateway = null
    ) {
        $transactionId = (string) $transactionId;
        if ($gateway !== null) {
            $transaction = self::paymentEvidenceByIdentity(
                self::resolveGatewayId($gateway),
                $transactionId
            );
        } else {
            $matches = InvoiceTransaction::query()
                ->where('transaction_id', $transactionId)
                ->orderBy('id')
                ->get()
                ->filter(
                    fn (InvoiceTransaction $candidate): bool => hash_equals(
                        (string) $candidate->transaction_id,
                        $transactionId
                    )
                )
                ->values();
            if ($matches->count() > 1) {
                throw new \RuntimeException(
                    'The transaction reference is ambiguous across gateways; provide the gateway identity before updating its fee.'
                );
            }
            $transaction = $matches->first();
        }
        if (!$transaction instanceof InvoiceTransaction) {
            throw (new ModelNotFoundException)
                ->setModel(InvoiceTransaction::class);
        }

        $transaction->fee = $fee;
        $transaction->save();

        return $transaction;
    }

    private static function resolveGatewayId(mixed $gateway): ?int
    {
        $resolvedGatewayId = $gateway instanceof Gateway
            ? $gateway->id
            : (
                isset($gateway)
                    ? Gateway::query()
                        ->where('extension', (string) $gateway)
                        ->value('id')
                    : null
            );

        return $resolvedGatewayId !== null
            ? (int) $resolvedGatewayId
            : null;
    }

    private static function paymentEvidenceByIdentity(
        ?int $gatewayId,
        string $transactionId
    ): ?InvoiceTransaction {
        $guard = InvoiceTransaction::gatewayTransactionGuard(
            $gatewayId,
            $transactionId
        );
        if (
            $guard !== null
            && Schema::hasColumn(
                'invoice_transactions',
                'gateway_transaction_guard'
            )
        ) {
            return InvoiceTransaction::query()
                ->where('gateway_transaction_guard', $guard)
                ->orderBy('id')
                ->first();
        }

        $query = InvoiceTransaction::query()
            ->where('transaction_id', $transactionId);
        $gatewayId === null
            ? $query->whereNull('gateway_id')
            : $query->where('gateway_id', $gatewayId);

        return $query->orderBy('id')
            ->get()
            ->first(
                fn (InvoiceTransaction $candidate): bool => hash_equals(
                    (string) $candidate->transaction_id,
                    $transactionId
                )
            );
    }

    /**
     * Cancel subscription
     */
    public static function cancelSubscription(Service $service)
    {
        foreach (Gateway::all() as $gateway) {
            if (self::hasFunction($gateway, 'cancelSubscription')) {
                if (self::getExtension('gateway', $gateway->extension, $gateway->settings)->cancelSubscription($service)) {
                    return true;
                }
            }
        }

        return false;
    }

    /* SERVER RELATED FUNCTIONS */

    /**
     * Get both properties and config options from order product and smash them together
     */
    public static function getServiceProperties(Service $service)
    {
        $properties = [];
        foreach ($service->properties as $property) {
            $properties[$property->key] = $property->value;
        }
        foreach ($service->configs as $config) {
            $configOption = $config->configOption;
            if (!$configOption) {
                continue;
            }

            if ($configOption->type === 'dynamic_slider') {
                if ($config->slider_value !== null) {
                    $properties[$configOption->env_variable ?: $configOption->name] = $config->slider_value;
                }

                continue;
            }

            $properties[$configOption->env_variable] = $config->configValue?->env_variable
                ?? $config->configValue?->name;
        }

        return $properties;
    }

    protected static function checkServer(Service $service, $action)
    {
        $reservedServerId = app(DurableFulfillmentService::class)
            ->reservedServerExtensionId($service);
        $server = $reservedServerId !== null
            ? Server::query()->find($reservedServerId)
            : $service->product->server;

        if (!$server) {
            throw new Exception(
                $reservedServerId !== null
                    ? 'The server extension pinned by this capacity reservation is unavailable'
                    : 'No server assigned to this product'
            );
        }

        // Does server support this action?
        if (!self::hasFunction($server, $action)) {
            throw new Exception('Server does not support the action: ' . $action);
        }

        return $server;
    }

    protected static function recordAudit(Model $model, string $action, array $oldValues = [], array $newValues = [])
    {
        // Trigger audit log for server creation
        $model->auditEvent = $action;
        $model->isCustomEvent = true;
        $model->auditCustomOld = $oldValues;
        $model->auditCustomNew = $newValues;

        Event::dispatch(new AuditCustom($model));
    }

    /**
     * Create server
     */
    public static function createServer(Service $service)
    {
        $server = self::checkServer($service, 'createServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'create_server']);

        return self::getExtension('server', $server->extension, $server->settings)->createServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Suspend server
     */
    public static function suspendServer(Service $service)
    {
        $server = self::checkServer($service, 'suspendServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'suspend_server']);

        return self::getExtension('server', $server->extension, $server->settings)->suspendServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Unsuspend server
     */
    public static function unsuspendServer(Service $service)
    {
        $server = self::checkServer($service, 'unsuspendServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'unsuspend_server']);

        return self::getExtension('server', $server->extension, $server->settings)->unsuspendServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Terminate server
     */
    public static function terminateServer(Service $service)
    {
        app(BillingChargeAttemptService::class)
            ->assertServiceTerminationAllowed($service);

        $server = self::checkServer($service, 'terminateServer');

        self::recordAudit($service, 'extension_action', [], ['action' => 'terminate_server']);

        return self::getExtension('server', $server->extension, $server->settings)->terminateServer($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Upgrade server
     */
    public static function upgradeServer(
        Service $service,
        ?Product $targetProduct = null,
        ?array $targetProperties = null
    ) {
        $targetProduct ??= $service->product;
        $server = $targetProduct->server;
        if (!$server) {
            throw new Exception('No server assigned to this product');
        }
        if (!self::hasFunction($server, 'upgradeServer')) {
            throw new Exception('Server does not support the action: upgradeServer');
        }

        self::recordAudit($service, 'extension_action', [], ['action' => 'upgrade_server']);

        return self::getExtension('server', $server->extension, $server->settings)->upgradeServer(
            $service,
            self::settingsToArray($targetProduct->settings),
            $targetProperties ?? self::getServiceProperties($service)
        );
    }

    /**
     * Get actions for service
     */
    public static function getActions(Service $service)
    {
        $server = self::checkServer($service, 'getActions');

        return self::getExtension('server', $server->extension, $server->settings)->getActions($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service));
    }

    /**
     * Get actions for service
     */
    public static function getView(Service $service, $view)
    {
        $function = isset($view['function']) ? $view['function'] : 'getView';

        $server = self::checkServer($service, $function);

        return self::getExtension('server', $server->extension, $server->settings)->$function($service, self::settingsToArray($service->product->settings), self::getServiceProperties($service), $view['name']);
    }

    /**
     * Revert migrations for a specific extension
     */
    public static function rollbackMigrations($path)
    {
        $migrationFiles = glob(base_path($path . '/*.php'));

        if (empty($migrationFiles)) {
            return;
        }

        // Sort by filename to ensure correct order
        usort($migrationFiles, function ($a, $b) {
            return strcmp(basename($a), basename($b));
        });

        // Reverse the order to rollback in the correct sequence
        $migrationFiles = array_reverse($migrationFiles);

        foreach ($migrationFiles as $file) {
            $migrationName = basename($file, '.php');
            try {
                $migration = require_once $file;
                // return new class extends Migration
                if (method_exists($migration, 'down') && DB::table('migrations')->where('migration', $migrationName)->exists()) {
                    $migration->down();
                    DB::table('migrations')->where('migration', $migrationName)->delete();
                }
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Run migrations for a specific extension
     */
    public static function runMigrations($path)
    {
        try {
            return self::runMigrationsOrFail($path);
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }

    /**
     * Run extension migrations and propagate every failure to the caller.
     *
     * Extension installers and deployment commands must use this strict path;
     * otherwise Paymenter can enable code whose required schema was never applied.
     *
     * @return array<int, string>
     */
    public static function runMigrationsOrFail(string $path): array
    {
        $fullPath = base_path($path);
        if (!is_dir($fullPath)) {
            throw new \RuntimeException("Extension migration path does not exist: {$path}");
        }

        $ranMigrations = app(Migrator::class)->run($fullPath);
        Log::debug('Extension migrations output: ', $ranMigrations);

        return $ranMigrations;
    }
}
