<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_job_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Service::class)
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->string('action', 20);
            $table->string('expected_status', 40);
            $table->uuid('dispatch_token')->unique();
            $table->boolean('send_notification')->default(true);
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['available_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_job_dispatches');
    }
};
