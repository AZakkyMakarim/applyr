<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->enum('salary_period', ['monthly', 'yearly', 'weekly', 'daily', 'hourly', 'per_project', 'unspecified'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The narrower enum can't hold these, so they fall back to unspecified and aren't restored by up().
        DB::table('jobs')
            ->whereIn('salary_period', ['weekly', 'daily', 'hourly', 'per_project'])
            ->update(['salary_period' => 'unspecified']);

        Schema::table('jobs', function (Blueprint $table) {
            $table->enum('salary_period', ['monthly', 'yearly', 'unspecified'])->nullable()->change();
        });
    }
};
