<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep schema DDL separate from the historical stock backfill. MariaDB
     * commits DDL implicitly, so a later data-validation exception in this
     * migration would otherwise leave an unrecorded, non-rerunnable schema.
     */
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['invoice_id']);

            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->restrictOnDelete();
            $table->foreign('plan_id')
                ->references('id')
                ->on('plans')
                ->restrictOnDelete();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();

            $table->string('capacity_mode', 16)
                ->nullable()
                ->after('credit_applied_at');
            $table->unsignedInteger('target_stock_reserved_quantity')
                ->nullable()
                ->after('capacity_mode');
            $table->timestamp('target_stock_reserved_at')
                ->nullable()
                ->after('target_stock_reserved_quantity');
            $table->timestamp('target_stock_released_at')
                ->nullable()
                ->after('target_stock_reserved_at');
            $table->timestamp('target_stock_consumed_at')
                ->nullable()
                ->after('target_stock_released_at');
            $table->string('target_stock_fingerprint', 64)
                ->nullable()
                ->after('target_stock_consumed_at');

            $table->index(
                'capacity_mode',
                'service_upgrades_capacity_mode_idx'
            );
        });
    }

    public function down(): void
    {
        $stockEvidence = DB::table('service_upgrades')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('target_stock_reserved_quantity')
                    ->orWhereNotNull('target_stock_reserved_at')
                    ->orWhereNotNull('target_stock_released_at')
                    ->orWhereNotNull('target_stock_consumed_at')
                    ->orWhereNotNull('target_stock_fingerprint');
            })
            ->orderBy('id')
            ->value('id');
        if ($stockEvidence !== null) {
            throw new RuntimeException(
                "Cannot roll back static upgrade stock because upgrade {$stockEvidence} has durable target-stock evidence. Preserve or explicitly archive the fulfillment history before downgrading."
            );
        }

        Schema::table('service_upgrades', function (Blueprint $table): void {
            $table->dropIndex('service_upgrades_capacity_mode_idx');
            $table->dropForeign(['service_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['invoice_id']);

            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->cascadeOnDelete();
            $table->foreign('plan_id')
                ->references('id')
                ->on('plans')
                ->cascadeOnDelete();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->cascadeOnDelete();

            $table->dropColumn([
                'capacity_mode',
                'target_stock_reserved_quantity',
                'target_stock_reserved_at',
                'target_stock_released_at',
                'target_stock_consumed_at',
                'target_stock_fingerprint',
            ]);
        });
    }
};
