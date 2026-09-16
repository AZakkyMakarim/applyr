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

    protected function tailoringKind(): string
    {
        return 'experience';
    }

    public function tailoringFacts(): array
    {
        return [
            'title' => $this->title,
            'company' => $this->company,
            'location' => $this->location,
            'employment_type' => $this->employment_type?->value,
            'start_date' => $this->start_date->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'is_current' => $this->is_current,
            'skills_used' => $this->skills_used,
        ];
    }

    public function reframeableText(): array
    {
        return [
            'description' => $this->description,
            'achievements' => $this->achievements,
        ];
    }

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
