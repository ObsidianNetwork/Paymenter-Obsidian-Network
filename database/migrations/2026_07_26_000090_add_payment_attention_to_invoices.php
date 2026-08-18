<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable()->change();
            $table->timestamp('payment_attention_required_at')->nullable();
            $table->text('payment_attention_reason')->nullable();
            $table->timestamp('payment_attention_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('due_at')->nullable()->change();
            $table->dropColumn([
                'payment_attention_required_at',
                'payment_attention_reason',
                'payment_attention_alerted_at',
            ]);
        });
    }
};
