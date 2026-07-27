<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data-only and idempotent so a failed MariaDB run can be retried.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // Legacy cancelled services were already processed by the old
            // cancellation paths. Mark them released to prevent replay.
            DB::table('services')
                ->where('status', 'cancelled')
                ->whereNull('product_stock_released_at')
                ->update(['product_stock_released_at' => now()]);

            if (
                Schema::hasTable('ptero_resource_reservations')
                && Schema::hasColumns(
                    'ptero_resource_reservations',
                    ['purpose', 'service_id', 'status']
                )
            ) {
                DB::table('services')
                    ->whereNull('product_stock_released_at')
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('ptero_resource_reservations')
                            ->whereColumn(
                                'ptero_resource_reservations.service_id',
                                'services.id'
                            )
                            ->where(
                                'ptero_resource_reservations.purpose',
                                'checkout'
                            )
                            ->whereIn(
                                'ptero_resource_reservations.status',
                                ['expired', 'cancelled']
                            );
                    })
                    ->update([
                        'product_stock_released_at' => now(),
                    ]);
            }
        }, 5);
    }

    public function down(): void
    {
        // A release marker cannot be distinguished from a later runtime
        // release and must never be cleared automatically.
    }
};
