<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per platform.
        Schema::create('adapter_health', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['glints', 'jobstreet'])->unique();
            $table->enum('status', ['healthy', 'failing', 'paused'])->default('healthy');
            $table->unsignedInteger('broken_failures')->default(0);
            $table->unsignedInteger('transport_failures')->default(0);
            $table->timestamp('first_failure_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->enum('last_failure_category', ['anti_bot', 'api_error', 'shape_drift', 'transport'])->nullable();
            $table->unsignedSmallInteger('last_failure_status_code')->nullable();
            $table->text('last_failure_message')->nullable();
            $table->json('recent_failures');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adapter_health');
    }
};
