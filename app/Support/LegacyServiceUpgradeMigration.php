<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LegacyServiceUpgradeMigration
{
    private function __construct()
    {
    }

    /**
     * Legacy dynamic upgrades have neither an immutable source/target snapshot
     * nor a capacity reservation. They cannot safely cross the payment
     * boundary and must not be promoted into the new lifecycle.
     */
    public static function reconcile(): void
    {
        $dynamicProducts = self::dynamicProductIds();
        $groups = DB::table('service_upgrades')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->groupBy('service_id');
        $serviceProducts = DB::table('services')
            ->whereIn('id', $groups->keys()->map(fn ($id): int => (int) $id))
            ->pluck('product_id', 'id');

        foreach ($groups as $serviceId => $upgrades) {
            $activeGuardUsed = false;
            foreach ($upgrades as $upgrade) {
                $dynamic = $dynamicProducts->contains(
                    (int) $upgrade->product_id
                ) || $dynamicProducts->contains(
                    (int) ($serviceProducts[$serviceId] ?? 0)
                );

                if ($dynamic) {
                    $hasPayment = self::retireUnsafeDynamicUpgrade(
                        $upgrade,
                        ! $activeGuardUsed
                    );
                    $activeGuardUsed = $activeGuardUsed || $hasPayment;

                    continue;
                }

                if (! $activeGuardUsed) {
                    DB::table('service_upgrades')
                        ->where('id', $upgrade->id)
                        ->update([
                            'status' => 'awaiting_payment',
                            'active_service_guard_id' => $serviceId,
                        ]);
                    $activeGuardUsed = true;

                    continue;
                }

                DB::table('service_upgrades')
                    ->where('id', $upgrade->id)
                    ->update([
                        'status' => 'cancelled',
                        'last_error' => 'Retired during capacity-aware upgrade migration because a newer active upgrade exists.',
                    ]);
            }
        }
    }

    private static function dynamicProductIds(): Collection
    {
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
            ->join('servers', 'servers.id', '=', 'products.server_id')
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->where('servers.extension', 'Pterodactyl')
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

    private static function retireUnsafeDynamicUpgrade(
        object $upgrade,
        bool $mayOwnActiveGuard
    ): bool
    {
        $invoice = DB::table('invoices')
            ->where('id', $upgrade->invoice_id)
            ->first();
        $hasPayment = $invoice !== null && (
            $invoice->status === 'paid'
            || DB::table('invoice_transactions')
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', ['processing', 'succeeded'])
                ->exists()
        );
        $reason = $hasPayment
            ? 'Legacy dynamic upgrade has payment activity but no immutable capacity reservation; refund or account-credit review is required.'
            : 'Legacy dynamic upgrade retired because it has no immutable capacity reservation. Create a new upgrade quote.';

        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'status' => $hasPayment ? 'needs_attention' : 'cancelled',
                'active_service_guard_id' => $hasPayment && $mayOwnActiveGuard
                    ? $upgrade->service_id
                    : null,
                'last_error' => $reason,
                'failed_at' => now(),
            ]);

        if ($invoice !== null) {
            if ($hasPayment) {
                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'payment_attention_required_at' => now(),
                        'payment_attention_reason' => $reason,
                    ]);
            } elseif ($invoice->status === 'pending') {
                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update(['status' => 'cancelled']);
            }
        }

        Log::warning($reason, [
            'service_upgrade_id' => (int) $upgrade->id,
            'service_id' => (int) $upgrade->service_id,
            'invoice_id' => $invoice?->id,
        ]);

        return $hasPayment;
    }
}
