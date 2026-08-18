<?php

use App\Support\LegacyServiceUpgradeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The original lifecycle reconciliation is already recorded as applied on
     * existing installations. Run the now-complete pending/awaiting scan under
     * a new migration identity so those databases receive the same fail-closed
     * transition as fresh installs.
     */
    public function up(): void
    {
        DB::transaction(
            fn () => LegacyServiceUpgradeMigration::reconcile(),
            5
        );
    }

    public function down(): void
    {
        $decision = DB::table('service_upgrades')
            ->where(function ($query): void {
                $query->whereNotNull('legacy_refund_only_at')
                    ->orWhere(
                        'last_error',
                        'like',
                        'Legacy % upgrade retired%'
                    )
                    ->orWhere(
                        'last_error',
                        'like',
                        'Legacy upgrade has payment activity%'
                    );
            })
            ->orderBy('id')
            ->value('id');
        if ($decision !== null) {
            throw new RuntimeException(
                "Cannot roll back the outstanding legacy-upgrade reconciliation because service upgrade {$decision} contains a terminal migration decision or refund obligation."
            );
        }

        $attentionInvoice = DB::table('invoices')
            ->whereNotNull('payment_attention_required_at')
            ->where(
                'payment_attention_reason',
                'like',
                'Legacy upgrade has payment activity%'
            )
            ->orderBy('id')
            ->value('id');
        if ($attentionInvoice !== null) {
            throw new RuntimeException(
                "Cannot roll back the outstanding legacy-upgrade reconciliation because invoice {$attentionInvoice} retains payment-attention evidence."
            );
        }
    }
};
