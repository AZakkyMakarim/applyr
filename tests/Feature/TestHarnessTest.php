<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TestHarnessTest extends TestCase
{
    public function test_unfaked_outbound_http_requests_fail(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://example.com');
    }

    public function test_faked_outbound_http_requests_are_allowed(): void
    {
        Http::fake(['example.com/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://example.com/ping')->json('ok'));
    }

    public function test_queued_work_runs_synchronously(): void
    {
        dispatch(fn () => DB::table('cache')->insert(['key' => 'queued', 'value' => '1', 'expiration' => 0]));

        $this->assertTrue(DB::table('cache')->where('key', 'queued')->exists());
    }

    public function test_the_queue_uses_queue_jobs_so_jobs_holds_domain_jobs(): void
    {
        $this->assertSame('queue_jobs', config('queue.connections.database.table'));
        $this->assertTrue(Schema::hasTable('queue_jobs'));
        $this->assertTrue(Schema::hasColumn('jobs', 'external_id'));
    }

    // Both tests write one row; whichever runs second would see two rows if the database leaked.
    public function test_database_starts_empty_for_each_test_first(): void
    {
        $this->insertCacheRowAndAssertItIsTheOnlyOne();
    }

    public function test_database_starts_empty_for_each_test_second(): void
    {
        $this->insertCacheRowAndAssertItIsTheOnlyOne();
    }

    private function insertCacheRowAndAssertItIsTheOnlyOne(): void
    {
        DB::table('cache')->insert(['key' => 'harness', 'value' => '1', 'expiration' => 0]);

        $this->assertSame(1, DB::table('cache')->count());
    }
}
