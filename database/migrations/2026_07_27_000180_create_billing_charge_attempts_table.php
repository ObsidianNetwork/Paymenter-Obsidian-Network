<?php

use App\Models\BillingAgreement;
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
        Schema::create('billing_charge_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Invoice::class)
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignIdFor(BillingAgreement::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedBigInteger('billing_agreement_snapshot_id');
            $table->string('billing_agreement_reference');
            $table->foreignIdFor(Gateway::class)
                ->nullable()
                ->constrained('extensions')
                ->nullOnDelete();
            $table->unsignedBigInteger('gateway_snapshot_id');
            $table->string('gateway_extension', 100);
            $table->string('provider_customer_reference')->nullable();
            $table->string('purpose', 32);
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_status', 100)->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('metric_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['status', 'lease_expires_at', 'id']);
            $table->index([
                'billing_agreement_snapshot_id',
                'status',
            ], 'billing_charge_agreement_status');
            $table->index(
                ['gateway_snapshot_id', 'status'],
                'billing_charge_gateway_status'
            );
            $table->index('provider_reference');
            $table->index('provider_transaction_id');
            $table->unique(
                [
                    'gateway_snapshot_id',
                    'provider_reference',
                ],
                'billing_charge_provider_reference_unique'
            );
            $table->unique(
                [
                    'gateway_snapshot_id',
                    'provider_transaction_id',
                ],
                'billing_charge_provider_transaction_unique'
            );
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('billing_charge_attempts')
            && DB::table('billing_charge_attempts')->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back durable billing charge evidence. Reconcile and retain the existing attempts.'
            );
        }

        Schema::dropIfExists('billing_charge_attempts');
    }
};
