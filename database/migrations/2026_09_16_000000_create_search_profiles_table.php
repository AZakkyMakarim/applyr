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
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('keyword');
            $table->string('location')->nullable();
            $table->string('country_code');
            $table->unsignedSmallInteger('min_experience_years')->nullable();
            $table->unsignedSmallInteger('max_experience_years')->nullable();
            $table->enum('post_date_range', ['PAST_24_HOURS', 'PAST_WEEK', 'PAST_MONTH', 'ANY_TIME']);
            $table->enum('work_arrangement', ['remote', 'onsite', 'hybrid', 'any']);
            $table->enum('job_type', ['full_time', 'part_time', 'contract', 'internship', 'any']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_profiles');
    }
};
