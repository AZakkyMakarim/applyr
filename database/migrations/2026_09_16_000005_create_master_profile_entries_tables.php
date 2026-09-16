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
        // Each row's id is the entry_id tailoring refers to. Dates are month and year, stored as the 1st.
        Schema::create('experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_profile_id')->constrained('master_profile')->cascadeOnDelete();
            $table->string('title');
            $table->string('company');
            $table->string('location')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('employment_type')->nullable();
            $table->text('description')->nullable();
            $table->json('achievements');
            $table->json('skills_used');
            $table->timestamps();
        });

        Schema::create('educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_profile_id')->constrained('master_profile')->cascadeOnDelete();
            $table->string('institution');
            $table->string('degree')->nullable();
            $table->string('field_of_study')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_profile_id')->constrained('master_profile')->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->json('tech_stack');
            $table->string('link', 2048)->nullable();
            $table->text('description')->nullable();
            $table->json('achievements');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('educations');
        Schema::dropIfExists('experiences');
    }
};
