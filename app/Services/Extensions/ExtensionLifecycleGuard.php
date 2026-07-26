<?php

namespace App\Services\Extensions;

use App\Models\ConfigOption;
use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent extension lifecycle operations from removing the code that owns
 * unresolved durable fulfillment state.
 */
class ExtensionLifecycleGuard
{
    public const DYNAMIC_PTERODACTYL = 'DynamicPterodactyl';

    /**
     * Refuse to activate dynamic fulfillment while legacy per-slider base
     * prices can disagree with the one plan-level amount used by invoices.
     */
    public function assertCanActivate(Extension|string $extension): void
    {
        $name = $extension instanceof Extension
            ? (string) $extension->extension
            : $extension;
        if (strcasecmp($name, self::DYNAMIC_PTERODACTYL) !== 0) {
            return;
        }

        if (
            ! Schema::hasTable('config_options')
            || ! Schema::hasTable('plans')
            || ! Schema::hasColumn('plans', 'dynamic_slider_base_price')
        ) {
            throw new \RuntimeException(
                'Dynamic slider plan-level pricing migrations are incomplete.'
            );
        }

        $invalid = [];
        ConfigOption::query()
            ->where('type', 'dynamic_slider')
            ->orderBy('id')
            ->get(['id', 'type', 'metadata'])
            ->each(function (ConfigOption $option) use (&$invalid): void {
                try {
                    $option->assertUsesPlanLevelBasePrice();
                } catch (\InvalidArgumentException $exception) {
                    $invalid[] = (int) $option->id;
                }
            });

        if ($invalid === []) {
            return;
        }

        throw new \RuntimeException(
            'Dynamic Pterodactyl cannot be activated while dynamic slider '
            .'options retain legacy per-slider base prices (option IDs: '
            .implode(', ', array_slice($invalid, 0, 20))
            .(count($invalid) > 20 ? ', …' : '')
            .'). Run paymenter:migrate-slider-base-price --force, resolve any '
            .'reported conflicts, and retry activation.'
        );
    }

    public function assertCanDeactivate(Extension|string $extension): void
    {
        $name = $extension instanceof Extension
            ? (string) $extension->extension
            : $extension;
        if (strcasecmp($name, self::DYNAMIC_PTERODACTYL) !== 0) {
            return;
        }

        $counts = $this->activeCommitmentCounts();
        if (array_sum($counts) === 0) {
            return;
        }

        throw new \RuntimeException(
            'Dynamic Pterodactyl cannot be disabled, replaced, or uninstalled '
            .'while durable fulfillment work is unresolved ('
            .collect($counts)
                ->filter(fn (int $count): bool => $count > 0)
                ->map(fn (int $count, string $type): string => "{$type}: {$count}")
                ->implode(', ')
            .'). Drain, cancel, or reconcile these records first.'
        );
    }

    /**
     * Version upgrades preserve the extension identity and the code that owns
     * durable commitments. They are allowed with live services only while the
     * application is in deployment maintenance; destructive lifecycle actions
     * continue to use assertCanDeactivate().
     */
    public function assertCanUpgrade(Extension|string $extension): void
    {
        $name = $extension instanceof Extension
            ? (string) $extension->extension
            : $extension;
        if (strcasecmp($name, self::DYNAMIC_PTERODACTYL) !== 0) {
            return;
        }
        if (
            array_sum($this->activeCommitmentCounts()) > 0
            && ! app()->isDownForMaintenance()
        ) {
            throw new \RuntimeException(
                'Dynamic Pterodactyl has live durable services. Put Paymenter '
                .'into deployment maintenance before upgrading it, run the '
                .'strict migrations/readiness gate, then restart queue workers.'
            );
        }
    }

    /**
     * @return array{reservations: int, upgrades: int, payment_attention: int}
     */
    public function activeCommitmentCounts(): array
    {
        if (! Schema::hasTable('ptero_resource_reservations')) {
            return [
                'reservations' => 0,
                'upgrades' => 0,
                'payment_attention' => 0,
            ];
        }

        $hasCancellationState = Schema::hasColumn(
            'ptero_resource_reservations',
            'cancellation_requested_at'
        );
        $reservations = DB::table('ptero_resource_reservations as reservation')
            ->leftJoin('services as service', 'service.id', '=', 'reservation.service_id')
            ->where(function ($query) use ($hasCancellationState): void {
                $query->whereIn('reservation.status', ['pending', 'paid_committed']);
                $query->orWhere(function ($query): void {
                    $query->where('reservation.status', 'confirmed')
                        ->where(function ($query): void {
                            $query->whereNull('service.status')
                                ->orWhere(
                                    'service.status',
                                    '!=',
                                    'cancelled'
                                );
                        });
                });
                if ($hasCancellationState) {
                    $query->orWhere(function ($query): void {
                        $query->whereNotNull('reservation.cancellation_requested_at')
                            ->where(function ($query): void {
                                $query->whereNull('service.status')
                                    ->orWhere('service.status', '!=', 'cancelled');
                            });
                    });
                }
                $query->orWhereIn('service.status', [
                    'provisioning',
                    'provisioning_failed',
                    'cancellation_pending',
                ]);
            })
            ->count();

        $upgrades = 0;
        if (
            Schema::hasTable('service_upgrades')
            && Schema::hasColumn('service_upgrades', 'status')
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'service_upgrade_id'
            )
        ) {
            $upgrades = DB::table('service_upgrades as upgrade')
                ->join(
                    'ptero_resource_reservations as reservation',
                    'reservation.service_upgrade_id',
                    '=',
                    'upgrade.id'
                )
                ->whereIn('upgrade.status', [
                    'pending',
                    'awaiting_payment',
                    'paid_committed',
                    'provisioning',
                    'retryable_failed',
                    'needs_attention',
                ])
                ->distinct('upgrade.id')
                ->count('upgrade.id');
        }

        $paymentAttention = 0;
        if (
            Schema::hasTable('invoices')
            && Schema::hasColumn('invoices', 'payment_attention_required_at')
            && Schema::hasColumn('ptero_resource_reservations', 'invoice_id')
        ) {
            $paymentAttention = DB::table('invoices')
                ->whereNotNull('payment_attention_required_at')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('ptero_resource_reservations')
                        ->whereColumn(
                            'ptero_resource_reservations.invoice_id',
                            'invoices.id'
                        );
                })
                ->count();
        }

        return [
            'reservations' => $reservations,
            'upgrades' => $upgrades,
            'payment_attention' => $paymentAttention,
        ];
    }
}
