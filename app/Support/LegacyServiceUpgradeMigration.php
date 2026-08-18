<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class LegacyServiceUpgradeMigration
{
    private function __construct() {}

    /**
     * Legacy unpaid upgrades have no complete immutable source/target
     * contract. Static and dynamic promises must both be retired before
     * payment; rows with existing payment evidence are retained only as
     * refund-not-applied operator obligations.
     *
     * The first lifecycle migration promoted one unsigned legacy row per
     * service from "pending" to "awaiting_payment". Existing installations
     * have therefore already recorded that state and will not rerun the old
     * migration. Scan both states, but leave a fully authentic current signed
     * quote alone so this reconciliation remains safe to rerun.
     */
    public static function reconcile(): void
    {
        $groups = DB::table('service_upgrades')
            ->whereIn('status', [
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('service_id');
        if ($groups->isEmpty()) {
            return;
        }

        $dynamicProducts = self::dynamicProductIds();
        $services = DB::table('services')
            ->whereIn('id', $groups->keys()->map(fn ($id): int => (int) $id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'user_id', 'currency_code'])
            ->keyBy('id');

        foreach ($groups as $serviceId => $upgrades) {
            $activeGuardUsed = false;
            foreach ($upgrades as $upgrade) {
                $service = $services->get((int) $serviceId);
                if ($service === null) {
                    throw new \RuntimeException(
                        "Legacy service upgrade {$upgrade->id} has no service."
                    );
                }
                if (self::hasCurrentSignedContract($upgrade, $service)) {
                    $activeGuardUsed = $activeGuardUsed
                        || (int) ($upgrade->active_service_guard_id ?? 0)
                            === (int) $serviceId;

                    continue;
                }
                $payment = self::paymentContext($upgrade, $service);
                self::assertNoActiveDynamicReservation($upgrade);
                $committed = in_array($upgrade->status, [
                    ServiceUpgrade::STATUS_PAID_COMMITTED,
                    ServiceUpgrade::STATUS_PROVISIONING,
                    ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ], true);
                if ($payment['has_payment'] || $committed) {
                    if (!$payment['has_payment']) {
                        $payment = [
                            'has_payment' => true,
                            'invoice' => $payment['invoice'],
                            'quoted_amount' => number_format(
                                (float) ($upgrade->quoted_amount ?? 0),
                                2,
                                '.',
                                ''
                            ),
                            'currency_code' => strtoupper(
                                (string) (
                                    $upgrade->currency_code
                                    ?? $service->currency_code
                                )
                            ),
                        ];
                    }
                    $guarded = self::markPaymentAttention(
                        $upgrade,
                        $payment,
                        !$activeGuardUsed
                    );
                    $activeGuardUsed = $activeGuardUsed || $guarded;

                    continue;
                }

                $dynamic = $dynamicProducts->contains(
                    (int) $upgrade->product_id
                ) || $dynamicProducts->contains(
                    (int) $service->product_id
                );
                self::retireUnsafeUnpaidUpgrade(
                    $upgrade,
                    $dynamic,
                    $payment['invoice']
                );
            }
        }
    }

    private static function hasCurrentSignedContract(
        object $row,
        object $service
    ): bool {
        $upgrade = ServiceUpgrade::query()->find((int) $row->id);
        if (
            $upgrade === null
            || !$upgrade->snapshotFingerprintsAreAuthentic()
            || !$upgrade->sourceStillMatches()
        ) {
            return false;
        }

        $source = $upgrade->source_snapshot;
        $target = $upgrade->target_snapshot;
        if (!is_array($source) || !is_array($target)) {
            return false;
        }

        $decimal = static fn (mixed $value, bool $signed = false): bool => is_string($value)
            && preg_match(
                $signed
                    ? '/^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D'
                    : '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D',
                $value
            ) === 1;
        $sourceProvisioner = $source['provisioner'] ?? null;
        $targetProvisioner = $target['provisioner'] ?? null;

        $contract = (int) ($source['service_id'] ?? 0)
                === (int) $row->service_id
            && (int) ($target['service_id'] ?? 0)
                === (int) $row->service_id
            && (int) ($source['user_id'] ?? 0)
                === (int) $service->user_id
            && (int) ($target['user_id'] ?? 0)
                === (int) $service->user_id
            && (int) ($source['product_id'] ?? 0)
                === (int) $service->product_id
            && (int) ($target['product_id'] ?? 0)
                === (int) $row->product_id
            && (int) ($target['plan_id'] ?? 0)
                === (int) $row->plan_id
            && (int) ($source['quantity'] ?? 0) === 1
            && (int) ($target['quantity'] ?? 0) === 1
            && is_string($source['plan_type'] ?? null)
            && $source['plan_type'] !== ''
            && ($source['plan_type'] ?? null)
                === ($target['plan_type'] ?? null)
            && strtoupper((string) ($source['currency_code'] ?? ''))
                === strtoupper((string) $service->currency_code)
            && strtoupper((string) ($target['currency_code'] ?? ''))
                === strtoupper((string) $service->currency_code)
            && strtoupper((string) ($row->currency_code ?? ''))
                === strtoupper((string) $service->currency_code)
            && is_array($source['properties'] ?? null)
            && is_array($target['properties'] ?? null)
            && is_array($source['configs'] ?? null)
            && is_array($target['configs'] ?? null)
            && is_array($source['managed_property_keys'] ?? null)
            && is_array($target['managed_property_keys'] ?? null)
            && is_array($source['billing_anchor'] ?? null)
            && is_array($target['billing_anchor'] ?? null)
            && is_array($sourceProvisioner)
            && $sourceProvisioner === $targetProvisioner
            && in_array(
                $sourceProvisioner['mode'] ?? null,
                ['external', 'serverless'],
                true
            )
            && $decimal($target['recurring_price'] ?? null)
            && $decimal($target['upgrade_price'] ?? null, true)
            && $decimal($target['credit_amount'] ?? null)
            && array_key_exists('coupon_id', $target)
            && is_array($target['tax'] ?? null)
            && (int) ($row->active_service_guard_id ?? 0)
                === (int) $row->service_id
            && (int) round(
                (float) ($row->quoted_amount ?? 0) * 100
            ) === (int) round(
                (float) ($target['upgrade_price'] ?? 0) * 100
            )
            && (int) round(
                (float) ($row->credit_amount ?? 0) * 100
            ) === (int) round(
                (float) ($target['credit_amount'] ?? 0) * 100
            );
        if (!$contract) {
            return false;
        }

        $positive = (float) $target['upgrade_price'] > 0;
        if (in_array($row->status, [
            ServiceUpgrade::STATUS_PENDING,
            ServiceUpgrade::STATUS_AWAITING_PAYMENT,
        ], true)) {
            return $positive && self::invoiceBindingMatches(
                $row,
                $service,
                Invoice::STATUS_PENDING
            );
        }

        if (!$positive) {
            return $row->invoice_id === null;
        }

        return self::invoiceBindingMatches(
            $row,
            $service,
            Invoice::STATUS_PAID
        );
    }

    private static function invoiceBindingMatches(
        object $upgrade,
        object $service,
        string $expectedStatus
    ): bool {
        if ($upgrade->invoice_id === null) {
            return false;
        }
        $invoice = DB::table('invoices')
            ->where('id', $upgrade->invoice_id)
            ->lockForUpdate()
            ->first();
        if (
            $invoice === null
            || $invoice->status !== $expectedStatus
            || (int) $invoice->user_id !== (int) $service->user_id
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $service->currency_code)
        ) {
            return false;
        }

        $lines = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'price',
                'quantity',
                'reference_id',
                'reference_type',
            ]);
        $line = $lines->first();

        return $lines->count() === 1
            && $line !== null
            && $line->reference_type === ServiceUpgrade::class
            && (int) $line->reference_id === (int) $upgrade->id
            && (int) $line->quantity === 1
            && (int) round((float) $line->price * 100)
                === (int) round(
                    (float) ($upgrade->quoted_amount ?? 0) * 100
                );
    }

    private static function dynamicProductIds(): Collection
    {
        // This is intentionally broader than the current runtime classifier.
        // A legacy quote may predate later visibility, metadata, or extension
        // deletion changes. Any historical Pterodactyl resource slider without
        // an immutable reservation must be retired rather than silently treated
        // as a safe static upgrade.
        return DB::table('config_options')
            ->join(
                'config_option_products',
                'config_option_products.config_option_id',
                '=',
                'config_options.id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'config_option_products.product_id'
            )
            ->join(
                'extensions as server_extensions',
                'server_extensions.id',
                '=',
                'products.server_id'
            )
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->where('server_extensions.type', 'server')
            ->where('server_extensions.extension', 'Pterodactyl')
            ->get([
                'config_option_products.product_id',
                'config_options.env_variable',
                'config_options.metadata',
            ])
            ->filter(function ($option): bool {
                $metadata = is_string($option->metadata)
                    ? json_decode($option->metadata, true)
                    : (array) $option->metadata;
                $resource = strtolower((string) (
                    $metadata['resource_type']
                    ?? $option->env_variable
                    ?? ''
                ));

                return in_array($resource, ['memory', 'cpu', 'disk'], true);
            })
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private static function retireUnsafeUnpaidUpgrade(
        object $upgrade,
        bool $dynamic,
        ?object $invoice
    ): void {
        $reason = $dynamic
            ? 'Legacy dynamic upgrade retired because it has no immutable capacity reservation. Create a new upgrade quote.'
            : 'Legacy unpaid upgrade retired because it has no immutable signed quote or target-stock reservation. Create a new upgrade quote.';

        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'status' => 'cancelled',
                'active_service_guard_id' => null,
                'last_error' => $reason,
                'failed_at' => now(),
            ]);

        if ($invoice?->status === 'pending') {
            DB::table('invoices')
                ->where('id', $invoice->id)
                ->update(['status' => 'cancelled']);
        }

        Log::warning($reason, [
            'service_upgrade_id' => (int) $upgrade->id,
            'service_id' => (int) $upgrade->service_id,
            'invoice_id' => $invoice?->id,
        ]);

    }

    private static function assertNoActiveDynamicReservation(
        object $upgrade
    ): void {
        if (
            !Schema::hasTable('ptero_resource_reservations')
            || !Schema::hasColumns(
                'ptero_resource_reservations',
                ['purpose', 'service_upgrade_id', 'status']
            )
        ) {
            return;
        }

        $reservation = DB::table('ptero_resource_reservations')
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $upgrade->id)
            ->whereIn('status', [
                'pending',
                'paid_committed',
                'confirmed',
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id', 'status']);
        if ($reservation !== null) {
            throw new \RuntimeException(
                "Legacy service upgrade {$upgrade->id} still owns dynamic capacity reservation {$reservation->id} ({$reservation->status}). Reconcile it through the extension capacity coordinator before retrying the migration."
            );
        }
    }

    /**
     * @return array{
     *   has_payment: bool,
     *   invoice: ?object,
     *   quoted_amount: ?string,
     *   currency_code: ?string
     * }
     */
    private static function paymentContext(
        object $upgrade,
        object $service
    ): array {
        $invoice = $upgrade->invoice_id === null
            ? null
            : DB::table('invoices')
                ->where('id', $upgrade->invoice_id)
                ->lockForUpdate()
                ->first();
        if ($invoice === null) {
            return [
                'has_payment' => false,
                'invoice' => null,
                'quoted_amount' => null,
                'currency_code' => null,
            ];
        }

        $transactions = DB::table('invoice_transactions')
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['processing', 'succeeded'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        $hasPayment =
            $invoice->status === 'paid' || $transactions->isNotEmpty();
        $line = null;
        if ($invoice->status === 'pending' || $hasPayment) {
            $lines = DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'price',
                    'quantity',
                    'reference_id',
                    'reference_type',
                ]);
            $line = $lines->first();
            if (
                $lines->count() !== 1
                || $line === null
                || $line->reference_type !== ServiceUpgrade::class
                || (int) $line->reference_id !== (int) $upgrade->id
                || (int) $line->quantity !== 1
                || (int) $invoice->user_id
                    !== (int) $service->user_id
                || strtoupper((string) $invoice->currency_code)
                    !== strtoupper((string) $service->currency_code)
            ) {
                throw new \RuntimeException(
                    "Legacy service upgrade {$upgrade->id} has an ambiguous invoice obligation. Reconcile invoice {$invoice->id} before retrying the migration."
                );
            }
        }
        if (!$hasPayment) {
            return [
                'has_payment' => false,
                'invoice' => $invoice,
                'quoted_amount' => null,
                'currency_code' => null,
            ];
        }

        return [
            'has_payment' => true,
            'invoice' => $invoice,
            'quoted_amount' => number_format(
                (float) $line->price,
                2,
                '.',
                ''
            ),
            'currency_code' => strtoupper(
                (string) $invoice->currency_code
            ),
        ];
    }

    /**
     * @param  array{
     *   has_payment: bool,
     *   invoice: object|null,
     *   quoted_amount: string,
     *   currency_code: string
     * }  $payment
     */
    private static function markPaymentAttention(
        object $upgrade,
        array $payment,
        bool $mayOwnActiveGuard
    ): bool {
        $reason =
            'Legacy upgrade has payment activity but no immutable signed quote or capacity proof. Only audited refunded-not-applied reconciliation is safe.';
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'status' => 'needs_attention',
                'active_service_guard_id' => $mayOwnActiveGuard
                    ? $upgrade->service_id
                    : null,
                'quoted_amount' => $payment['quoted_amount'],
                'currency_code' => $payment['currency_code'],
                'legacy_refund_only_at' => now(),
                'last_error' => $reason,
                'failed_at' => now(),
            ]);
        if ($payment['invoice'] !== null) {
            DB::table('invoices')
                ->where('id', $payment['invoice']->id)
                ->update([
                    'payment_attention_required_at' => now(),
                    'payment_attention_reason' => $reason,
                ]);
        }

        Log::warning($reason, [
            'service_upgrade_id' => (int) $upgrade->id,
            'service_id' => (int) $upgrade->service_id,
            'invoice_id' => $payment['invoice'] === null
                ? null
                : (int) $payment['invoice']->id,
        ]);

        return $mayOwnActiveGuard;
    }
}
