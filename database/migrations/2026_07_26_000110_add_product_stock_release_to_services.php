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
            $table->timestamp('product_stock_released_at')
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        if (
            DB::table('services')
                ->whereNotNull('product_stock_released_at')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back durable product-stock release evidence.'
            );
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('product_stock_released_at');
        });
    }
};
