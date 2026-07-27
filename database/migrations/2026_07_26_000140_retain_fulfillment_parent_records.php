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
            $table->dropForeign(['order_id']);
            $table->dropForeign(['user_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::table('credits', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'services' => 'service fulfillment',
            'orders' => 'order history',
            'invoices' => 'invoice/payment history',
            'credits' => 'customer credit history',
        ] as $table => $description) {
            $id = DB::table($table)->orderBy('id')->value('id');
            if ($id !== null) {
                throw new RuntimeException(
                    "Cannot restore cascading parent deletion while {$description} exists ({$table} row {$id}). Terminate/anonymize durable records through the supported lifecycle instead."
                );
            }
        }

        Schema::table('credits', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['user_id']);
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
