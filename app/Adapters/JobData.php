<?php

namespace App\Adapters;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use Carbon\CarbonImmutable;

/**
 * Canonical Job data as an Adapter returned it: every Job field except the
 * description, plus the platform's raw JSON for it. Not persisted.
 */
final readonly class JobData
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $companyName,
        public ?string $location,
        public string $countryCode,
        public string $url,
        public WorkArrangement $workArrangement,
        public JobType $jobType,
        public ?int $minYearsExperience,
        public ?int $maxYearsExperience,
        public JobStatus $status,
        public int|float|null $salaryMin,
        public int|float|null $salaryMax,
        public ?string $salaryCurrency,
        public ?SalaryPeriod $salaryPeriod,
        public CarbonImmutable $postedDate,
        public array $rawPayload,
    ) {}

    /**
     * The Job columns a poll refreshes every time it sees this posting.
     *
     * @return array<string, mixed>
     */
    public function jobAttributes(): array
    {
        return [
            'title' => $this->title,
            'company_name' => $this->companyName,
            'location' => $this->location,
            'country_code' => $this->countryCode,
            'url' => $this->url,
            'work_arrangement' => $this->workArrangement,
            'job_type' => $this->jobType,
            'min_years_experience' => $this->minYearsExperience,
            'max_years_experience' => $this->maxYearsExperience,
            'status' => $this->status,
            'salary_min' => $this->salaryMin,
            'salary_max' => $this->salaryMax,
            'salary_currency' => $this->salaryCurrency,
            'salary_period' => $this->salaryPeriod,
            'posted_date' => $this->postedDate,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
