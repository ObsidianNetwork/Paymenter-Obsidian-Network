<?php

use App\Support\LegacyServiceUpgradeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->json('source_snapshot')->nullable()->after('type');
            $table->json('target_snapshot')->nullable()->after('source_snapshot');
            $table->string('source_fingerprint', 64)->nullable()->after('target_snapshot');
            $table->string('target_fingerprint', 64)->nullable()->after('source_fingerprint');
            $table->decimal('quoted_amount', 17, 2)->nullable()->after('target_fingerprint');
            $table->string('currency_code', 3)->nullable()->after('quoted_amount');
            $table->decimal('credit_amount', 17, 2)->default(0)->after('currency_code');
            $table->unsignedBigInteger('active_service_guard_id')->nullable()->after('credit_amount');
            $table->unsignedInteger('provisioning_attempts')->default(0)->after('active_service_guard_id');
            $table->text('last_error')->nullable()->after('provisioning_attempts');
            $table->timestamp('paid_at')->nullable()->after('last_error');
            $table->timestamp('provisioning_started_at')->nullable()->after('paid_at');
            $table->timestamp('failed_at')->nullable()->after('provisioning_started_at');
            $table->timestamp('failure_alerted_at')->nullable()->after('failed_at');
            $table->timestamp('completed_at')->nullable()->after('failure_alerted_at');
            $table->timestamp('credit_applied_at')->nullable()->after('completed_at');

            $table->unique('active_service_guard_id', 'service_upgrades_active_service_unique');
            $table->index('status', 'service_upgrades_status_idx');
        });

        LegacyServiceUpgradeMigration::reconcile();
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('service_upgrades')
            ->where('status', 'awaiting_payment')
            ->update(['status' => 'pending']);

        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->dropUnique('service_upgrades_active_service_unique');
            $table->dropIndex('service_upgrades_status_idx');
            $table->dropColumn([
                'source_snapshot',
                'target_snapshot',
                'source_fingerprint',
                'target_fingerprint',
                'quoted_amount',
                'currency_code',
                'credit_amount',
                'active_service_guard_id',
                'provisioning_attempts',
                'last_error',
                'paid_at',
                'provisioning_started_at',
                'failed_at',
                'failure_alerted_at',
                'completed_at',
                'credit_applied_at',
            ]);
        });
    }
};
