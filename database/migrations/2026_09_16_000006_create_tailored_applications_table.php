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
        // Self-contained snapshots: rendering never reads live MasterProfile or Job data.
        Schema::create('tailored_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->json('cv_data');
            $table->json('cover_letter_data');
            $table->string('cv_pdf_path');
            $table->string('cover_letter_pdf_path');
            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreign('current_tailored_application_id')->references('id')->on('tailored_applications')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['current_tailored_application_id']);
        });

        Schema::dropIfExists('tailored_applications');
    }
};
