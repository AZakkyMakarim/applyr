<?php

namespace Tests\Feature;

use App\Adapters\Exceptions\AdapterException;
use App\Adapters\Exceptions\AntiBotBlockedException;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\Exceptions\TransportException;
use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\JobTypeFilter;
use App\Enums\Platform;
use App\Enums\PostDateRange;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Enums\WorkArrangementFilter;
use App\Models\Application;
use App\Models\Job;
use App\Models\SearchProfile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobStreetPollingTest extends TestCase
{
    private const QA_ENGINEER_ID = '94675692';

    private const VIRTUAL_DESKTOP_ID = '94674962';

    private const REMOTE_ID = '94434122';

    private const NO_ORGANISATION_ID = '94667969';

    private const HOURLY_ID = '94672549';

    /** @var array<int, array<string, mixed>> */
    private array $searchPages = [];

    private int $searchCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // The poll runs every Adapter; Glints finds nothing so only JobStreet Jobs are stored.
        Http::fake(['glints.com/*' => Http::response(['data' => ['searchJobsV3' => ['hasMore' => false, 'jobsInPage' => []]]])]);
    }

    public function test_polling_normalizes_a_jobstreet_result_into_a_job(): void
    {
        $this->fakeJobStreet();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null, 'country_code' => 'ID']);

        $this->artisan('applyr:poll')->assertSuccessful();

        $job = Job::where('platform', 'jobstreet')->where('external_id', self::QA_ENGINEER_ID)->sole();

        $this->assertSame('Quality Assurance Engineer', $job->title);
        $this->assertSame('PT Abhimata Persada', $job->company_name);
        $this->assertSame('Jakarta Raya', $job->location);
        $this->assertSame('ID', $job->country_code);
        $this->assertSame('https://id.jobstreet.com/job/94675692', $job->url);
        $this->assertSame(
            'Memastikan kualitas aplikasi melalui manual testing, validasi data, dan pengujian API.',
            $job->description,
        );
        $this->assertSame('onsite', $job->work_arrangement->value);
        $this->assertSame('contract', $job->job_type->value);
        $this->assertNull($job->min_years_experience);
        $this->assertNull($job->max_years_experience);
        $this->assertSame('unknown', $job->status->value);
        $this->assertEquals(8000000, $job->salary_min);
        $this->assertEquals(12000000, $job->salary_max);
        $this->assertSame('IDR', $job->salary_currency);
        $this->assertSame('monthly', $job->salary_period->value);
        $this->assertSame('2026-09-16 09:36:13', $job->posted_date->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->fixture('search-jobs-page-1.json')['data']['jobSearchV7']['results']['jobs'][0], $job->raw_payload);
    }

    public function test_normalization_covers_hybrid_remote_and_non_monthly_salaries(): void
    {
        config(['applyr.adapters.page_cap' => 2]);
        $this->fakeJobStreet([$this->fixture('search-jobs-page-1.json'), $this->fixture('search-jobs-page-2.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $hybrid = Job::where('external_id', self::VIRTUAL_DESKTOP_ID)->sole();
        $this->assertSame(WorkArrangement::Hybrid, $hybrid->work_arrangement);
        $this->assertSame(JobType::FullTime, $hybrid->job_type);
        $this->assertSame('Indonesia', $hybrid->location);

        $remote = Job::where('external_id', self::REMOTE_ID)->sole();
        $this->assertSame(WorkArrangement::Remote, $remote->work_arrangement);

        $hourly = Job::where('external_id', self::HOURLY_ID)->sole();
        $this->assertSame(JobType::FullTime, $hourly->job_type);
        $this->assertEquals(90000, $hourly->salary_min);
        $this->assertEquals(100000, $hourly->salary_max);
        $this->assertSame(SalaryPeriod::Unspecified, $hourly->salary_period);

        // Advertisers without a SEEK organisation still name the company.
        $this->assertSame('PT Mitra Indah Lestari', Job::where('external_id', self::NO_ORGANISATION_ID)->sole()->company_name);
    }

    public function test_annual_salaries_part_time_and_unreported_fields(): void
    {
        $page = $this->fixture('search-jobs-page-1.json');
        $page['data']['jobSearchV7']['results']['jobs'][0]['salary'] = ['period' => 'annual', 'min' => 10000, 'max' => 15000, 'currency' => 'USD'];
        $page['data']['jobSearchV7']['results']['jobs'][1]['salary'] = null;
        $page['data']['jobSearchV7']['results']['jobs'][1]['workArrangements'] = [];
        // Casual work has no canonical job type.
        $page['data']['jobSearchV7']['results']['jobs'][1]['cjs']['workTypes'] = [['sourceId' => '245']];
        $page['data']['jobSearchV7']['results']['jobs'][2]['cjs']['workTypes'] = [['sourceId' => '243']];
        $this->fakeJobStreet([$page, $this->fixture('search-jobs-no-results.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $annual = Job::where('external_id', self::QA_ENGINEER_ID)->sole();
        $this->assertEquals(10000, $annual->salary_min);
        $this->assertSame('USD', $annual->salary_currency);
        $this->assertSame(SalaryPeriod::Yearly, $annual->salary_period);

        $unreported = Job::where('external_id', self::VIRTUAL_DESKTOP_ID)->sole();
        $this->assertNull($unreported->salary_min);
        $this->assertNull($unreported->salary_max);
        $this->assertNull($unreported->salary_currency);
        $this->assertNull($unreported->salary_period);
        $this->assertSame(WorkArrangement::Unspecified, $unreported->work_arrangement);
        $this->assertSame(JobType::Unspecified, $unreported->job_type);

        $this->assertSame(JobType::PartTime, Job::where('external_id', self::REMOTE_ID)->sole()->job_type);
    }

    public function test_paging_stops_at_the_page_cap(): void
    {
        config(['applyr.adapters.page_cap' => 2]);
        $this->fakeJobStreet([$this->fixture('search-jobs-page-1.json'), $this->fixture('search-jobs-page-2.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        // Both pages report 2148 results, so only the cap ends paging.
        $this->assertSame([1, 2], $this->jobStreetSearches()->pluck('responseConfig.page')->all());
        $this->assertSame(5, Job::where('platform', 'jobstreet')->count());
    }

    public function test_paging_stops_once_every_result_has_been_fetched(): void
    {
        $lastPage = $this->fixture('search-jobs-page-1.json');
        $lastPage['data']['jobSearchV7']['results']['pagination']['resultCount'] = 3;
        $this->fakeJobStreet([$lastPage, $this->fixture('search-jobs-page-2.json')]);
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame([1], $this->jobStreetSearches()->pluck('responseConfig.page')->all());
        $this->assertSame(3, Job::where('platform', 'jobstreet')->count());
    }

    public function test_paging_stops_at_an_empty_page(): void
    {
        $this->fakeJobStreet();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame([1, 2], $this->jobStreetSearches()->pluck('responseConfig.page')->all());
        $this->assertSame(3, Job::where('platform', 'jobstreet')->count());
    }

    public function test_jobstreet_jobs_dedup_independently_of_glints_jobs_with_the_same_external_id(): void
    {
        $glintsJob = Job::create([
            'platform' => Platform::Glints,
            'external_id' => self::QA_ENGINEER_ID,
            'title' => 'Glints posting',
            'company_name' => 'Glints company',
            'country_code' => 'ID',
            'url' => 'https://glints.com/id/opportunities/jobs/'.self::QA_ENGINEER_ID,
            'description' => 'Glints description',
            'work_arrangement' => WorkArrangement::Unspecified,
            'job_type' => JobType::Unspecified,
            'status' => JobStatus::Open,
            'posted_date' => now(),
            'raw_payload' => [],
        ]);
        $glintsJob->application()->create(['status' => ApplicationStatus::PendingTailoring]);
        $this->fakeJobStreet();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame('Glints posting', $glintsJob->fresh()->title);
        $jobStreetJob = Job::where('platform', 'jobstreet')->where('external_id', self::QA_ENGINEER_ID)->sole();
        $this->assertNotEquals($glintsJob->id, $jobStreetJob->id);
        $this->assertSame(ApplicationStatus::PendingTailoring, $jobStreetJob->application->status);
        $this->assertSame(4, Application::count());
    }

    public function test_re_polling_refreshes_jobstreet_jobs_in_place_without_new_applications(): void
    {
        $searchProfile = SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);
        $this->fakeJobStreet();
        $this->artisan('applyr:poll')->assertSuccessful();

        $retitled = $this->fixture('search-jobs-page-1.json');
        $retitled['data']['jobSearchV7']['results']['jobs'][0]['title'] = 'Senior Quality Assurance Engineer';
        $this->fakeJobStreet([$retitled, $this->fixture('search-jobs-no-results.json')]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(3, Job::count());
        $this->assertSame(3, Application::count());
        $job = Job::where('external_id', self::QA_ENGINEER_ID)->sole();
        $this->assertSame('Senior Quality Assurance Engineer', $job->title);
        $this->assertSame([$searchProfile->id], $job->searchProfiles->modelKeys());
    }

    public function test_requests_to_jobstreet_are_paced(): void
    {
        config(['applyr.adapters.request_delay_ms' => 1500]);
        $this->fakeJobStreet();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        $this->artisan('applyr:poll')->assertSuccessful();

        // Two search pages; descriptions come with the results. Glints' single empty search never pauses.
        Sleep::assertSequence([Sleep::for(1500)->milliseconds()]);
    }

    public function test_a_cloudflare_challenge_fails_the_run_as_anti_bot_blocked(): void
    {
        Http::fake([
            'id.jobstreet.com/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/JobStreet/cloudflare-challenge.html')),
                403,
                ['Cf-Mitigated' => 'challenge', 'Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $this->assertPollFails(AntiBotBlockedException::class);
    }

    public function test_a_graphql_error_on_a_200_fails_the_run_as_an_api_error(): void
    {
        Http::fake(['id.jobstreet.com/*' => Http::response($this->fixture('graphql-unstable-query-error.json'))]);

        $this->assertPollFails(ApiErrorException::class);
    }

    public function test_a_rejected_query_fails_the_run_as_an_api_error(): void
    {
        Http::fake(['id.jobstreet.com/*' => Http::response($this->fixture('graphql-validation-error.json'), 400)]);

        $this->assertPollFails(ApiErrorException::class);
    }

    public function test_a_connection_failure_fails_the_run_as_transport(): void
    {
        Http::fake(['id.jobstreet.com/*' => Http::failedConnection()]);

        $this->assertPollFails(TransportException::class);
    }

    public function test_a_result_missing_a_required_field_fails_the_run_as_shape_drift(): void
    {
        $drifted = $this->fixture('search-jobs-page-1.json');
        unset($drifted['data']['jobSearchV7']['results']['jobs'][1]['listedAt']);
        Http::fake(['id.jobstreet.com/*' => Http::response($drifted)]);

        $this->assertPollFails(ShapeDriftException::class);
    }

    public function test_a_response_missing_its_results_fails_the_run_as_shape_drift(): void
    {
        Http::fake(['id.jobstreet.com/*' => Http::response(['data' => ['jobSearchV7' => ['jobs' => []]]])]);

        $this->assertPollFails(ShapeDriftException::class);
    }

    public function test_search_profile_filters_are_translated_into_the_jobstreet_request(): void
    {
        $this->fakeJobStreet([$this->fixture('search-jobs-no-results.json')]);
        SearchProfile::factory()->create([
            'keyword' => ['laravel developer', 'php engineer'],
            'location' => 'Jakarta Selatan',
            'country_code' => 'ID',
            'min_experience_years' => 2,
            'max_experience_years' => 5,
            'post_date_range' => PostDateRange::PastWeek,
            'work_arrangement' => WorkArrangementFilter::Remote,
            'job_type' => JobTypeFilter::Contract,
        ]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $sessionIds = [];

        foreach (['laravel developer', 'php engineer'] as $keyword) {
            Http::assertSent(function (Request $request) use ($keyword, &$sessionIds) {
                if ($request->url() !== 'https://id.jobstreet.com/graphql' || $request['variables']['params']['searchIntent']['text'] !== $keyword) {
                    return false;
                }

                $params = $request['variables']['params'];
                $sessionIds[] = $params['sessionId'];

                // Experience years have no JobStreet filter, so they're left out.
                return $request->method() === 'POST'
                    && $request['operationName'] === 'JobSearchV7'
                    && str_contains($request['query'], 'jobSearchV7(params: $params)')
                    && $this->hasBrowserHeaders($request)
                    && Str::isUuid($params['sessionId'])
                    && $params['searchContext'] === ['brand' => 'jobstreet', 'channel' => 'web', 'intent' => 'SEARCH', 'source' => 'FE_SERP']
                    && $params['searchIntent'] === [
                        'country' => 'ID',
                        'locale' => 'id-ID',
                        'sort' => 'listedAt',
                        'where' => ['Jakarta Selatan'],
                        'filter' => ['listedAt' => '7d', 'workTypeId' => ['244'], 'workArrangementId' => ['3']],
                        'text' => $keyword,
                    ]
                    && $params['responseConfig'] === ['results' => ['jobs'], 'representations' => ['uiV1'], 'page' => 1, 'pageSize' => 30];
            });
        }

        $this->assertCount(1, array_unique($sessionIds));
    }

    public function test_post_date_ranges_map_to_jobstreet_listed_at_days(): void
    {
        $this->fakeJobStreet([$this->fixture('search-jobs-no-results.json')]);

        foreach ([PostDateRange::Past24Hours, PostDateRange::PastWeek, PostDateRange::PastMonth] as $range) {
            SearchProfile::factory()->create(['keyword' => [$range->value], 'location' => null, 'post_date_range' => $range]);
        }

        $this->artisan('applyr:poll')->assertSuccessful();

        $listedAt = $this->jobStreetSearches()
            ->mapWithKeys(fn (array $params) => [$params['searchIntent']['text'] => $params['searchIntent']['filter']['listedAt']])
            ->all();

        $this->assertSame(['PAST_24_HOURS' => '1d', 'PAST_WEEK' => '7d', 'PAST_MONTH' => '30d'], $listedAt);
    }

    public function test_filters_jobstreet_cannot_express_are_left_out_of_the_request(): void
    {
        $this->fakeJobStreet([$this->fixture('search-jobs-no-results.json')]);
        SearchProfile::factory()->create([
            'keyword' => ['software engineer'],
            'location' => null,
            'post_date_range' => PostDateRange::AnyTime,
            'work_arrangement' => WorkArrangementFilter::Any,
            // JobStreet has no internship work type; searching every type beats finding nothing.
            'job_type' => JobTypeFilter::Internship,
        ]);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertSame(
            [['country' => 'ID', 'locale' => 'id-ID', 'sort' => 'listedAt', 'text' => 'software engineer']],
            $this->jobStreetSearches()->pluck('searchIntent')->all(),
        );
    }

    public function test_a_location_jobstreet_does_not_recognise_falls_back_to_searching_the_whole_country(): void
    {
        $this->fakeJobStreet([
            $this->fixture('search-jobs-unresolved-location.json'),
            $this->fixture('search-jobs-page-1.json'),
            $this->fixture('search-jobs-no-results.json'),
        ]);
        SearchProfile::factory()->create(['keyword' => ['software engineer', 'qa engineer'], 'location' => 'Atlantis']);

        $this->artisan('applyr:poll')->assertSuccessful();

        // The location is tried once; the second keyword goes straight to the whole country.
        $this->assertSame(
            [
                ['software engineer', ['Atlantis']],
                ['software engineer', null],
                ['software engineer', null],
                ['qa engineer', null],
            ],
            $this->jobStreetSearches()
                ->map(fn (array $params) => [$params['searchIntent']['text'], $params['searchIntent']['where'] ?? null])
                ->all(),
        );
        $this->assertSame(3, Job::where('platform', 'jobstreet')->count());
    }

    public function test_search_profiles_outside_indonesia_are_ignored_by_jobstreet(): void
    {
        $this->fakeJobStreet();
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null, 'country_code' => 'MY']);

        $this->artisan('applyr:poll')->assertSuccessful();

        $this->assertTrue($this->jobStreetSearches()->isEmpty());
        $this->assertSame(0, Job::count());
    }

    /**
     * @param  class-string<AdapterException>  $exception
     */
    private function assertPollFails(string $exception): void
    {
        SearchProfile::factory()->create(['keyword' => ['software engineer'], 'location' => null]);

        try {
            $this->artisan('applyr:poll')->run();
            $this->fail("Expected the poll to fail with {$exception}.");
        } catch (AdapterException $e) {
            $this->assertInstanceOf($exception, $e);
            $this->assertSame(Platform::JobStreet, $e->platform);
        }

        $this->assertSame(0, Job::count());
    }

    /**
     * The params of every jobSearchV7 request sent, in order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function jobStreetSearches(): Collection
    {
        return Http::recorded(fn (Request $request) => $request->url() === 'https://id.jobstreet.com/graphql')
            ->map(fn (array $pair) => $pair[0]['variables']['params'])
            ->values();
    }

    /**
     * Whether the request carries every header JobStreet's own search page sends, with the same values.
     */
    private function hasBrowserHeaders(Request $request): bool
    {
        foreach ($this->fixture('site-search-request.json')['headers'] as $name => $value) {
            if ($request->header($name) !== [$value]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Serve JobStreet search from fixtures. Calling again swaps the responses for later polls.
     *
     * @param  array<int, array<string, mixed>>  $searchPages  search responses served in order; the last repeats
     */
    private function fakeJobStreet(?array $searchPages = null): void
    {
        $alreadyFaked = $this->searchPages !== [];
        $this->searchPages = $searchPages ?? [$this->fixture('search-jobs-page-1.json'), $this->fixture('search-jobs-no-results.json')];
        $this->searchCalls = 0;

        if ($alreadyFaked) {
            return;
        }

        Http::fake([
            'id.jobstreet.com/graphql' => fn (Request $request) => Http::response(
                $this->searchPages[min($this->searchCalls++, count($this->searchPages) - 1)],
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/JobStreet/{$name}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
