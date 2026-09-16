<?php

namespace App\Adapters\Glints;

use App\Adapters\Adapter;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\GraphQlClient;
use App\Adapters\JobData;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\JobTypeFilter;
use App\Enums\Platform;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Enums\WorkArrangementFilter;
use App\Models\SearchProfile;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Glints job search through its internal GraphQL API (glints.com/api/v2/graphql).
 */
class GlintsAdapter implements Adapter
{
    private const ENDPOINT = 'https://glints.com/api/v2/graphql';

    // Cloudflare challenges a bare User-Agent; a full desktop Chrome string passes.
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    // Cloudflare also scores the TLS handshake: PHP's OpenSSL defaults get challenged even with
    // a browser User-Agent, while Chrome's cipher and curve preferences pass.
    private const TLS_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-SHA:ECDHE-RSA-AES256-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA:AES256-SHA';

    private const TLS_CURVES = 'X25519:P-256:P-384';

    // The largest page Glints serves; anonymous callers only get page 1.
    private const PAGE_SIZE = 50;

    private const SEARCH_JOBS_QUERY = <<<'GRAPHQL'
        query searchJobs($data: JobSearchConditionInput!) {
          searchJobsV3(data: $data) {
            hasMore
            jobsInPage {
              id title status type workArrangementOption isRemote source
              createdAt updatedAt expiryDate closedAt
              minYearsOfExperience maxYearsOfExperience educationLevel
              descriptionJsonString externalApplyURL
              CountryCode
              company { id name brandName }
              city { id name }
              citySubDivision { id name }
              country { code name }
              location { id name formattedName level parents { id name formattedName level } }
              salaries { salaryType salaryMode maxAmount minAmount CurrencyCode }
              skills { skill { id name } mustHave }
              benefits { benefit title description }
              hierarchicalJobCategory { id level name }
            }
          }
        }
        GRAPHQL;

    private const SEARCH_LOCATIONS_QUERY = <<<'GRAPHQL'
        query searchHierarchicalLocations($searchTerm: String, $countryCode: String, $searchType: String) {
          searchHierarchicalLocations(searchTerm: $searchTerm, countryCode: $countryCode, searchType: $searchType) {
            list { id name formattedName level }
          }
        }
        GRAPHQL;

    private const JOB_DETAIL_QUERY = <<<'GRAPHQL'
        query getJobById($id: String!) {
          getJobById(id: $id) { id descriptionJsonString }
        }
        GRAPHQL;

    private readonly GraphQlClient $client;

    public function __construct()
    {
        $this->client = new GraphQlClient(
            Platform::Glints,
            self::ENDPOINT,
            headers: ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
            options: ['curl' => [
                CURLOPT_SSL_CIPHER_LIST => self::TLS_CIPHERS,
                CURLOPT_SSL_EC_CURVES => self::TLS_CURVES,
            ]],
        );
    }

    public function platform(): Platform
    {
        return Platform::Glints;
    }

    public function search(SearchProfile $searchProfile): array
    {
        $conditions = $this->searchConditions($searchProfile);
        $jobs = [];

        // Glints matches only the first SearchTerm, so OR-matching keywords takes one search each.
        foreach ($searchProfile->keyword as $keyword) {
            for ($page = 1; $page <= config('applyr.adapters.page_cap'); $page++) {
                $results = $this->searchPage([...$conditions, 'SearchTerm' => [$keyword], 'page' => $page]);

                if ($results === null) {
                    break;
                }

                foreach ($results['jobsInPage'] as $posting) {
                    $jobData = $this->toJobData($posting);
                    $jobs[$jobData->externalId] ??= $jobData;
                }

                if (! $results['hasMore'] || $results['jobsInPage'] === []) {
                    break;
                }
            }
        }

        return array_values($jobs);
    }

    public function describe(JobData $jobData): string
    {
        $data = $this->client->query('getJobById', self::JOB_DETAIL_QUERY, ['id' => $jobData->externalId]);

        // A posting removed since the search has no detail left to fetch.
        $descriptionJson = $data['getJobById']['descriptionJsonString'] ?? null;

        if ($descriptionJson === null) {
            return '';
        }

        return $this->flattenDescription($descriptionJson);
    }

    /**
     * Translate a SearchProfile into searchJobsV3 conditions, minus keyword and page.
     *
     * @return array<string, mixed>
     */
    private function searchConditions(SearchProfile $searchProfile): array
    {
        $conditions = [
            'CountryCode' => [$searchProfile->country_code],
            'lastUpdatedAtRange' => $searchProfile->post_date_range->value,
            'pageSize' => self::PAGE_SIZE,
        ];

        if ($locationId = $this->resolveLocationId($searchProfile)) {
            $conditions['LocationIds'] = [$locationId];
        }

        if ($searchProfile->min_experience_years !== null) {
            $conditions['minYearsOfExperience'] = $searchProfile->min_experience_years;
        }

        if ($searchProfile->max_experience_years !== null) {
            $conditions['maxYearsOfExperience'] = $searchProfile->max_experience_years;
        }

        if ($searchProfile->work_arrangement !== WorkArrangementFilter::Any) {
            $conditions['workArrangementOptions'] = [strtoupper($searchProfile->work_arrangement->value)];
            $conditions['isRemote'] = $searchProfile->work_arrangement === WorkArrangementFilter::Remote;
        }

        if ($searchProfile->job_type !== JobTypeFilter::Any) {
            $conditions['type'] = [strtoupper($searchProfile->job_type->value)];
        }

        return $conditions;
    }

    /**
     * Best-effort match of the free-text location to a Glints location id.
     */
    private function resolveLocationId(SearchProfile $searchProfile): ?string
    {
        $location = trim((string) $searchProfile->location);

        if ($location === '') {
            return null;
        }

        try {
            $data = $this->client->query('searchHierarchicalLocations', self::SEARCH_LOCATIONS_QUERY, [
                'searchTerm' => $location,
                'countryCode' => $searchProfile->country_code,
                'searchType' => 'SEARCH',
            ]);
        } catch (ApiErrorException|ShapeDriftException) {
            // Location is a best-effort filter: a lookup Glints won't answer mustn't sink the run.
            return null;
        }

        foreach ($data['searchHierarchicalLocations']['list'] ?? [] as $candidate) {
            if (strcasecmp($candidate['name'] ?? '', $location) === 0 || strcasecmp($candidate['formattedName'] ?? '', $location) === 0) {
                return $candidate['id'];
            }
        }

        // No exact name match: search the whole country rather than guess a place.
        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{hasMore: bool, jobsInPage: list<array<string, mixed>>}|null null when Glints won't serve this page
     */
    private function searchPage(array $data): ?array
    {
        $body = $this->client->send('searchJobs', self::SEARCH_JOBS_QUERY, ['data' => $data]);

        // Pages past the first need a logged-in user; that's the end of what we can see.
        if ($data['page'] > 1 && ($body['errors'][0]['extensions']['code'] ?? null) === 'NO_PERMISSION') {
            return null;
        }

        $results = $this->client->data($body)['searchJobsV3'] ?? null;

        if (! is_array($results) || ! is_array($results['jobsInPage'] ?? null)) {
            throw new ShapeDriftException(Platform::Glints, 'searchJobsV3.jobsInPage is missing.', 200);
        }

        return ['hasMore' => (bool) ($results['hasMore'] ?? false), 'jobsInPage' => $results['jobsInPage']];
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function toJobData(array $posting): JobData
    {
        $externalId = $this->client->required($posting, 'id');
        $salary = $this->salary($posting);

        return new JobData(
            externalId: $externalId,
            title: $this->client->required($posting, 'title'),
            companyName: $this->client->required($posting['company'] ?? [], 'name', 'company.name'),
            location: $this->location($posting),
            countryCode: $this->client->required($posting, 'CountryCode'),
            url: ($posting['externalApplyURL'] ?? null) ?: "https://glints.com/id/opportunities/jobs/{$externalId}",
            workArrangement: $this->workArrangement($posting),
            jobType: match ($posting['type'] ?? null) {
                'FULL_TIME' => JobType::FullTime,
                'PART_TIME' => JobType::PartTime,
                'CONTRACT' => JobType::Contract,
                'INTERNSHIP' => JobType::Internship,
                default => JobType::Unspecified,
            },
            minYearsExperience: $posting['minYearsOfExperience'] ?? null,
            maxYearsExperience: $posting['maxYearsOfExperience'] ?? null,
            status: $this->status($posting),
            salaryMin: $salary['minAmount'] ?? null,
            salaryMax: $salary['maxAmount'] ?? null,
            salaryCurrency: $salary['CurrencyCode'] ?? null,
            // Glints also reports WEEK, DAY, HOUR, PROJECT and a blank mode, which have no SalaryPeriod.
            salaryPeriod: $salary === null ? null : match ($salary['salaryMode'] ?? null) {
                'MONTH' => SalaryPeriod::Monthly,
                'YEAR' => SalaryPeriod::Yearly,
                default => SalaryPeriod::Unspecified,
            },
            postedDate: $this->postedDate($posting),
            rawPayload: $posting,
        );
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function postedDate(array $posting): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->client->required($posting, 'createdAt'));
        } catch (ShapeDriftException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ShapeDriftException(Platform::Glints, 'Posting field createdAt is not a date.', 200, $e);
        }
    }

    /**
     * The posting's place from most to least specific, stopping above country level.
     *
     * @param  array<string, mixed>  $posting
     */
    private function location(array $posting): ?string
    {
        $location = $posting['location'] ?? null;

        if (! is_array($location)) {
            return $posting['city']['name'] ?? null;
        }

        $names = collect([$location, ...($location['parents'] ?? [])])
            ->filter(fn (array $place) => ($place['level'] ?? 0) > 1)
            ->sortByDesc('level')
            ->pluck('formattedName')
            ->filter();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    /**
     * Glints' job status enum is OPEN, CLOSED, IN_REVIEW and DELETED, with no expired state, so an
     * expiry shows up as CLOSED. IN_REVIEW and DELETED postings aren't served publicly.
     *
     * @param  array<string, mixed>  $posting
     */
    private function status(array $posting): JobStatus
    {
        return match ($posting['status'] ?? null) {
            'OPEN' => JobStatus::Open,
            'CLOSED' => $this->closedOnExpiry($posting) ? JobStatus::Expired : JobStatus::Closed,
            default => JobStatus::Unknown,
        };
    }

    /**
     * Whether Glints closed the posting itself when it expired rather than the employer closing it.
     * Glints closes expired postings as the expiry day ends in its UTC+7/UTC+8 markets (17:00Z seen in
     * Indonesia), so a close before that is manual, even one earlier on the expiry day.
     *
     * @param  array<string, mixed>  $posting
     */
    private function closedOnExpiry(array $posting): bool
    {
        if (empty($posting['closedAt']) || empty($posting['expiryDate'])) {
            return false;
        }

        try {
            $closedAt = CarbonImmutable::parse($posting['closedAt']);
            // expiryDate is a bare date on open postings and midnight UTC on closed ones.
            $expiryDayEnds = CarbonImmutable::parse($posting['expiryDate'], 'UTC')->startOfDay()->addHours(16);
        } catch (Throwable $e) {
            throw new ShapeDriftException(Platform::Glints, 'Posting field closedAt or expiryDate is not a date.', 200, $e);
        }

        return $closedAt->gte($expiryDayEnds);
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function workArrangement(array $posting): WorkArrangement
    {
        return match ($posting['workArrangementOption'] ?? null) {
            'ONSITE' => WorkArrangement::Onsite,
            'HYBRID' => WorkArrangement::Hybrid,
            'REMOTE' => WorkArrangement::Remote,
            default => ($posting['isRemote'] ?? false) ? WorkArrangement::Remote : WorkArrangement::Unspecified,
        };
    }

    /**
     * The base salary as reported, or the first salary if none is marked base.
     *
     * @param  array<string, mixed>  $posting
     * @return array<string, mixed>|null
     */
    private function salary(array $posting): ?array
    {
        $salaries = $posting['salaries'] ?? null;

        if (empty($salaries)) {
            return null;
        }

        return collect($salaries)->firstWhere('salaryType', 'BASIC') ?? $salaries[0];
    }

    /**
     * Flatten a Draft.js descriptionJsonString into plain text, one block per line.
     */
    private function flattenDescription(string $descriptionJson): string
    {
        $blocks = json_decode($descriptionJson, true)['blocks'] ?? null;

        if (! is_array($blocks)) {
            throw new ShapeDriftException(Platform::Glints, 'descriptionJsonString has no Draft.js blocks.', 200);
        }

        $lines = [];
        $orderedNumber = 0;

        foreach ($blocks as $block) {
            $text = rtrim($block['text'] ?? '');
            $type = $block['type'] ?? 'unstyled';
            $orderedNumber = $type === 'ordered-list-item' ? $orderedNumber + 1 : 0;

            $lines[] = match ($type) {
                'unordered-list-item' => "- {$text}",
                'ordered-list-item' => "{$orderedNumber}. {$text}",
                default => $text,
            };
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
