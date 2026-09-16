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
