<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\FailureCategory;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\JobTypeFilter;
use App\Enums\Platform;
use App\Enums\PostDateRange;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Enums\WorkArrangementFilter;
use App\Models\AdapterHealth;
use App\Models\Application;
use App\Models\Job;
use App\Models\SearchProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GlintsPollingTest extends TestCase
{
    private const SOFTWARE_ENGINEER_ID = 'bc932c0b-3749-4e8c-8195-a11831888002';

    private const REMOTE_FULLSTACK_ID = '7eab7cb1-f34e-46ae-a81d-de2e55622b90';

    private const HYBRID_INTERNSHIP_ID = 'a0c1f274-6977-4957-810d-0b733589e298';

    /** @var array<int, array<string, mixed>> */
    private array $searchPages = [];

    private int $searchCalls = 0;

    private string $locationResponse = 'search-locations.json';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // The poll runs every Adapter; JobStreet finds nothing so only Glints Jobs are stored.
        Http::fake(['id.jobstreet.com/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/JobStreet/search-jobs-no-results.json')),
        )]);
    }

    public function test_polling_normalizes_a_glints_result_into_a_job(): void
    {
        $this->fakeGlints();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $job = Job::where('platform', 'glints')->where('external_id', self::SOFTWARE_ENGINEER_ID)->sole();

        $this->assertSame('software engineer', $job->title);
        $this->assertSame('PT. BEONE OPTIMA SOLUSI', $job->company_name);
        $this->assertSame('Cipondoh, Tangerang, Banten', $job->location);
        $this->assertSame('ID', $job->country_code);
        $this->assertSame('https://glints.com/id/opportunities/jobs/'.self::SOFTWARE_ENGINEER_ID, $job->url);
        $this->assertSame('onsite', $job->work_arrangement->value);
        $this->assertSame('contract', $job->job_type->value);
        $this->assertSame(1, $job->min_years_experience);
        $this->assertSame(3, $job->max_years_experience);
        $this->assertSame('open', $job->status->value);
        $this->assertEquals(5500000, $job->salary_min);
        $this->assertEquals(8000000, $job->salary_max);
        $this->assertSame('IDR', $job->salary_currency);
        $this->assertSame('monthly', $job->salary_period->value);
        $this->assertSame('2026-07-16 16:28:56', $job->posted_date->utc()->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith(
            "Qualifications\n- Minimal SMK/D3/S1 dari jurusan Teknik Informatika",
            $job->description,
        );
        $this->assertStringContainsString("\nJob Description\n- Mengembangkan, memelihara", $job->description);
        $this->assertSame($this->fixture('search-jobs.json')['data']['searchJobsV3']['jobsInPage'][0], $job->raw_payload);
    }

    public function test_each_new_job_gets_one_pending_application_and_a_match_for_its_search_profile(): void
    {
        $this->fakeGlints();
        $this->freezeTime();
        $searchProfile = SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(3, Job::count());
        $this->assertSame(3, Application::count());
        $this->assertSame(
            [ApplicationStatus::PendingTailoring],
            Application::pluck('status')->unique()->values()->all(),
        );

        foreach (Job::all() as $job) {
            $this->assertEquals([$searchProfile->id], $job->searchProfiles->modelKeys());
            $this->assertEquals(now(), $job->searchProfiles->first()->pivot->matched_at);
        }
    }

    public function test_normalization_covers_remote_hybrid_internship_and_unreported_salary(): void
    {
        $this->fakeGlints();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $remote = Job::where('external_id', self::REMOTE_FULLSTACK_ID)->sole();
        $this->assertSame(WorkArrangement::Remote, $remote->work_arrangement);
        $this->assertSame(JobType::FullTime, $remote->job_type);
        $this->assertSame('Setiabudi, Jakarta Selatan, DKI Jakarta', $remote->location);
        $this->assertNull($remote->salary_min);
        $this->assertNull($remote->salary_max);
        $this->assertNull($remote->salary_currency);
        $this->assertNull($remote->salary_period);

        $hybrid = Job::where('external_id', self::HYBRID_INTERNSHIP_ID)->sole();
        $this->assertSame(WorkArrangement::Hybrid, $hybrid->work_arrangement);
        $this->assertSame(JobType::Internship, $hybrid->job_type);
        $this->assertSame(0, $hybrid->min_years_experience);
        $this->assertSame(1, $hybrid->max_years_experience);
        $this->assertSame('2025-09-03 07:33:40', $hybrid->posted_date->utc()->format('Y-m-d H:i:s'));
    }

    public function test_search_profile_filters_are_translated_into_the_glints_request(): void
    {
        $this->fakeGlints();
        SearchProfile::factory()->create([
            'keyword' => ['laravel developer', 'php engineer'],
            'location' => 'jakarta selatan',
            'country_code' => 'ID',
            'min_experience_years' => 2,
            'max_experience_years' => 5,
            'post_date_range' => PostDateRange::Past24Hours,
            'work_arrangement' => WorkArrangementFilter::Remote,
            'job_type' => JobTypeFilter::FullTime,
        ]);

        $this->artisan('applyr:poll')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['operationName'] === 'searchHierarchicalLocations'
            && $request['variables'] === ['searchTerm' => 'jakarta selatan', 'countryCode' => 'ID', 'searchType' => 'SEARCH']);

        foreach (['laravel developer', 'php engineer'] as $keyword) {
            Http::assertSent(fn (Request $request) => $request->url() === 'https://glints.com/api/v2/graphql'
                && $request->method() === 'POST'
                && str_contains($request->header('User-Agent')[0], 'Chrome/')
                && $request['operationName'] === 'searchJobs'
                && $request['variables']['data'] === [
                    'CountryCode' => ['ID'],
                    'lastUpdatedAtRange' => 'PAST_24_HOURS',
                    'pageSize' => 50,
                    'LocationIds' => ['078b37b2-e791-4739-958e-c29192e5df3e'],
                    'minYearsOfExperience' => 2,
                    'maxYearsOfExperience' => 5,
                    'workArrangementOptions' => ['REMOTE'],
                    'isRemote' => true,
                    'type' => ['FULL_TIME'],
                    'SearchTerm' => [$keyword],
                    'page' => 1,
                ]);
        }
    }

    public function test_filters_set_to_any_are_left_out_of_the_glints_request(): void
    {
        $this->fakeGlints();
        SearchProfile::factory()->create([
            'keyword' => ['software engineer'],
            'location' => 'Atlantis',
            'post_date_range' => PostDateRange::AnyTime,
            'work_arrangement' => WorkArrangementFilter::Any,
            'job_type' => JobTypeFilter::Any,
        ]);

        $this->artisan('applyr:poll')->assertSuccessful();

        // The location lookup finds no place called Atlantis, so the whole country is searched.
        Http::assertSent(fn (Request $request) => $request['operationName'] === 'searchJobs'
            && $request['variables']['data'] === [
                'CountryCode' => ['ID'],
                'lastUpdatedAtRange' => 'ANY_TIME',
                'pageSize' => 50,
                'SearchTerm' => ['software engineer'],
                'page' => 1,
            ]);
    }

    public function test_a_failed_location_lookup_falls_back_to_searching_the_whole_country(): void
    {
        $this->locationResponse = 'graphql-validation-error.json';
        $this->fakeGlints();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => 'Jakarta Selatan']);

        $this->artisan('applyr:poll')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['operationName'] === 'searchJobs'
            && ! array_key_exists('LocationIds', $request['variables']['data']));
        $this->assertSame(3, Job::count());
    }

    public function test_a_job_matched_by_two_search_profiles_gets_one_application_and_two_matches(): void
    {
        $this->fakeGlints();
        [$first, $second] = SearchProfile::factory()->count(2)->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(3, Job::count());
        $this->assertSame(3, Application::count());

        foreach (Job::all() as $job) {
            $this->assertEqualsCanonicalizing([$first->id, $second->id], $job->searchProfiles->modelKeys());
        }
    }

    public function test_re_polling_refreshes_jobs_in_place_without_new_applications(): void
    {
        $this->freezeTime();
        $searchProfile = SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);
        $this->fakeGlints();
        $this->artisan('applyr:poll')->assertSuccessful();
        $firstMatchedAt = now();

        $this->travel(1)->hour();
        $closed = $this->fixture('search-jobs.json');
        $closed['data']['searchJobsV3']['jobsInPage'][0]['status'] = 'CLOSED';
        $closed['data']['searchJobsV3']['jobsInPage'][0]['title'] = 'Software Engineer (Node.js)';
        $this->fakeGlints([$closed]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(3, Job::count());
        $this->assertSame(3, Application::count());

        $job = Job::where('external_id', self::SOFTWARE_ENGINEER_ID)->sole();
        $this->assertSame(JobStatus::Closed, $job->status);
        $this->assertSame('Software Engineer (Node.js)', $job->title);
        $this->assertSame('CLOSED', $job->raw_payload['status']);
        $this->assertSame(ApplicationStatus::PendingTailoring, $job->application->status);
        $this->assertEquals($firstMatchedAt, $job->searchProfiles()->sole()->pivot->matched_at);

        // Known postings aren't described again: only the first poll's three lookups were sent.
        $this->assertCount(3, Http::recorded(fn (Request $request) => $request['operationName'] === 'getJobById'));
    }

    public function test_a_closed_job_is_expired_when_glints_closed_it_at_its_expiry_date(): void
    {
        // Search only serves OPEN postings, so the closed states come from live getJobById captures.
        $page = $this->fixture('search-jobs.json');
        $expired = $this->fixture('job-expired-00317faf-5b08-4ac9-bd9b-028662ed379b.json')['data']['getJobById'];
        $closed = $this->fixture('job-closed-0c75c92d-f952-4446-aa77-a5a2deb5732d.json')['data']['getJobById'];
        $fields = ['status' => null, 'closedAt' => null, 'expiryDate' => null];
        $page['data']['searchJobsV3']['jobsInPage'][0] = [...$page['data']['searchJobsV3']['jobsInPage'][0], ...array_intersect_key($expired, $fields)];
        $page['data']['searchJobsV3']['jobsInPage'][1] = [...$page['data']['searchJobsV3']['jobsInPage'][1], ...array_intersect_key($closed, $fields)];
        // Closed by the employer on the expiry day itself, before Glints' own close would run.
        $page['data']['searchJobsV3']['jobsInPage'][2] = [...$page['data']['searchJobsV3']['jobsInPage'][2], 'status' => 'CLOSED', 'closedAt' => '2024-10-25T09:30:00.000Z', 'expiryDate' => '2024-10-25T00:00:00Z'];
        $this->fakeGlints([$page]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(JobStatus::Expired, Job::where('external_id', self::SOFTWARE_ENGINEER_ID)->sole()->status);
        $this->assertSame(JobStatus::Closed, Job::where('external_id', self::REMOTE_FULLSTACK_ID)->sole()->status);
        $this->assertSame(JobStatus::Closed, Job::where('external_id', self::HYBRID_INTERNSHIP_ID)->sole()->status);
    }

    /**
     * Salary lists as live Glints postings report them.
     *
     * @return array<string, array{list<array<string, mixed>>, SalaryPeriod, float|int}>
     */
    public static function salaryModes(): array
    {
        return [
            'daily (654d5b11)' => [[
                ['CurrencyCode' => 'IDR', 'maxAmount' => 250000, 'minAmount' => 150000, 'salaryMode' => 'DAY', 'salaryType' => 'BASIC'],
            ], SalaryPeriod::Unspecified, 150000],
            'hourly (d92b26f7)' => [[
                ['CurrencyCode' => 'IDR', 'maxAmount' => 400000, 'minAmount' => 390000, 'salaryMode' => 'HOUR', 'salaryType' => 'BASIC'],
            ], SalaryPeriod::Unspecified, 390000],
            'yearly (64b658c3)' => [[
                ['CurrencyCode' => 'VND', 'maxAmount' => 15000000, 'minAmount' => 10000000, 'salaryMode' => 'YEAR', 'salaryType' => 'BONUS'],
                ['CurrencyCode' => 'VND', 'maxAmount' => 15000000, 'minAmount' => 10000000, 'salaryMode' => 'YEAR', 'salaryType' => 'BASIC'],
            ], SalaryPeriod::Yearly, 10000000],
            'weekly bonus beside a monthly base (9f430571)' => [[
                ['CurrencyCode' => 'IDR', 'maxAmount' => 1000000, 'minAmount' => 500000, 'salaryMode' => 'WEEK', 'salaryType' => 'BONUS'],
                ['CurrencyCode' => 'IDR', 'maxAmount' => 6500000, 'minAmount' => 5000000, 'salaryMode' => 'MONTH', 'salaryType' => 'BASIC'],
            ], SalaryPeriod::Monthly, 5000000],
            'per-project bonus beside a monthly base (b017c03c)' => [[
                ['CurrencyCode' => 'IDR', 'maxAmount' => 5000000, 'minAmount' => 2000000, 'salaryMode' => 'PROJECT', 'salaryType' => 'BONUS'],
                ['CurrencyCode' => 'IDR', 'maxAmount' => 6000000, 'minAmount' => 5000000, 'salaryMode' => 'MONTH', 'salaryType' => 'BASIC'],
            ], SalaryPeriod::Monthly, 5000000],
            'unlabelled (2a19f54f)' => [[
                ['CurrencyCode' => 'SGD', 'maxAmount' => 50, 'minAmount' => 25, 'salaryMode' => '', 'salaryType' => ''],
            ], SalaryPeriod::Unspecified, 25],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $salaries
     */
    #[DataProvider('salaryModes')]
    public function test_salary_modes_map_to_a_salary_period(array $salaries, SalaryPeriod $period, float|int $minimum): void
    {
        $page = $this->fixture('search-jobs.json');
        $page['data']['searchJobsV3']['jobsInPage'][0]['salaries'] = $salaries;
        $this->fakeGlints([$page]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $job = Job::where('external_id', self::SOFTWARE_ENGINEER_ID)->sole();
        $this->assertSame($period, $job->salary_period);
        $this->assertEquals($minimum, $job->salary_min);
    }

    public function test_paused_search_profiles_are_not_polled(): void
    {
        $this->fakeGlints();
        SearchProfile::factory()->paused()->create(['keyword' => ['software engineer']]);

        $this->artisan('applyr:poll')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, Job::count());
    }

    public function test_paging_stops_at_the_page_cap(): void
    {
        config(['applyr.adapters.page_cap' => 2]);
        $this->fakeGlints([$this->fixture('search-jobs.json'), $this->fixture('search-jobs-accountant.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $searches = Http::recorded(fn (Request $request) => $request['operationName'] === 'searchJobs');
        $this->assertSame([1, 2], $searches->map(fn (array $pair) => $pair[0]['variables']['data']['page'])->all());
        $this->assertSame(5, Job::count());
    }

    public function test_paging_ends_quietly_when_glints_requires_login_for_the_next_page(): void
    {
        $this->fakeGlints([$this->fixture('search-jobs.json'), $this->fixture('search-jobs-login-required.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertCount(2, Http::recorded(fn (Request $request) => $request['operationName'] === 'searchJobs'));
        $this->assertSame(3, Job::count());
    }

    public function test_requests_to_glints_are_paced(): void
    {
        config(['applyr.adapters.request_delay_ms' => 1500, 'applyr.adapters.page_cap' => 1]);
        $this->fakeGlints();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => 'Jakarta Selatan']);

        $this->artisan('applyr:poll')->assertSuccessful();

        // Location lookup, one search page, three descriptions: a pause before every request but the first.
        Sleep::assertSequence(array_fill(0, 4, Sleep::for(1500)->milliseconds()));
    }

    public function test_a_cloudflare_challenge_fails_the_run_as_anti_bot_blocked(): void
    {
        Http::fake([
            'glints.com/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/Glints/cloudflare-challenge.html')),
                403,
                ['Cf-Mitigated' => 'challenge', 'Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $this->assertPollFails(FailureCategory::AntiBot);
    }

    public function test_a_graphql_errors_array_fails_the_run_as_an_api_error(): void
    {
        Http::fake(['glints.com/*' => Http::response($this->fixture('graphql-validation-error.json'))]);

        $this->assertPollFails(FailureCategory::ApiError);
    }

    public function test_a_connection_failure_fails_the_run_as_transport(): void
    {
        Http::fake(['glints.com/*' => Http::failedConnection()]);

        $this->assertPollFails(FailureCategory::Transport);
    }

    public function test_a_result_missing_a_required_field_fails_the_run_as_shape_drift(): void
    {
        $drifted = $this->fixture('search-jobs.json');
        $drifted['data']['searchJobsV3']['jobsInPage'][1]['jobTitle'] = $drifted['data']['searchJobsV3']['jobsInPage'][1]['title'];
        unset($drifted['data']['searchJobsV3']['jobsInPage'][1]['title']);
        $this->fakeGlints([$drifted]);

        $this->assertPollFails(FailureCategory::ShapeDrift);
    }

    public function test_polling_is_scheduled_on_the_configured_interval(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'applyr:poll'));

        $this->assertCount(1, $events);
        $this->assertSame(config('applyr.polling.schedule'), $events->first()->expression);
    }

    /**
     * The run fails with the given category recorded against Glints, storing nothing.
     */
    private function assertPollFails(FailureCategory $category): void
    {
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $health = AdapterHealth::for(Platform::Glints);
        $this->assertSame($category, $health->last_failure_category);
        $this->assertSame(1, $health->failuresFor($category));
        $this->assertSame(0, Job::count());
    }

    /**
     * Serve Glints from fixtures. Calling again swaps the search responses for later polls.
     *
     * @param  array<int, array<string, mixed>>  $searchPages  search responses served in order; the last repeats
     */
    private function fakeGlints(?array $searchPages = null): void
    {
        $alreadyFaked = $this->searchPages !== [];
        $this->searchPages = $searchPages ?? [$this->fixture('search-jobs.json')];
        $this->searchCalls = 0;

        if ($alreadyFaked) {
            return;
        }

        Http::fake([
            'glints.com/api/v2/graphql' => fn (Request $request) => match ($request['operationName']) {
                'searchJobs' => Http::response($this->searchPages[min($this->searchCalls++, count($this->searchPages) - 1)]),
                'searchHierarchicalLocations' => Http::response($this->fixture($this->locationResponse)),
                'getJobById' => Http::response($this->fixture("job-detail-{$request['variables']['id']}.json")),
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/Glints/{$name}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
