<?php

namespace App\Services\ServiceUpgrade;

use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UpgradeFailureAlertService
{
    /**
     * Emit one durable operator signal after a service upgrade reaches its
     * terminal needs-attention state.
     */
    public function notify(int $upgradeId): void
    {
        $upgrade = ServiceUpgrade::query()->find($upgradeId);
        if ($upgrade === null) {
            return;
        }

        $reservation = null;
        if (
            Schema::hasTable('ptero_resource_reservations')
            && Schema::hasColumn(
                'ptero_resource_reservations',
                'service_upgrade_id'
            )
        ) {
            $reservation = DB::table('ptero_resource_reservations')
                ->where('service_upgrade_id', $upgrade->id)
                ->where('purpose', 'upgrade')
                ->orderByDesc('id')
                ->first();
        }

        $snapshot = [
            'operation' => 'upgrade',
            'upgrade_id' => (int) $upgrade->id,
            'service_id' => (int) $upgrade->service_id,
            'invoice_id' => $upgrade->invoice_id !== null
                ? (int) $upgrade->invoice_id
                : null,
            'reservation_id' => $reservation?->id !== null
                ? (int) $reservation->id
                : null,
            'node_id' => $reservation?->node_id !== null
                ? (int) $reservation->node_id
                : null,
            'attempts' => (int) $upgrade->provisioning_attempts,
            'error' => mb_substr(
                (string) ($upgrade->last_error ?? 'Unknown upgrade failure.'),
                0,
                1000
            ),
        ];

        // Always retain an operator-visible application signal, even when the
        // optional extension notifier or mail transport is unavailable.
        Log::critical(
            'Paid service upgrade requires operator reconciliation.',
            $snapshot
        );

        $alertServiceClass =
            'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\AlertService';
        if (!class_exists($alertServiceClass)) {
            return;
        }

        try {
            $alerts = app($alertServiceClass);
            if (!method_exists($alerts, 'notifyUpgradeFailure')) {
                return;
            }
            $alerts->notifyUpgradeFailure($snapshot);
        } catch (\Throwable $exception) {
            Log::error('Failed to send the dynamic upgrade operator alert.', [
                ...$snapshot,
                'notification_error' => $exception->getMessage(),
            ]);

            try {
                report($exception);
            } catch (\Throwable) {
                // The critical application log above is the final fallback.
            }
        }
    }
}
