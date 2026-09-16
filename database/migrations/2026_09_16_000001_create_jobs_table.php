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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['glints', 'jobstreet']);
            $table->string('external_id');
            $table->string('title');
            $table->string('company_name');
            $table->string('location')->nullable();
            $table->string('country_code');
            $table->string('url', 2048);
            $table->longText('description');
            $table->enum('work_arrangement', ['remote', 'onsite', 'hybrid', 'unspecified']);
            $table->enum('job_type', ['full_time', 'part_time', 'contract', 'internship', 'unspecified']);
            $table->unsignedSmallInteger('min_years_experience')->nullable();
            $table->unsignedSmallInteger('max_years_experience')->nullable();
            $table->enum('status', ['open', 'closed', 'expired', 'unknown']);
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->enum('salary_period', ['monthly', 'yearly', 'unspecified'])->nullable();
            $table->timestamp('posted_date');
            $table->json('raw_payload');
            $table->timestamps();

            // The dedup key: a posting is new only if this pair hasn't been seen.
            $table->unique(['platform', 'external_id']);
        });

        Schema::create('job_search_profile_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('matched_at');

            $table->unique(['job_id', 'search_profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_search_profile_matches');
        Schema::dropIfExists('jobs');
    }
};
