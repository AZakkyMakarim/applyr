<?php

namespace Tests\Feature;

use App\Enums\AdapterStatus;
use App\Enums\Platform;
use App\Jobs\PollAdapter;
use App\Models\AdapterHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdaptersPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    public function test_the_page_lists_both_adapters_as_healthy_before_any_run(): void
    {
        $this->get('/adapters')
            ->assertOk()
            ->assertSeeInOrder(['Glints', 'Healthy', 'JobStreet', 'Healthy'])
            ->assertSee('Never')
            ->assertDontSee('Resume');

        // Viewing the page doesn't create health rows.
        $this->assertDatabaseCount('adapter_health', 0);
    }

    public function test_the_dashboard_navigation_links_to_the_adapters_page(): void
    {
        $this->get('/')->assertSee('href="'.route('adapters.index').'"', escape: false);
    }

    public function test_a_failing_adapter_shows_its_count_against_the_threshold_of_its_track(): void
    {
        $this->health(Platform::Glints, [
            'status' => AdapterStatus::Failing,
            'broken_failures' => 2,
            'last_failure_category' => 'anti_bot',
        ]);
        $this->health(Platform::JobStreet, [
            'status' => AdapterStatus::Failing,
            'transport_failures' => 4,
            'last_failure_category' => 'transport',
        ]);

        $this->get('/adapters')
            ->assertOk()
            ->assertSeeInOrder(['Glints', 'Failing 2 of 3', 'JobStreet', 'Failing 4 of 6'])
            ->assertDontSee('Resume');
    }

    public function test_last_success_last_failure_details_and_recent_failures_are_shown(): void
    {
        $this->health(Platform::Glints, [
            'status' => AdapterStatus::Failing,
            'broken_failures' => 1,
            'last_success_at' => now()->subDay(),
            'last_failure_at' => now()->subHour(),
            'last_failure_category' => 'api_error',
            'last_failure_status_code' => 400,
            'last_failure_message' => 'GraphQL error: BAD_USER_INPUT',
            'recent_failures' => [
                ['category' => 'transport', 'at' => now()->subDays(2)->toIso8601String()],
                ['category' => 'api_error', 'at' => now()->subHour()->toIso8601String()],
            ],
        ]);

        $this->get('/adapters')
            ->assertOk()
            ->assertSeeInOrder([
                'Glints',
                'Last success', now()->subDay()->format('Y-m-d H:i:s T'),
                'Last failure', 'API error', '400', 'GraphQL error: BAD_USER_INPUT', now()->subHour()->format('Y-m-d H:i:s T'),
                // Newest first.
                'Recent failures',
                'API error', now()->subHour()->format('Y-m-d H:i:s T'),
                'transport', now()->subDays(2)->format('Y-m-d H:i:s T'),
                'JobStreet',
            ]);
    }

    public function test_only_a_paused_adapter_offers_resume(): void
    {
        $this->health(Platform::JobStreet, [
            'status' => AdapterStatus::Paused,
            'broken_failures' => 3,
            'last_failure_category' => 'shape_drift',
        ]);

        $this->get('/adapters')
            ->assertOk()
            ->assertSeeInOrder(['Glints', 'Healthy', 'JobStreet', 'Paused', 'Resume'])
            ->assertSee(route('adapters.resume', Platform::JobStreet))
            ->assertDontSee(route('adapters.resume', Platform::Glints));
    }

    public function test_resume_resets_the_counters_and_sets_the_adapter_healthy_without_running_it(): void
    {
        Http::fake();
        Queue::fake();
        $recentFailures = [['category' => 'anti_bot', 'at' => now()->subHour()->toIso8601String()]];
        $health = $this->health(Platform::Glints, [
            'status' => AdapterStatus::Paused,
            'broken_failures' => 3,
            'transport_failures' => 2,
            'first_failure_at' => now()->subHours(3),
            'last_failure_at' => now()->subHour(),
            'last_failure_category' => 'anti_bot',
            'last_failure_status_code' => 403,
            'last_failure_message' => 'HTTP 403',
            'recent_failures' => $recentFailures,
        ]);

        $this->patch('/adapters/glints/resume')
            ->assertRedirect('/adapters')
            ->assertSessionHas('status', 'Glints Adapter resumed.');

        $health->refresh();
        $this->assertSame(AdapterStatus::Healthy, $health->status);
        $this->assertSame(0, $health->broken_failures);
        $this->assertSame(0, $health->transport_failures);
        $this->assertNull($health->first_failure_at);
        // Resuming isn't a successful run, and the failure history stays for reference.
        $this->assertNull($health->last_success_at);
        $this->assertSame('HTTP 403', $health->last_failure_message);
        $this->assertSame($recentFailures, $health->recent_failures);

        Http::assertNothingSent();
        Queue::assertNothingPushed();

        $this->get('/adapters')->assertSeeInOrder(['Glints', 'Healthy', 'JobStreet'])->assertDontSee('Resume');
    }

    public function test_the_next_scheduled_poll_runs_a_resumed_adapter_again(): void
    {
        Queue::fake();
        $this->health(Platform::Glints, ['status' => AdapterStatus::Paused, 'broken_failures' => 3]);

        $this->artisan('applyr:poll')->assertSuccessful();
        Queue::assertNotPushed(PollAdapter::class, fn (PollAdapter $job) => $job->platform === Platform::Glints);

        $this->patch('/adapters/glints/resume');
        $this->artisan('applyr:poll')->assertSuccessful();

        Queue::assertPushed(PollAdapter::class, fn (PollAdapter $job) => $job->platform === Platform::Glints);
    }

    public function test_resuming_an_adapter_that_is_not_paused_changes_nothing(): void
    {
        $health = $this->health(Platform::Glints, ['status' => AdapterStatus::Failing, 'broken_failures' => 2]);

        $this->patch('/adapters/glints/resume')->assertRedirect('/adapters');

        $health->refresh();
        $this->assertSame(AdapterStatus::Failing, $health->status);
        $this->assertSame(2, $health->broken_failures);
    }

    public function test_an_unknown_platform_is_not_found(): void
    {
        $this->patch('/adapters/indeed/resume')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function health(Platform $platform, array $attributes): AdapterHealth
    {
        return tap(AdapterHealth::for($platform)->forceFill($attributes))->save();
    }
}
