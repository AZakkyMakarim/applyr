<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Unlike the other entries, a Project's dates are optional.
 */
#[Fillable([
    'name',
    'start_date',
    'end_date',
    'is_current',
    'tech_stack',
    'link',
    'description',
    'achievements',
])]
class Project extends MasterProfileEntry
{
    protected $attributes = [
        'tech_stack' => '[]',
        'achievements' => '[]',
    ];

    protected function tailoringKind(): string
    {
        return 'project';
    }

    public function tailoringFacts(): array
    {
        return [
            'name' => $this->name,
            'start_date' => $this->start_date?->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'is_current' => $this->is_current,
            'tech_stack' => $this->tech_stack,
            'link' => $this->link,
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
            'tech_stack' => 'array',
            'achievements' => 'array',
        ];
    }
}
