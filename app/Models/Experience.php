<?php

namespace App\Models;

use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'title',
    'company',
    'location',
    'start_date',
    'end_date',
    'is_current',
    'employment_type',
    'description',
    'achievements',
    'skills_used',
])]
class Experience extends MasterProfileEntry
{
    protected $attributes = [
        'achievements' => '[]',
        'skills_used' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'employment_type' => EmploymentType::class,
            'achievements' => 'array',
            'skills_used' => 'array',
        ];
    }
}
