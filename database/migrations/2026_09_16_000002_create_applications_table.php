<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['pending_tailoring', 'tailoring_failed', 'needs_review', 'applied', 'rejected'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', self::STATUSES);
            // Foreign key is added with the tailored_applications table.
            $table->unsignedBigInteger('current_tailored_application_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->enum('previous_status', self::STATUSES)->nullable();
            $table->boolean('edited_by_user')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
