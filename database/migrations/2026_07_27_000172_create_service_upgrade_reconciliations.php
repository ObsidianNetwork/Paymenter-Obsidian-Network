<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'service_upgrade_reconciliations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('service_upgrade_id')
                    ->constrained('service_upgrades')
                    ->restrictOnDelete();
                $table->foreignId('service_id')
                    ->constrained('services')
                    ->restrictOnDelete();
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->constrained('invoices')
                    ->restrictOnDelete();
                $table->string('action', 32);
                $table->text('reason');
                $table->string('operator');
                $table->string('before_status');
                $table->string('after_status');
                $table->json('payment_evidence');
                $table->string('idempotency_key', 64)->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index(
                    ['service_upgrade_id', 'created_at'],
                    'upgrade_reconciliations_history_idx'
                );
            }
        );
    }

    public function down(): void
    {
        if (
            Schema::hasTable('service_upgrade_reconciliations')
            && DB::table('service_upgrade_reconciliations')->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back append-only service upgrade reconciliation evidence.'
            );
        }

        Schema::dropIfExists('service_upgrade_reconciliations');
    }
};
