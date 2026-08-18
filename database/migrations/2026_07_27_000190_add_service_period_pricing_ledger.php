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
            $table->decimal('period_base_price', 17, 2)
                ->nullable()
                ->after('price');
            $table->decimal('current_period_price', 17, 2)
                ->nullable()
                ->after('period_base_price');
            $table->timestamp('pricing_ledger_started_at')
                ->nullable()
                ->after('current_period_price');
            $table->timestamp('pricing_ledger_verified_at')
                ->nullable()
                ->after('pricing_ledger_started_at');
            $table->unsignedInteger('billing_cycles_completed')
                ->default(0)
                ->after('pricing_ledger_verified_at');
        });
    }

    public function down(): void
    {
        if (
            DB::table('service_upgrades')
                ->whereNotNull('source_fingerprint')
                ->exists()
        ) {
            throw new RuntimeException(
                'The service pricing ledger cannot be removed after signed upgrades exist.'
            );
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn([
                'period_base_price',
                'current_period_price',
                'pricing_ledger_started_at',
                'pricing_ledger_verified_at',
                'billing_cycles_completed',
            ]);
        });
    }
};
