<?php

namespace App\Adapters\JobStreet;

use App\Adapters\Adapter;
use App\Adapters\Exceptions\AntiBotBlockedException;
use App\Adapters\Exceptions\ApiErrorException;
use App\Adapters\Exceptions\ShapeDriftException;
use App\Adapters\Exceptions\TransportException;
use App\Adapters\JobData;
use App\Adapters\RetriesTransportFailures;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\Platform;
use App\Enums\PostDateRange;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Models\SearchProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * JobStreet job search through SEEK's candidate GraphQL API (id.jobstreet.com/graphql).
 */
class JobStreetAdapter implements Adapter
{
    use RetriesTransportFailures;

    // jobstreet.co.id only redirects here.
    private const ENDPOINT = 'https://id.jobstreet.com/graphql';

    private const ORIGIN = 'https://id.jobstreet.com';

    // Cloudflare challenges requests that lack a browser's full header set, not just its User-Agent.
    private const HEADERS = [
        'Accept' => '*/*',
        'Accept-Language' => 'en-US,en;q=0.9,id;q=0.8',
        'Origin' => self::ORIGIN,
        'Referer' => self::ORIGIN.'/',
        'sec-ch-ua' => '"Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"Windows"',
        'sec-fetch-dest' => 'empty',
        'sec-fetch-mode' => 'cors',
        'sec-fetch-site' => 'same-origin',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        // Without it jobSearchV7 answers UNSTABLE_QUERY_ERROR.
        'x-custom-features' => 'application/features.seek.all+json',
    ];

    // The only JobStreet site the Adapter searches.
    private const COUNTRY_CODE = 'ID';

    // JobStreet work type ids by the value JobType and JobTypeFilter share. JobStreet has no internship type.
    private const WORK_TYPE_IDS = ['full_time' => '242', 'part_time' => '243', 'contract' => '244'];

    // JobStreet work arrangement ids by the value WorkArrangement and WorkArrangementFilter share.
    private const WORK_ARRANGEMENT_IDS = ['onsite' => '1', 'hybrid' => '2', 'remote' => '3'];

    // The page size JobStreet's own search page asks for.
    private const PAGE_SIZE = 30;

    private const SEARCH_JOBS_QUERY = <<<'GRAPHQL'
        query JobSearchV7($params: JobSearchV7QueryInput!) {
          jobSearchV7(params: $params) {
            results {
              jobs {
                id title abstract url
                advertiser { id name }
                organisation { id name }
                location { id displayName { text } }
                listedAt { dateTimeUtc }
                salary { period min max currency }
                workArrangements { id label { text } }
                cjs { workTypes { sourceId } }
              }
              pagination { page pageSize resultCount }
            }
          }
        }
        GRAPHQL;

    private bool $hasSentRequest = false;

    public function platform(): Platform
    {
        return Platform::JobStreet;
    }

    public function search(SearchProfile $searchProfile): array
    {
        // id.jobstreet.com lists only Indonesian postings; other countries are ignored, not failed.
        if ($searchProfile->country_code !== self::COUNTRY_CODE) {
            return [];
        }

        // A browser tab keeps one session id across its searches.
        $sessionId = (string) Str::uuid();
        $searchIntent = $this->searchIntent($searchProfile);
        $jobs = [];

        // Like the Glints Adapter, one search per keyword, OR-matching them.
        foreach ($searchProfile->keyword as $keyword) {
            for ($page = 1; $page <= config('applyr.adapters.page_cap'); $page++) {
                $results = $this->searchPage($sessionId, [...$searchIntent, 'text' => $keyword], $page);

                // An unrecognised location answers with an empty page 0: search the whole country instead,
                // for this keyword and the rest, since the location won't resolve for them either.
                if (isset($searchIntent['where']) && $results['pagination']['page'] === 0) {
                    unset($searchIntent['where']);
                    $results = $this->searchPage($sessionId, [...$searchIntent, 'text' => $keyword], $page);
                }

                foreach ($results['jobs'] as $posting) {
                    $jobData = $this->toJobData($posting);
                    $jobs[$jobData->externalId] ??= $jobData;
                }

                if ($results['jobs'] === [] || $page * self::PAGE_SIZE >= $results['pagination']['resultCount']) {
                    break;
                }
            }
        }

        return array_values($jobs);
    }

    /**
     * JobStreet's search results already carry the posting's abstract, so no request is needed.
     */
    public function describe(JobData $jobData): string
    {
        return (string) ($jobData->rawPayload['abstract'] ?? '');
    }

    /**
     * Translate a SearchProfile into a jobSearchV7 searchIntent, minus the keyword.
     * JobStreet has no experience-year filter and no internship work type, so those are ignored.
     *
     * @return array<string, mixed>
     */
    private function searchIntent(SearchProfile $searchProfile): array
    {
        $searchIntent = [
            'country' => self::COUNTRY_CODE,
            'locale' => 'id-ID',
            // Newest first, so the page cap keeps the freshest postings.
            'sort' => 'listedAt',
        ];

        $location = trim((string) $searchProfile->location);

        if ($location !== '') {
            $searchIntent['where'] = [$location];
        }

        $filter = [];

        if ($listedAt = match ($searchProfile->post_date_range) {
            PostDateRange::Past24Hours => '1d',
            PostDateRange::PastWeek => '7d',
            PostDateRange::PastMonth => '30d',
            PostDateRange::AnyTime => null,
        }) {
            $filter['listedAt'] = $listedAt;
        }

        // Any, and an internship JobStreet can't express, search every work type.
        if ($workTypeId = self::WORK_TYPE_IDS[$searchProfile->job_type->value] ?? null) {
            $filter['workTypeId'] = [$workTypeId];
        }

        if ($workArrangementId = self::WORK_ARRANGEMENT_IDS[$searchProfile->work_arrangement->value] ?? null) {
            $filter['workArrangementId'] = [$workArrangementId];
        }

        if ($filter !== []) {
            $searchIntent['filter'] = $filter;
        }

        return $searchIntent;
    }

    /**
     * @param  array<string, mixed>  $searchIntent
     * @return array{jobs: list<array<string, mixed>>, pagination: array{page: int, resultCount: int}}
     */
    private function searchPage(string $sessionId, array $searchIntent, int $page): array
    {
        $body = $this->send([
            'sessionId' => $sessionId,
            'searchContext' => ['brand' => 'jobstreet', 'channel' => 'web', 'intent' => 'SEARCH', 'source' => 'FE_SERP'],
            'searchIntent' => $searchIntent,
            'responseConfig' => [
                'results' => ['jobs'],
                'representations' => ['uiV1'],
                'page' => $page,
                'pageSize' => self::PAGE_SIZE,
            ],
        ]);

        $results = $this->data($body)['jobSearchV7']['results'] ?? null;

        if (! is_array($results['jobs'] ?? null) || ! is_int($results['pagination']['resultCount'] ?? null)) {
            throw new ShapeDriftException(Platform::JobStreet, 'jobSearchV7.results.jobs or pagination is missing.', 200);
        }

        return [
            'jobs' => $results['jobs'],
            'pagination' => ['page' => (int) ($results['pagination']['page'] ?? 0), 'resultCount' => $results['pagination']['resultCount']],
        ];
    }

    /**
     * Send one jobSearchV7 query and return the decoded body, classifying transport and HTTP failures.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function send(array $params): array
    {
        $this->pace();

        try {
            $response = $this->retryingTransportFailures(Http::withHeaders(self::HEADERS))
                ->asJson()
                ->timeout(30)
                ->post(self::ENDPOINT, [
                    'operationName' => 'JobSearchV7',
                    'variables' => ['params' => $params],
                    'query' => self::SEARCH_JOBS_QUERY,
                ]);
        } catch (ConnectionException $e) {
            throw new TransportException(Platform::JobStreet, $e->getMessage(), previous: $e);
        }

        $this->guardHttpFailure($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new ShapeDriftException(Platform::JobStreet, 'Response body is not JSON: '.$this->snippet($response->body()), $response->status());
        }

        return $body;
    }

    /**
     * The data object of a GraphQL body, or the API error it reports instead.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        if (! empty($body['errors'])) {
            $error = $body['errors'][0];
            $code = $error['extensions']['code'] ?? 'UNKNOWN';

            throw new ApiErrorException(Platform::JobStreet, "GraphQL error {$code}: ".($error['message'] ?? ''), 200);
        }

        if (! is_array($body['data'] ?? null)) {
            throw new ShapeDriftException(Platform::JobStreet, 'Response has no data object.', 200);
        }

        return $body['data'];
    }

    private function guardHttpFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $message = "HTTP {$status}: ".$this->snippet($response->body());

        if (($status === 403 && strtolower($response->header('Cf-Mitigated')) === 'challenge') || $status === 429) {
            throw new AntiBotBlockedException(Platform::JobStreet, $message, $status);
        }

        if ($response->serverError()) {
            throw new TransportException(Platform::JobStreet, $message, $status);
        }

        throw new ApiErrorException(Platform::JobStreet, $message, $status);
    }

    /**
     * Space requests out so bursts don't raise Cloudflare's bot score.
     */
    private function pace(): void
    {
        if ($this->hasSentRequest) {
            Sleep::for(config('applyr.adapters.request_delay_ms'))->milliseconds();
        }

        $this->hasSentRequest = true;
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function toJobData(array $posting): JobData
    {
        $externalId = $this->required($posting, 'id');
        $salary = $posting['salary'] ?? null;

        return new JobData(
            externalId: $externalId,
            title: $this->required($posting, 'title'),
            companyName: $this->required($posting['advertiser'] ?? [], 'name', 'advertiser.name'),
            location: $posting['location']['displayName']['text'] ?? null,
            // Search results carry no country; it's the one the search asked for.
            countryCode: self::COUNTRY_CODE,
            url: ($posting['url'] ?? null) ?: self::ORIGIN."/job/{$externalId}",
            workArrangement: WorkArrangement::tryFrom($this->canonicalValue(self::WORK_ARRANGEMENT_IDS, $posting['workArrangements'][0]['id'] ?? null))
                ?? WorkArrangement::Unspecified,
            // Casual work (245) has no canonical job type.
            jobType: JobType::tryFrom($this->canonicalValue(self::WORK_TYPE_IDS, $posting['cjs']['workTypes'][0]['sourceId'] ?? null))
                ?? JobType::Unspecified,
            // JobStreet states no experience bounds.
            minYearsExperience: null,
            maxYearsExperience: null,
            // Search results expose no expiry, and a listing being searchable doesn't prove it's open.
            status: JobStatus::Unknown,
            salaryMin: $salary['min'] ?? null,
            salaryMax: $salary['max'] ?? null,
            salaryCurrency: $salary['currency'] ?? null,
            salaryPeriod: $salary === null ? null : match ($salary['period'] ?? null) {
                'monthly' => SalaryPeriod::Monthly,
                'annual' => SalaryPeriod::Yearly,
                default => SalaryPeriod::Unspecified,
            },
            postedDate: $this->postedDate($posting),
            rawPayload: $posting,
        );
    }

    /**
     * The canonical value a JobStreet id maps to, or '' for an id with none.
     *
     * @param  array<string, string>  $idsByValue
     */
    private function canonicalValue(array $idsByValue, mixed $id): string
    {
        return (string) array_search($id, $idsByValue, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function required(array $data, string $key, ?string $path = null): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ShapeDriftException(Platform::JobStreet, 'Posting field '.($path ?? $key).' is missing.', 200);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function postedDate(array $posting): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->required($posting['listedAt'] ?? [], 'dateTimeUtc', 'listedAt.dateTimeUtc'));
        } catch (ShapeDriftException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ShapeDriftException(Platform::JobStreet, 'Posting field listedAt.dateTimeUtc is not a date.', 200, $e);
        }
    }

    private function snippet(string $body): string
    {
        return mb_substr(trim(strip_tags($body)), 0, 200);
    }
}
