<?php

use App\Models\Gateway;
use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'invoice_payment_initiations',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignIdFor(Invoice::class)
                    ->constrained()
                    ->restrictOnDelete();
                // Preserve every provider attempt while allowing exactly one
                // unresolved claim per invoice. Terminal rows clear this
                // nullable slot; SQL unique indexes permit multiple NULLs.
                $table->foreignId('active_invoice_id')
                    ->nullable()
                    ->unique()
                    ->constrained('invoices')
                    ->restrictOnDelete();
                $table->unsignedInteger('generation');
                $table->foreignIdFor(Gateway::class)
                    ->nullable()
                    ->constrained('extensions')
                    ->nullOnDelete();
                $table->unsignedBigInteger('gateway_snapshot_id');
                $table->string('gateway_extension', 100);
                $table->decimal('amount', 17, 2);
                $table->string('currency_code', 3);
                $table->uuid('idempotency_key')->unique();
                $table->string('status', 32)
                    ->default('initiating');
                $table->unsignedSmallInteger('attempt_count')
                    ->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->uuid('reconciliation_lease_token')
                    ->nullable();
                $table->timestamp('reconciliation_lease_expires_at')
                    ->nullable();
                $table->timestamp('next_reconcile_at')->nullable();
                $table->unsignedSmallInteger(
                    'reconciliation_attempt_count'
                )->default(0);
                $table->string('provider_reference')->nullable();
                $table->string('provider_transaction_id')
                    ->nullable();
                $table->string('provider_status', 100)
                    ->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->text('release_reason')->nullable();
                $table->text('attention_reason')->nullable();
                $table->boolean('attention_reconcilable')
                    ->default(false);
                $table->timestamps();

                $table->unique(
                    ['invoice_id', 'generation'],
                    'invoice_payment_initiation_generation'
                );
                $table->index(
                    ['active_invoice_id', 'status', 'id'],
                    'invoice_payment_initiation_active'
                );
                $table->index(
                    ['status', 'next_reconcile_at', 'id'],
                    'invoice_payment_initiation_status'
                );
                $table->index(
                    ['status', 'reconciliation_lease_expires_at', 'id'],
                    'invoice_payment_initiation_lease'
                );
                $table->index(
                    ['gateway_snapshot_id', 'status'],
                    'invoice_payment_initiation_gateway'
                );
                $table->index('provider_reference');
                $table->index('provider_transaction_id');
                $table->unique(
                    [
                        'gateway_snapshot_id',
                        'provider_reference',
                    ],
                    'invoice_payment_provider_reference_unique'
                );
            }
        );
    }

    public function down(): void
    {
        if (
            Schema::hasTable('invoice_payment_initiations')
            && DB::table('invoice_payment_initiations')->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back durable provider payment initiations.'
            );
        }

        Schema::dropIfExists('invoice_payment_initiations');
    }
};
