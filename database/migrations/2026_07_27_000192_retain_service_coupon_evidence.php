<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('services', function (Blueprint $table): void {
            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
        });
        Schema::table('services', function (Blueprint $table): void {
            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
                ->nullOnDelete();
        });
    }
};
