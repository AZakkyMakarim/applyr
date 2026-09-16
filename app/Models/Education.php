<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('educations')]
#[Fillable([
    'institution',
    'degree',
    'field_of_study',
    'start_date',
    'end_date',
    'is_current',
    'description',
])]
class Education extends MasterProfileEntry
{
    protected function tailoringKind(): string
    {
        return 'education';
    }

    public function tailoringFacts(): array
    {
        return [
            'institution' => $this->institution,
            'degree' => $this->degree,
            'field_of_study' => $this->field_of_study,
            'start_date' => $this->start_date->format('Y-m'),
            'end_date' => $this->end_date?->format('Y-m'),
            'is_current' => $this->is_current,
        ];
    }

    /**
     * Education has no achievements to reframe.
     */
    public function reframeableText(): array
    {
        return [
            'description' => $this->description,
        ];
    }
}
