<?php

namespace App\Models;

use App\Enums\JobTypeFilter;
use App\Enums\PostDateRange;
use App\Enums\WorkArrangementFilter;
use Database\Factories\SearchProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved set of canonical search parameters. Holds no run or poll state.
 */
#[Fillable([
    'name',
    'is_active',
    'keyword',
    'location',
    'country_code',
    'min_experience_years',
    'max_experience_years',
    'post_date_range',
    'work_arrangement',
    'job_type',
])]
class SearchProfile extends Model
{
    /** @use HasFactory<SearchProfileFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Stop polling this SearchProfile while keeping it.
     */
    public function pause(): void
    {
        $this->update(['is_active' => false]);
    }

    public function resume(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'keyword' => 'array',
            'min_experience_years' => 'integer',
            'max_experience_years' => 'integer',
            'post_date_range' => PostDateRange::class,
            'work_arrangement' => WorkArrangementFilter::class,
            'job_type' => JobTypeFilter::class,
        ];
    }
}
