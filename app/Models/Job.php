<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\Platform;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A job posting normalized from one Adapter. Unique by (platform, external_id).
 */
#[Fillable([
    'platform',
    'external_id',
    'title',
    'company_name',
    'location',
    'country_code',
    'url',
    'description',
    'work_arrangement',
    'job_type',
    'min_years_experience',
    'max_years_experience',
    'status',
    'salary_min',
    'salary_max',
    'salary_currency',
    'salary_period',
    'posted_date',
    'raw_payload',
])]
class Job extends Model
{
    /**
     * The SearchProfiles that returned this Job, with when each first did.
     *
     * @return BelongsToMany<SearchProfile, $this>
     */
    public function searchProfiles(): BelongsToMany
    {
        return $this->belongsToMany(SearchProfile::class, 'job_search_profile_matches')
            ->withPivot('matched_at');
    }

    /**
     * @return HasOne<Application, $this>
     */
    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'work_arrangement' => WorkArrangement::class,
            'job_type' => JobType::class,
            'status' => JobStatus::class,
            'salary_period' => SalaryPeriod::class,
            'min_years_experience' => 'integer',
            'max_years_experience' => 'integer',
            'posted_date' => 'datetime',
            'raw_payload' => 'array',
        ];
    }
}
