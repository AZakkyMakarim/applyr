<?php

namespace App\Http\Requests;

use App\Enums\EmploymentType;
use Illuminate\Validation\Rule;

class ExperienceRequest extends MasterProfileEntryRequest
{
    protected function section(): string
    {
        return 'experiences';
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'achievements' => ['present', 'array'],
            'achievements.*' => ['string', 'max:1000'],
            'skills_used' => ['present', 'array'],
            'skills_used.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Achievements are typed one per line, skills comma-separated.
     *
     * @return array<string, mixed>
     */
    protected function listFields(): array
    {
        return [
            'achievements' => $this->lines('achievements'),
            'skills_used' => $this->commaSeparated('skills_used'),
        ];
    }
}
