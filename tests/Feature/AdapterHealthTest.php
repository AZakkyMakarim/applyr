<?php

namespace Tests\Feature;

use App\Enums\AdapterStatus;
use App\Enums\FailureCategory;
use App\Enums\Platform;
use App\Models\AdapterHealth;
use App\Models\SearchProfile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AdapterHealthTest extends TestCase
{
    private const TELEGRAM_URL = 'https://api.telegram.org/botbot-token/sendMessage';

    /** @var list<mixed> */
    private array $glintsResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);
        $this->freezeSecond();
        config(['services.telegram.bot_token' => 'bot-token', 'services.telegram.chat_id' => '12345']);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);
    }

    public function test_a_cloudflare_challenge_is_recorded_as_anti_bot_without_failing_the_poll(): void
    {
        $this->fakePlatforms(glints: $this->cloudflareChallenge(Platform::Glints));

        $this->artisan('applyr:poll')->assertSuccessful();

        $glints = AdapterHealth::for(Platform::Glints);
        $this->assertSame(AdapterStatus::Failing, $glints->status);
        $this->assertSame(FailureCategory::AntiBot, $glints->last_failure_category);
        $this->assertSame(403, $glints->last_failure_status_code);
        $this->assertStringContainsString('HTTP 403', $glints->last_failure_message);
        $this->assertSame(1, $glints->broken_failures);
        $this->assertEquals(now(), $glints->first_failure_at);
        $this->assertSame([['category' => 'anti_bot', 'at' => now()->toIso8601String()]], $glints->recent_failures);

        // JobStreet ran cleanly in the same poll.
        $jobStreet = AdapterHealth::for(Platform::JobStreet);
        $this->assertSame(AdapterStatus::Healthy, $jobStreet->status);
        $this->assertEquals(now(), $jobStreet->last_success_at);
    }

    public function test_graphql_errors_and_drifted_results_are_recorded_under_their_categories(): void
    {
        $this->fakePlatforms(
            glints: Http::response(['errors' => [['message' => 'Bad input', 'extensions' => ['code' => 'BAD_USER_INPUT']]]]),
            jobStreet: Http::response(['data' => ['jobSearchV7' => null]]),
        );

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(FailureCategory::ApiError, AdapterHealth::for(Platform::Glints)->last_failure_category);
        $this->assertSame(FailureCategory::ShapeDrift, AdapterHealth::for(Platform::JobStreet)->last_failure_category);
    }

    public function test_three_broken_runs_pause_the_adapter_and_send_exactly_one_alert(): void
    {
        $this->fakePlatforms(glints: $this->cloudflareChallenge(Platform::Glints));
        $firstFailureAt = now();

        $this->artisan('applyr:poll')->assertSuccessful();
        $this->travel(1)->hour();
        $this->artisan('applyr:poll')->assertSuccessful();
        Http::assertNotSent(fn (Request $request) => $request->url() === self::TELEGRAM_URL);
        $this->travel(1)->hour();
        $this->artisan('applyr:poll')->assertSuccessful();

        $glints = AdapterHealth::for(Platform::Glints);
        $this->assertSame(AdapterStatus::Paused, $glints->status);
        $this->assertSame(3, $glints->broken_failures);

        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('12345', $alerts[0]['chat_id']);
        $this->assertSame(implode("\n", [
            'Applyr: Glints Adapter paused',
            '',
            'Failure: anti-bot blocked (3/3 consecutive failed runs)',
            'First failure: '.$firstFailureAt->format('Y-m-d H:i:s T'),
            'Last failure: '.now()->format('Y-m-d H:i:s T'),
            'Status code: 403',
            'Error: '.$glints->last_failure_message,
            '',
            'The Adapter is paused and will not be polled until it is resumed.',
        ]), $alerts[0]['text']);

        // Paused Adapters are skipped, so no further requests, failures or reminders follow.
        $glintsRequests = $this->glintsRequestCount();
        $this->travel(1)->hour();
        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame($glintsRequests, $this->glintsRequestCount());
        $this->assertSame(3, AdapterHealth::for(Platform::Glints)->broken_failures);
        $this->assertCount(1, $this->telegramAlerts());
    }

    public function test_mixed_broken_categories_count_toward_the_same_pause(): void
    {
        $this->fakePlatforms(glints: fn () => array_shift($this->glintsResponses));
        $this->glintsResponses = [
            $this->cloudflareChallenge(Platform::Glints),
            Http::response(['errors' => [['message' => 'Bad input']]]),
            Http::response(['data' => ['searchJobsV3' => ['hasMore' => false]]]),
        ];

        foreach ([1, 2, 3] as $run) {
            $this->artisan('applyr:poll')->assertSuccessful();
        }

        $this->assertTrue(AdapterHealth::for(Platform::Glints)->isPaused());
        $this->assertCount(1, $this->telegramAlerts());
        $this->assertStringContainsString('Failure: shape drift (3/3 consecutive failed runs)', $this->telegramAlerts()[0]['text']);
    }

    public function test_transport_retries_within_a_run_count_as_one_failure(): void
    {
        $this->fakePlatforms(glints: Http::failedConnection('cURL error 7: connection refused'));
        $start = now();

        $this->artisan('applyr:poll')->assertSuccessful();

        $glints = AdapterHealth::for(Platform::Glints);
        $this->assertSame(1, $glints->transport_failures);
        $this->assertSame(FailureCategory::Transport, $glints->last_failure_category);
        $this->assertSame(3, $this->glintsRequestCount());
        // Backoff moved the clock rather than blocking: 30s then 120s before the run gave up.
        $this->assertEquals($start->copy()->addSeconds(150), $glints->last_failure_at);
    }

    public function test_a_server_error_that_clears_on_retry_is_a_clean_run(): void
    {
        $this->fakePlatforms(glints: Http::sequence()
            ->push('Bad Gateway', 502)
            ->pushResponse($this->noPostingsFound(Platform::Glints)));

        $this->artisan('applyr:poll')->assertSuccessful();

        $glints = AdapterHealth::for(Platform::Glints);
        $this->assertSame(AdapterStatus::Healthy, $glints->status);
        $this->assertSame(0, $glints->transport_failures);
        $this->assertEquals(now(), $glints->last_success_at);
        $this->assertSame(2, $this->glintsRequestCount());
    }

    public function test_six_transport_failed_runs_pause_the_adapter(): void
    {
        $this->fakePlatforms(glints: Http::response('Service Unavailable', 503));

        for ($run = 1; $run <= 5; $run++) {
            $this->artisan('applyr:poll')->assertSuccessful();
            $this->travel(1)->hour();
        }

        $this->assertSame(AdapterStatus::Failing, AdapterHealth::for(Platform::Glints)->status);
        $this->assertSame([], $this->telegramAlerts());

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(AdapterStatus::Paused, AdapterHealth::for(Platform::Glints)->status);
        $this->assertCount(1, $this->telegramAlerts());
        $this->assertStringContainsString('Failure: transport (6/6 consecutive failed runs)', $this->telegramAlerts()[0]['text']);
        $this->assertStringContainsString('Status code: 503', $this->telegramAlerts()[0]['text']);
    }

    public function test_a_clean_run_resets_the_failure_streak(): void
    {
        $this->fakePlatforms(glints: fn () => array_shift($this->glintsResponses));
        $this->glintsResponses = [
            $this->cloudflareChallenge(Platform::Glints),
            $this->cloudflareChallenge(Platform::Glints),
            $this->noPostingsFound(Platform::Glints),
            $this->cloudflareChallenge(Platform::Glints),
            $this->cloudflareChallenge(Platform::Glints),
        ];

        foreach ([1, 2, 3, 4, 5] as $run) {
            $this->artisan('applyr:poll')->assertSuccessful();
        }

        $glints = AdapterHealth::for(Platform::Glints);
        $this->assertSame(AdapterStatus::Failing, $glints->status);
        $this->assertSame(2, $glints->broken_failures);
        $this->assertSame([], $this->telegramAlerts());
    }

    public function test_one_paused_platform_does_not_stop_the_other_from_polling(): void
    {
        $this->fakePlatforms(glints: $this->cloudflareChallenge(Platform::Glints));

        foreach ([1, 2, 3] as $run) {
            $this->artisan('applyr:poll')->assertSuccessful();
        }

        $jobStreetRequests = $this->jobStreetRequestCount();
        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertGreaterThan($jobStreetRequests, $this->jobStreetRequestCount());
        $this->assertSame(AdapterStatus::Healthy, AdapterHealth::for(Platform::JobStreet)->status);
    }

    public function test_a_failed_alert_does_not_undo_the_pause(): void
    {
        $this->fakePlatforms(glints: $this->cloudflareChallenge(Platform::Glints), telegram: Http::response(['ok' => false], 500));

        foreach ([1, 2, 3] as $run) {
            $this->artisan('applyr:poll')->assertSuccessful();
        }

        $this->assertTrue(AdapterHealth::for(Platform::Glints)->isPaused());
    }

    private function fakePlatforms(mixed $glints = null, mixed $jobStreet = null, mixed $telegram = null): void
    {
        Http::fake(array_filter([
            'api.telegram.org/*' => $telegram ?? Http::response(['ok' => true]),
            'glints.com/*' => $glints,
            'id.jobstreet.com/*' => $jobStreet,
        ]));

        // Platforms given no response find nothing.
        $this->fakeAdaptersFindingNothing();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function telegramAlerts(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::TELEGRAM_URL)
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function glintsRequestCount(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'glints.com'))->count();
    }

    private function jobStreetRequestCount(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'jobstreet.com'))->count();
    }
}
