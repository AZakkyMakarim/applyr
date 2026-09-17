<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // JobStreet Jobs were stored unknown though search serves only active postings; as open,
        // polls refresh them once search stops returning them.
        DB::table('jobs')
            ->where('platform', 'jobstreet')
            ->where('status', 'unknown')
            ->update(['status' => 'open']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Jobs up() opened aren't told apart from ones search stored open since, so they stay open.
    }
};
