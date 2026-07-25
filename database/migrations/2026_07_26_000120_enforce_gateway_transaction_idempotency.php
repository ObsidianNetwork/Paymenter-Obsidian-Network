<?php

use App\Models\InvoiceTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX =
        'invoice_tx_gateway_reference_unique';

    public function up(): void
    {
        if (! Schema::hasTable('invoice_transactions')) {
            return;
        }
        if (Schema::hasColumn(
            'invoice_transactions',
            'gateway_transaction_guard'
        )) {
            throw new \RuntimeException(
                'The gateway transaction guard column already exists without a completed migration. Remove the incomplete column or restore the migration record before retrying.'
            );
        }

        $guards = [];
        DB::table('invoice_transactions')
            ->whereNotNull('transaction_id')
            ->orderBy('id')
            ->chunkById(
                500,
                function ($transactions) use (&$guards): void {
                    foreach ($transactions as $transaction) {
                        $guard =
                            InvoiceTransaction::gatewayTransactionGuard(
                                $transaction->gateway_id,
                                $transaction->transaction_id
                            );
                        if (
                            $guard !== null
                            && array_key_exists($guard, $guards)
                        ) {
                            throw new \RuntimeException(
                                "Gateway transaction evidence {$transaction->id} duplicates invoice transaction {$guards[$guard]}; reconcile these financial records before migrating."
                            );
                        }
                        if ($guard !== null) {
                            $guards[$guard] = (int) $transaction->id;
                        }
                    }
                },
                'id'
            );

        Schema::table(
            'invoice_transactions',
            function (Blueprint $table): void {
                $table->char(
                    'gateway_transaction_guard',
                    64
                )->nullable()->after('transaction_id');
            }
        );

        DB::table('invoice_transactions')
            ->whereNotNull('transaction_id')
            ->orderBy('id')
            ->chunkById(
                500,
                function ($transactions): void {
                    foreach ($transactions as $transaction) {
                        DB::table('invoice_transactions')
                            ->where('id', $transaction->id)
                            ->update([
                                'gateway_transaction_guard' =>
                                    InvoiceTransaction::gatewayTransactionGuard(
                                        $transaction->gateway_id,
                                        $transaction->transaction_id
                                    ),
                            ]);
                    }
                },
                'id'
            );

        Schema::table(
            'invoice_transactions',
            function (Blueprint $table): void {
                $table->unique(
                    'gateway_transaction_guard',
                    self::INDEX
                );
            }
        );
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('invoice_transactions')
            || ! Schema::hasColumn(
                'invoice_transactions',
                'gateway_transaction_guard'
            )
        ) {
            return;
        }

        Schema::table(
            'invoice_transactions',
            function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
                $table->dropColumn('gateway_transaction_guard');
            }
        );
    }
};
