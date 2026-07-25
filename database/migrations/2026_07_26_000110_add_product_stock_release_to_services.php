<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->timestamp('product_stock_released_at')
                ->nullable()
                ->after('status');
        });

        // Legacy cancelled services were already processed by the old
        // cancellation paths. Mark them released to prevent replay.
        DB::table('services')
            ->where('status', 'cancelled')
            ->whereNull('product_stock_released_at')
            ->update(['product_stock_released_at' => now()]);
        if (Schema::hasTable('ptero_resource_reservations')) {
            DB::table('services')
                ->whereNull('product_stock_released_at')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('ptero_resource_reservations')
                        ->whereColumn(
                            'ptero_resource_reservations.service_id',
                            'services.id'
                        )
                        ->whereIn(
                            'ptero_resource_reservations.status',
                            ['expired', 'cancelled']
                        );
                })
                ->update(['product_stock_released_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('product_stock_released_at');
        });
    }
};
