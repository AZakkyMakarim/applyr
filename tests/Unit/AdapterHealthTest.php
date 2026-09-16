<?php

namespace Tests\Unit;

use App\Adapters\Exceptions\AdapterException;
use App\Adapters\Exceptions\AntiBotBlockedException;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\Exceptions\TransportException;
use App\Enums\AdapterStatus;
use App\Enums\FailureCategory;
use App\Enums\Platform;
use App\Models\AdapterHealth;
use Tests\TestCase;

class AdapterHealthTest extends TestCase
{
    public function test_a_platform_starts_healthy_with_empty_counters(): void
    {
        $health = AdapterHealth::for(Platform::Glints);

        $this->assertSame(AdapterStatus::Healthy, $health->status);
        $this->assertSame(0, $health->broken_failures);
        $this->assertSame(0, $health->transport_failures);
        $this->assertSame([], $health->recent_failures);
        $this->assertSame(1, AdapterHealth::count());
        $this->assertTrue(AdapterHealth::for(Platform::Glints)->is($health));
    }

    public function test_each_exception_is_recorded_under_its_category(): void
    {
        $cases = [
            [new AntiBotBlockedException(Platform::Glints, 'HTTP 403: Just a moment...', 403), FailureCategory::AntiBot],
            [new ApiErrorException(Platform::Glints, 'GraphQL error BAD_USER_INPUT', 200), FailureCategory::ApiError],
            [new ShapeDriftException(Platform::Glints, 'Posting field title is missing.', 200), FailureCategory::ShapeDrift],
            [new TransportException(Platform::Glints, 'cURL error 28: timed out'), FailureCategory::Transport],
        ];

        foreach ($cases as [$exception, $category]) {
            $health = AdapterHealth::for(Platform::Glints);
            $health->recordFailure($exception);

            $this->assertSame($category, $health->fresh()->last_failure_category);
            $this->assertSame($exception->statusCode, $health->fresh()->last_failure_status_code);
            $this->assertSame($exception->getMessage(), $health->fresh()->last_failure_message);
        }
    }

    public function test_the_broken_track_pauses_at_three_consecutive_failures_across_categories(): void
    {
        $health = AdapterHealth::for(Platform::Glints);

        $this->assertFalse($health->recordFailure($this->antiBot()));
        $this->assertSame(AdapterStatus::Failing, $health->status);
        $this->assertFalse($health->recordFailure(new ApiErrorException(Platform::Glints, 'GraphQL error', 200)));
        $this->assertSame(AdapterStatus::Failing, $health->status);
        $this->assertTrue($health->recordFailure(new ShapeDriftException(Platform::Glints, 'drift', 200)));

        $health->refresh();
        $this->assertSame(AdapterStatus::Paused, $health->status);
        $this->assertSame(3, $health->broken_failures);
        $this->assertSame(0, $health->transport_failures);
    }

    public function test_the_transport_track_pauses_at_six_consecutive_failures(): void
    {
        $health = AdapterHealth::for(Platform::JobStreet);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($health->recordFailure($this->transport()));
            $this->assertSame(AdapterStatus::Failing, $health->status);
        }

        $this->assertTrue($health->recordFailure($this->transport()));
        $this->assertSame(AdapterStatus::Paused, $health->fresh()->status);
        $this->assertSame(6, $health->fresh()->transport_failures);
    }

    public function test_a_failure_on_one_track_does_not_reset_the_other(): void
    {
        $health = AdapterHealth::for(Platform::Glints);

        $health->recordFailure($this->antiBot());
        $health->recordFailure($this->antiBot());
        $health->recordFailure($this->transport());

        $this->assertSame(2, $health->broken_failures);
        $this->assertSame(1, $health->transport_failures);
        $this->assertTrue($health->recordFailure($this->antiBot()));
    }

    public function test_only_the_failure_that_crosses_a_threshold_reports_the_pause(): void
    {
        $health = AdapterHealth::for(Platform::Glints);

        $health->recordFailure($this->antiBot());
        $health->recordFailure($this->antiBot());
        $this->assertTrue($health->recordFailure($this->antiBot()));

        $this->assertFalse($health->recordFailure($this->antiBot()));
        $this->assertFalse($health->recordFailure($this->transport()));
        $this->assertSame(AdapterStatus::Paused, $health->status);
    }

    public function test_the_streak_keeps_its_first_and_last_failure_times(): void
    {
        $this->freezeSecond();
        $health = AdapterHealth::for(Platform::Glints);
        $start = now();

        $health->recordFailure($this->antiBot());
        $this->travel(1)->hour();
        $health->recordFailure($this->transport());

        $health->refresh();
        $this->assertEquals($start, $health->first_failure_at);
        $this->assertEquals(now(), $health->last_failure_at);
    }

    public function test_a_clean_run_resets_both_counters_and_records_last_success(): void
    {
        $this->freezeSecond();
        $health = AdapterHealth::for(Platform::Glints);
        $health->recordFailure($this->antiBot());
        $health->recordFailure($this->transport());
        $this->travel(1)->hour();

        $health->recordSuccess();

        $health->refresh();
        $this->assertSame(AdapterStatus::Healthy, $health->status);
        $this->assertSame(0, $health->broken_failures);
        $this->assertSame(0, $health->transport_failures);
        $this->assertNull($health->first_failure_at);
        $this->assertNull($health->last_failure_at);
        $this->assertEquals(now(), $health->last_success_at);

        // A new streak starts from the next failure.
        $this->travel(1)->hour();
        $health->recordFailure($this->antiBot());
        $this->assertEquals(now(), $health->fresh()->first_failure_at);
    }

    public function test_the_recent_failure_log_is_bounded_newest_last(): void
    {
        $this->freezeSecond();
        $health = AdapterHealth::for(Platform::Glints);

        for ($i = 0; $i < AdapterHealth::RECENT_FAILURE_LIMIT + 5; $i++) {
            $health->recordFailure($i % 2 === 0 ? $this->transport() : $this->antiBot());
            $health->recordSuccess();
            $this->travel(1)->minute();
        }

        $log = $health->fresh()->recent_failures;
        $this->assertCount(AdapterHealth::RECENT_FAILURE_LIMIT, $log);
        $this->assertSame(['category' => 'transport', 'at' => now()->subMinute()->toIso8601String()], end($log));
    }

    public function test_long_error_messages_are_trimmed_to_a_snippet(): void
    {
        $health = AdapterHealth::for(Platform::Glints);

        $health->recordFailure(new ApiErrorException(Platform::Glints, str_repeat('x', 2000), 400));

        $this->assertLessThanOrEqual(500, mb_strlen($health->fresh()->last_failure_message));
    }

    public function test_platforms_keep_independent_health(): void
    {
        $glints = AdapterHealth::for(Platform::Glints);
        $glints->recordFailure($this->antiBot());
        $glints->recordFailure($this->antiBot());
        $glints->recordFailure($this->antiBot());

        $jobStreet = AdapterHealth::for(Platform::JobStreet);

        $this->assertSame(AdapterStatus::Healthy, $jobStreet->status);
        $this->assertSame(0, $jobStreet->broken_failures);
    }

    private function antiBot(): AdapterException
    {
        return new AntiBotBlockedException(Platform::Glints, 'HTTP 403: Just a moment...', 403);
    }

    private function transport(): AdapterException
    {
        return new TransportException(Platform::Glints, 'cURL error 7: connection refused');
    }
}
